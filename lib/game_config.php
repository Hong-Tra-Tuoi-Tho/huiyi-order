<?php
/** Canonical campaign configuration. Only generated files inside uploads/minigame are accepted. */
function hgr_config_invalid(): never {
    throw new InvalidArgumentException(function_exists('hgr_text')?hgr_text('invalid'):'Invalid game configuration.');
}
function hgr_config_integer(mixed $value,int $minimum,int $maximum): int {
    if(!is_int($value)&&(!is_string($value)||preg_match('/^(0|[1-9][0-9]*)$/D',$value)!==1))hgr_config_invalid();
    if((float)$value<$minimum||(float)$value>$maximum)hgr_config_invalid();
    return (int)$value;
}
function hgr_config_path(mixed $value): string {
    if(!is_string($value)||($value!==''&&preg_match('~^uploads/minigame/[a-f0-9]{32}\.(jpg|png|webp)$~D',$value)!==1))hgr_config_invalid();
    return $value;
}
function hgr_config_name(mixed $value): string {
    if(!is_string($value))hgr_config_invalid();$value=trim($value);
    if(strlen($value)>640||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/u',$value)||preg_match_all('/./us',$value)>160)hgr_config_invalid();
    return $value;
}
function hgr_normalize_config(array $input,bool $allowEmpty=false): array {
    $theme=$input['theme']??'memory';if(!is_string($theme)||!in_array($theme,['memory','garden','night'],true))hgr_config_invalid();
    $faces=$input['faces']??array_fill(0,6,'');
    if(!is_array($faces)||!array_is_list($faces)||count($faces)!==6)hgr_config_invalid();
    $config=['theme'=>$theme,'back_image'=>hgr_config_path($input['back_image']??''),'faces'=>array_map('hgr_config_path',$faces),'prizes'=>[]];
    $prizes=$input['prizes']??null;if(!is_array($prizes)||!array_is_list($prizes)||count($prizes)>12||(!$allowEmpty&&count($prizes)<1))hgr_config_invalid();
    $ids=[];
    foreach($prizes as $prize){
        if(!is_array($prize))hgr_config_invalid();$id=$prize['id']??null;$kind=$prize['kind']??null;$enabled=$prize['enabled']??null;
        if(!is_string($id)||preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)!==1||isset($ids[$id])||!is_string($kind)||!in_array($kind,['topping','tea','discount'],true)||!is_bool($enabled))hgr_config_invalid();
        $ids[$id]=true;$size=$prize['cup_size']??'';if(!is_string($size)||!in_array($size,['','L','XL'],true))hgr_config_invalid();
        $name=$prize['name']??[];if(!is_array($name))hgr_config_invalid();$names=[];foreach(['vi','zh','en'] as $lang)$names[$lang]=hgr_config_name($name[$lang]??'');
        // A prize may list several gift items; item_id stays the first one for older readers.
        $list=$prize['item_ids']??[$prize['item_id']??0];if(!is_array($list)||count($list)>50)hgr_config_invalid();
        $items=[];foreach($list as $value){$value=hgr_config_integer($value,0,4294967295);if($value>0)$items[$value]=$value;}
        $items=$kind==='discount'?[]:array_values($items);$amount=hgr_config_integer($prize['amount']??0,0,100000000);
        // Each gift drink keeps its own cup size; older settings fall back to the prize-wide cup_size.
        $sizes=[];$map=$prize['item_sizes']??[];if(!is_array($map))hgr_config_invalid();
        if($kind==='tea')foreach($items as $item){$value=$map[$item]??$size;if(!is_string($value)||!in_array($value,['','L','XL'],true))hgr_config_invalid();$sizes[(string)$item]=$value;}
        $config['prizes'][]=['id'=>$id,'kind'=>$kind,'enabled'=>$enabled,'weight'=>hgr_config_integer($prize['weight']??0,0,10000),
            'item_id'=>$items[0]??0,'item_ids'=>$items,'gift_qty'=>$kind==='discount'?0:hgr_config_integer($prize['gift_qty']??1,1,20),'item_sizes'=>$sizes,'cup_size'=>$kind==='tea'?($sizes[(string)($items[0]??'')]??$size):'','amount'=>$kind==='discount'?$amount:0,
            'min_spend'=>hgr_config_integer($prize['min_spend']??0,0,100000000),'min_paid_cups'=>hgr_config_integer($prize['min_paid_cups']??1,1,100),
            'valid_days'=>hgr_config_integer($prize['valid_days']??7,1,365),'name'=>$names];
    }
    return $config;
}
/** Validate catalog references while saving; a disabled row may keep an unavailable item. */
function hgr_config_catalog_item(array $prize): ?array {
    if($prize['kind']==='discount')return null;
    if($prize['item_id']<1)hgr_config_invalid();
    if($prize['kind']==='topping'){
        $item=hym_reward_one('SELECT * FROM toppings WHERE id=? AND active=1 AND price>0',[$prize['item_id']]);
        if(!$item)hgr_config_invalid();return $item;
    }
    $item=hym_reward_one('SELECT p.* FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.active=1 AND c.active=1',[$prize['item_id']]);
    if(!$item)hgr_config_invalid();$prices=product_has_sizes($item)?product_size_options($item):[''=>(float)$item['price']];
    if(!isset($prices[$prize['cup_size']])||$prices[$prize['cup_size']]<=0)hgr_config_invalid();
    return $item;
}
/** Every gift item of a prize, validated against the current catalog. */
function hgr_config_catalog_items(array $prize): array {
    if($prize['kind']==='discount')return [];
    $ids=$prize['item_ids']??[$prize['item_id']];if(!$ids)hgr_config_invalid();
    return array_map(fn($id)=>hgr_config_catalog_item(array_merge($prize,['item_id'=>(int)$id,'cup_size'=>hgr_item_size($prize,(int)$id)])),$ids);
}
/** Cup size of one gift drink of a prize. */
function hgr_item_size(array $prize,int $id): string {
    return $prize['kind']==='tea'?(string)($prize['item_sizes'][$id]??$prize['item_sizes'][(string)$id]??$prize['cup_size']??''):'';
}
function hgr_validate_config(array $input): array {
    $config=hgr_normalize_config($input);$total=0;
    foreach($config['prizes'] as $prize){
        if(!$prize['enabled'])continue;
        // A money prize may be 0đ: winning it offers a replay that always wins a configured share prize (admin: Quà khi khách chia sẻ Facebook).
        if($prize['kind']!=='discount')hgr_config_catalog_items($prize);
        if($prize['weight']>0)$total+=$prize['weight'];
    }
    if($total!==10000)hgr_config_invalid();return $config;
}
function hgr_default_config(): array {
    $top=max(0,(int)setting('hgr_topping_id','0'));$tea=max(0,(int)setting('hgr_drink_id','0'));$size=setting('hgr_drink_size','L');
    if(!in_array($size,['','L','XL'],true))$size='L';
    if($tea>0){$item=hym_reward_one('SELECT * FROM products WHERE id=?',[$tea]);if($item&&!product_has_sizes($item))$size='';}
    $prizes=[];
    foreach([['topping',5000,$top,'',0,0,1],['discount',3500,0,'',5000,50000,1],['tea',1500,$tea,$size,0,0,4]] as [$kind,$weight,$item,$cup,$amount,$spend,$cups])
        $prizes[]=['id'=>$kind,'kind'=>$kind,'enabled'=>true,'weight'=>$weight,'item_id'=>$item,'item_ids'=>$item>0?[$item]:[],'item_sizes'=>$kind==='tea'&&$item>0?[(string)$item=>$cup]:[],'gift_qty'=>$kind==='discount'?0:1,'cup_size'=>$cup,'amount'=>$amount,'min_spend'=>$spend,'min_paid_cups'=>$cups,'valid_days'=>7,'name'=>['vi'=>'','zh'=>'','en'=>'']];
    return ['theme'=>'memory','back_image'=>'','faces'=>array_fill(0,6,''),'prizes'=>$prizes];
}
/** Invalid persisted settings close the campaign rather than silently changing its prizes. */
function hgr_config(): array {
    $raw=setting('hgr_game_config','');if($raw==='')return hgr_default_config();
    try{$input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($input))hgr_config_invalid();return hgr_normalize_config($input);}
    catch(JsonException|InvalidArgumentException $error){return ['theme'=>'memory','back_image'=>'','faces'=>array_fill(0,6,''),'prizes'=>[]];}
}
function hgr_config_revision(): string {
    return hash('sha256',json_encode([setting('hgr_game_config',''),setting('hgr_topping_id','0'),setting('hgr_drink_id','0'),setting('hgr_drink_size','L')],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
