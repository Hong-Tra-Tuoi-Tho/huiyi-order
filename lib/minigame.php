<?php
require_once __DIR__.'/minigame_matching.php';
require_once __DIR__.'/game_rewards.php';

class HymgException extends RuntimeException {
    public int $httpStatus;
    public function __construct(string $error,int $status){parent::__construct($error);$this->httpStatus=$status;}
}
function hymg_fail(string $error,int $status=409): never { throw new HymgException($error,$status); }

/** This migration runs only for Mini Game requests, before opening a transaction. */
function minigame_schema(): void {
    static $done=false;if($done)return;
    $pdo=db();if($pdo->inTransaction())throw new RuntimeException('minigame_schema_inside_transaction');
    hgr_schema();
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_matching_boards (
        wallet_token VARCHAR(64) NOT NULL,
        board_id CHAR(32) NOT NULL,
        state_json LONGTEXT NOT NULL,
        coupon_code VARCHAR(80) NULL,
        coupon_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        reward_json LONGTEXT NULL,
        awarded_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(wallet_token),
        UNIQUE KEY uq_matching_board_id(board_id),
        CONSTRAINT fk_matching_wallet FOREIGN KEY(wallet_token) REFERENCES game_wallets(wallet_token) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done=true;
}

function hymg_board_id(array $data): string {
    $id=$data['board_id']??'';
    if(!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1)hymg_fail('invalid');
    return $id;
}
function hymg_integer(array $data,string $key,int $maximum): int {
    $value=$data[$key]??null;
    if(!is_int($value)&&(!is_string($value)||preg_match('/^(0|[1-9][0-9]*)$/D',$value)!==1))hymg_fail('invalid');
    if((float)$value<0||(float)$value>$maximum)hymg_fail('invalid');
    return (int)$value;
}
function hymg_decode_board(array $row): array {
    $board=json_decode((string)$row['state_json'],true,32,JSON_THROW_ON_ERROR);
    if(!is_array($board)||($board['id']??null)!==$row['board_id']||!is_array($board['deck']??null)||count($board['deck'])!==12
        ||!is_array($board['open']??null)||!is_array($board['matched']??null)
        ||!isset($board['seq'],$board['moves'],$board['hide_at'])||!array_key_exists('started_at',$board)||!array_key_exists('completed_at',$board))
        throw new RuntimeException('minigame_board_invalid');
    return $board;
}
function hymg_saved_reward(array $row): ?array {
    if($row['awarded_at']===null)return null;
    $reward=json_decode((string)($row['reward_json']??''),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($reward))throw new RuntimeException('minigame_reward_invalid');
    return $reward;
}
function hymg_presentation(?array $config=null): array {
    $config??=hgr_config();
    return ['theme'=>$config['theme'],'back_image'=>$config['back_image'],'faces'=>$config['faces']];
}
function hymg_public_presentation(array $presentation): array {
    $path=static fn(string $value): string=>$value!==''?base_url($value):'';
    return ['theme'=>$presentation['theme'],'back_image'=>$path($presentation['back_image']),
        'faces'=>array_map($path,$presentation['faces'])];
}
function hymg_board_ready(?array $board): bool {
    return is_array($board['reward_pool']??null)&&count($board['reward_pool'])>0;
}
function hymg_payload(?array $row,?array $board,int $balance,string $event): array {
    $legacyAwarded=$row&&$row['awarded_at']!==null&&!isset($board['reward_pool']);
    $pool=$legacyAwarded?null:($board['reward_pool']??hgr_reward_pool());
    $presentation=$board['presentation']??($legacyAwarded?['theme'=>'memory','back_image'=>'','faces'=>array_fill(0,6,'')]:hymg_presentation());
    return ['ok'=>true,'board'=>$board?hymg_matching_view($board):null,'balance'=>max(0,$balance),
        'reward'=>$row?hymg_saved_reward($row):null,'event'=>$event,
        'appearance'=>hymg_public_presentation($presentation),'share_bonus'=>hymg_share_offer($row,$board),
        'prizes'=>$pool?hgr_public_pool($pool):[]];
}
/** A 0đ prize earns one replay that always wins 52.000đ for sharing the website, once per board. */
function hymg_share_offer(?array $row,?array $board): bool {
    if(!$row||$row['awarded_at']===null||!isset($row['reward_json'])||!is_array($board['reward_pool']??null))return false;
    $reward=hymg_saved_reward($row);
    return $reward!==null&&hgr_reward_is_zero($reward);
}
const HYMG_SHARE_AMOUNT = 52000;
/** The share prize: the board's own 52.000đ prize when it has one, otherwise a 52.000đ discount built from another discount prize. */
function hymg_share_prize(array $pool): array {
    $prize = null;
    $base = null;
    foreach ($pool as $item) {
        if (!is_array($item) || ($item['kind'] ?? '') !== 'discount') continue;
        if ((float)($item['amount'] ?? 0) === (float)HYMG_SHARE_AMOUNT) {
            $prize = $item;
            break;
        }
        $base ??= $item;
    }
    if (!$prize) {
        $prize = $base ?? [
            'id' => 'share-bonus',
            'kind' => 'discount',
            'valid_days' => 30,
            'item_id' => 0,
            'item_ids' => [],
            'gift_qty' => 0,
            'item_sizes' => [],
            'cup_size' => '',
            'min_spend' => 0,
            'min_paid_cups' => 0,
            'terms' => ['vi' => '', 'zh' => '', 'en' => ''],
        ];
        $prize['amount'] = HYMG_SHARE_AMOUNT;
        foreach (['vi', 'zh', 'en'] as $lang) {
            $money = hgr_money_text(HYMG_SHARE_AMOUNT, $lang);
            $prize['name'][$lang] = ['vi' => 'Giảm ' . $money, 'zh' => '折抵 ' . $money, 'en' => $money . ' off'][$lang];
        }
    }
    $prize['weight'] = 10000;
    // Marks the coupon as won from a 0đ prize, so the revenue report can tell it from a regular 52.000đ prize.
    $prize['share_bonus'] = true;
    return $prize;
}
function hymg_save_board(string $token,array $board): void {
    $statement=db()->prepare('UPDATE game_matching_boards SET state_json=? WHERE wallet_token=?');
    $statement->execute([json_encode($board,JSON_THROW_ON_ERROR),$token]);
}
function hymg_create_board(string $token,bool $replace=false,?array $pool=null): array {
    $config=hgr_config();$pool??=hgr_reward_pool($config);
    if(!$pool)hymg_fail('rewards_unavailable',403);
    $board=hymg_matching_new();$board['presentation']=hymg_presentation($config);$board['reward_pool']=$pool;
    $state=json_encode($board,JSON_THROW_ON_ERROR);
    $sql=$replace?'UPDATE game_matching_boards SET board_id=?,state_json=?,coupon_code=NULL,coupon_amount=0,reward_json=NULL,awarded_at=NULL,created_at=CURRENT_TIMESTAMP WHERE wallet_token=?':
        'INSERT INTO game_matching_boards(board_id,state_json,wallet_token) VALUES(?,?,?)';
    $statement=db()->prepare($sql);$statement->execute([$board['id'],$state,$token]);
    return $board;
}

/** Consume and issue inside the transaction that also marks the board awarded. */
function hymg_complete_locked(string $token,?array $pool=null): array {
    if(!db()->inTransaction())throw new RuntimeException('minigame_award_requires_transaction');
    $statement=db()->prepare('UPDATE game_wallets SET available_chances=available_chances-1,total_played=total_played+1 WHERE wallet_token=? AND available_chances>0');
    $statement->execute([$token]);if($statement->rowCount()!==1)hymg_fail('no_chances',403);
    $statement=db()->prepare('INSERT INTO game_plays(wallet_token) VALUES(?)');$statement->execute([$token]);
    return hgr_issue_reward_locked($token,$pool);
}

/** All operations lock wallet then board, so sessions/devices share one resumable game. */
function hymg_request(string $action,array $data=[]): array {
    if(!in_array($action,['state','start','flip','finish','next','share_bonus'],true))hymg_fail('invalid');
    minigame_schema();
    $token=customer_game_wallet_token(false);
    if($token===''||!hym_reward_wallet_accessible($token)){
        if($action==='state')return hymg_payload(null,null,0,'state');
        hymg_fail('no_chances',403);
    }
    $pdo=db();if($pdo->inTransaction())throw new RuntimeException('minigame_request_inside_transaction');
    $pdo->beginTransaction();
    try{
        if(!hym_reward_wallet_accessible($token,true)){
            if($action==='state'){$pdo->commit();return hymg_payload(null,null,0,'state');}
            hymg_fail('no_chances',403);
        }
        $statement=$pdo->prepare('SELECT available_chances FROM game_wallets WHERE wallet_token=? FOR UPDATE');$statement->execute([$token]);
        $balance=max(0,(int)$statement->fetchColumn());
        $statement=$pdo->prepare('SELECT * FROM game_matching_boards WHERE wallet_token=? FOR UPDATE');$statement->execute([$token]);
        $row=$statement->fetch()?:null;$board=$row?hymg_decode_board($row):null;$event=$action;
        // Upgrade a pre-settings game once, then keep its appearance and prize terms stable.
        if($board&&$row['awarded_at']===null&&!array_key_exists('reward_pool',$board)){
            $config=hgr_config();$pool=hgr_reward_pool($config);
            if($pool){$board['presentation']=hymg_presentation($config);$board['reward_pool']=$pool;hymg_save_board($token,$board);}
        }
        if($action==='state'){
            // An issued reward remains readable when the campaign is paused or the wallet is empty.
        }elseif($action==='finish'){
            $id=hymg_board_id($data);
            if(!$row)hymg_fail('board_missing');
            if(!hash_equals($row['board_id'],$id))hymg_fail('board_changed');
            if($row['awarded_at']!==null)$event='replayed';
            else{
                if(!minigame_enabled())hymg_fail('campaign_disabled',403);
                if(!hymg_board_ready($board))hymg_fail('rewards_unavailable',403);
                if($balance<1)hymg_fail('no_chances',403);
                if($board['completed_at']===null||count($board['matched'])!==12||count(array_unique($board['matched']))!==12)hymg_fail('matching_incomplete');
                $reward=hymg_complete_locked($token,$board['reward_pool']);$balance--;
                // A 0đ prize has no coupon; a marker ties its board to the checkout session so it is not cleared as an old board.
                $marker=(string)($reward['coupon_code']??'');if($marker==='')$marker='ZERO-'.strtoupper(bin2hex(random_bytes(6)));
                hcgs_record_play_locked($token,$marker);
                $statement=$pdo->prepare('UPDATE game_matching_boards SET coupon_code=?,coupon_amount=?,reward_json=?,awarded_at=UTC_TIMESTAMP() WHERE wallet_token=? AND awarded_at IS NULL');
                $statement->execute([$marker,(float)($reward['coupon_amount']??0),json_encode($reward,JSON_THROW_ON_ERROR),$token]);
                if($statement->rowCount()!==1)throw new RuntimeException('minigame_award_marker_failed');
                $row['coupon_code']=$reward['coupon_code']??'';$row['coupon_amount']=(float)($reward['coupon_amount']??0);
                $row['reward_json']=json_encode($reward,JSON_THROW_ON_ERROR);$row['awarded_at']='awarded';$event='awarded';
            }
        }elseif($action==='share_bonus'){
            // Sharing after a 0đ prize grants one more chance on a board whose only prize is 52.000đ (100%);
            // the win joins the open checkout session, so it is taken off the current order like any money prize.
            $id=hymg_board_id($data);
            if(!$row)hymg_fail('board_missing');
            if(!hash_equals($row['board_id'],$id))hymg_fail('board_changed');
            if(!minigame_enabled())hymg_fail('campaign_disabled',403);
            if(!hymg_share_offer($row,$board))hymg_fail('share_unavailable');
            $pool = [hymg_share_prize($board['reward_pool'])];
            $pdo->prepare('UPDATE game_wallets SET available_chances=available_chances+1,total_earned=total_earned+1 WHERE wallet_token=?')->execute([$token]);
            $balance++;
            hcgs_add_chance_locked($token);
            $board = hymg_create_board($token, true, $pool);
            $row = ['board_id' => $board['id'], 'awarded_at' => null];
        }else{
            if(!minigame_enabled())hymg_fail('campaign_disabled',403);
            if(($action==='next'||!$board)?!hgr_ready():!hymg_board_ready($board))hymg_fail('rewards_unavailable',403);
            if($balance<1)hymg_fail('no_chances',403);
            if($action==='start'){
                if(!$row){$board=hymg_create_board($token);$row=['board_id'=>$board['id'],'awarded_at'=>null];$event='started';}
                else $event=$row['awarded_at']!==null?'replayed':'resumed';
            }elseif($action==='next'){
                $id=hymg_board_id($data);
                if(!$row)hymg_fail('board_missing');
                if(!hash_equals($row['board_id'],$id))hymg_fail('board_changed');
                if($row['awarded_at']===null)hymg_fail('board_not_awarded');
                $board=hymg_create_board($token,true);$row=['board_id'=>$board['id'],'awarded_at'=>null];$event='started';
            }else{
                $id=hymg_board_id($data);$sequence=hymg_integer($data,'seq',1000000);$position=hymg_integer($data,'position',11);
                if(!$row)hymg_fail('board_missing');
                if(!hash_equals($row['board_id'],$id))hymg_fail('board_changed');
                if($row['awarded_at']!==null)$event='replayed';
                else{$event=hymg_matching_flip($board,$id,$sequence,$position);hymg_save_board($token,$board);}
            }
        }
        $result=hymg_payload($row,$board,$balance,$event);$pdo->commit();return $result;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
