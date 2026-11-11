<?php
/** Store dispatch is a staff decision. Google recommendations never select a store. */
class HdsDispatchError extends RuntimeException {}
function hds_fail(string $key): never { throw new HdsDispatchError($key); }
function hds_one(string $sql,array $params=[]): ?array { $st=db()->prepare($sql);$st->execute($params);return $st->fetch()?:null; }
function hds_exec(string $sql,array $params=[]): void { $st=db()->prepare($sql);$st->execute($params); }
function hds_schema(): void {
    static $done=false;if($done)return;$pdo=db();
    if($pdo->inTransaction())hds_fail('dispatch_inside_transaction');
    $marker=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$marker->execute(['dispatch_schema_version']);
    if((string)$marker->fetchColumn()!=='19.17.0'){
        $lock='hds-schema:'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $st=$pdo->prepare('SELECT GET_LOCK(?,10)');$st->execute([$lock]);if((int)$st->fetchColumn()!==1)hds_fail('dispatch_schema_busy');
        try{
            $marker->execute(['dispatch_schema_version']);
            if((string)$marker->fetchColumn()!=='19.17.0'){
                $sql=file_get_contents(__DIR__.'/dispatch_schema.sql');if($sql===false)hds_fail('dispatch_schema_missing');
                foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
                foreach(['coord_source'=>"VARCHAR(16) NOT NULL DEFAULT 'manual'",'geocoded_at'=>'DATETIME NULL','expires_at'=>'DATETIME NULL'] as $column=>$definition){
                    $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$check->execute(['dispatch_stores',$column]);
                    if(!(int)$check->fetchColumn())$pdo->exec("ALTER TABLE dispatch_stores ADD COLUMN `$column` $definition");
                }
                foreach([
                    'recommended_store_id'=>'BIGINT UNSIGNED NULL','delivery_lat'=>'DECIMAL(10,7) NULL','delivery_lng'=>'DECIMAL(10,7) NULL',
                    'delivery_geo_status'=>'VARCHAR(24) NULL','delivery_distance_km'=>'DECIMAL(12,5) NULL',
                    'delivery_geo_attempted_at'=>'DATETIME NULL','delivery_geo_at'=>'DATETIME NULL','delivery_geo_expires_at'=>'DATETIME NULL',
                    'delivery_geo_request'=>'VARCHAR(32) NULL','delivery_geo_address_hash'=>'VARCHAR(64) NULL',
                    'dispatch_store_id'=>'BIGINT UNSIGNED NULL','dispatch_store_name'=>'VARCHAR(160) NULL','dispatch_store_address'=>'VARCHAR(500) NULL',
                    'dispatch_store_map_url'=>'VARCHAR(2048) NULL','dispatch_assigned_at'=>'DATETIME NULL','dispatch_assigned_by_admin_id'=>'BIGINT UNSIGNED NULL',
                    'dispatch_revision'=>'INT UNSIGNED NULL',
                ] as $column=>$definition){
                    $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$check->execute(['orders',$column]);
                    if(!(int)$check->fetchColumn())$pdo->exec("ALTER TABLE orders ADD COLUMN `$column` $definition");
                }
                $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='idx_dispatch_geo_expiry'")->fetchColumn();
                if(!(int)$index)$pdo->exec('ALTER TABLE orders ADD KEY idx_dispatch_geo_expiry(delivery_geo_expires_at)');
                // Store partners list and poll their own store's orders by status.
                $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='idx_orders_store_status'")->fetchColumn();
                if(!(int)$index)$pdo->exec('ALTER TABLE orders ADD KEY idx_orders_store_status(dispatch_store_id,status,id)');
                // A partner handing an order back is recorded with admin_id NULL, its partners.id and a reason.
                if($pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dispatch_history' AND COLUMN_NAME='admin_id'")->fetchColumn()==='NO')$pdo->exec('ALTER TABLE dispatch_history MODIFY COLUMN admin_id BIGINT UNSIGNED NULL');
                foreach(['partner_id'=>'BIGINT UNSIGNED NULL AFTER admin_id','reason'=>'VARCHAR(1000) NULL AFTER partner_id'] as $column=>$definition){
                    $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$check->execute(['dispatch_history',$column]);
                    if(!(int)$check->fetchColumn())$pdo->exec("ALTER TABLE dispatch_history ADD COLUMN `$column` $definition");
                }
                $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dispatch_history' AND INDEX_NAME='idx_dispatch_history_partner'")->fetchColumn();
                if(!(int)$index)$pdo->exec('ALTER TABLE dispatch_history ADD KEY idx_dispatch_history_partner(partner_id)');
                hds_exec('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',['dispatch_schema_version','19.17.0']);
            }
        }finally{$st=$pdo->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lock]);}
    }$done=true;
}
function hds_staff(?int $adminId=null): int {
    $id=(int)($_SESSION['admin_id']??0);
    if($id<1||($adminId!==null&&$id!==$adminId)||!hds_one('SELECT id FROM admins WHERE id=?',[$id]))hds_fail('dispatch_forbidden');return $id;
}
function hds_text(mixed $value,int $max,string $error='dispatch_invalid_store'): string {
    if(!is_string($value)||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))hds_fail($error);
    $value=trim($value);$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);
    if($length>$max)hds_fail($error);return $value;
}
function hds_coordinates(mixed $lat,mixed $lng): ?array {
    $empty=fn($v)=>$v===null||$v==='';if($empty($lat)&&$empty($lng))return null;
    $values=[];
    foreach([$lat,$lng] as $index=>$value){
        if((!is_int($value)&&!is_float($value)&&!is_string($value))||(is_string($value)&&!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D',$value)))hds_fail('dispatch_invalid_coordinates');
        $number=(float)$value;$limit=$index===0?90:180;if(!is_finite($number)||abs($number)>$limit)hds_fail('dispatch_invalid_coordinates');$values[]=$number;
    }return ['lat'=>$values[0],'lng'=>$values[1]];
}
function hds_map_url(mixed $value): string {
    $url=hds_text($value,2048,'dispatch_invalid_map_url');if($url==='')return '';
    if(preg_match('/[\s\\\\]/',$url)||!filter_var($url,FILTER_VALIDATE_URL))hds_fail('dispatch_invalid_map_url');
    $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));$path=$parts['path']??'/';
    if(($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['port']))hds_fail('dispatch_invalid_map_url');
    $allowed=['google.com','www.google.com','maps.google.com','google.com.vn','www.google.com.vn','maps.google.com.vn','maps.app.goo.gl','goo.gl'];
    if(!in_array($host,$allowed,true))hds_fail('dispatch_invalid_map_url');
    if(in_array($host,['google.com','www.google.com','google.com.vn','www.google.com.vn','goo.gl'],true)&&!preg_match('~^/maps(?:/|$)~',$path))hds_fail('dispatch_invalid_map_url');
    if($host==='maps.app.goo.gl'&&$path==='/')hds_fail('dispatch_invalid_map_url');return $url;
}
function hds_map_coordinates(string $url): ?array {
    $url=hds_map_url($url);if($url==='')return null;$decoded=rawurldecode($url);
    // A Maps @ position is usually a camera center, not the destination pin.
    preg_match_all('/!3d([+-]?\d+(?:\.\d+)?)!4d([+-]?\d+(?:\.\d+)?)/',$decoded,$pins,PREG_SET_ORDER);
    if(count($pins)===1)return hds_coordinates($pins[0][1],$pins[0][2]);if($pins)return null;
    parse_str((string)(parse_url($url,PHP_URL_QUERY)??''),$query);
    foreach(['query','q'] as $key){$value=$query[$key]??null;if(is_string($value)&&preg_match('/^(?:loc:)?\s*([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\s*$/D',$value,$match))return hds_coordinates($match[1],$match[2]);}
    return null;
}
function hds_distance_km(float $lat1,float $lng1,float $lat2,float $lng2): float {
    hds_coordinates($lat1,$lng1);hds_coordinates($lat2,$lng2);
    $a=sin(deg2rad($lat2-$lat1)/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
    return 6371.0088*2*asin(sqrt(max(0.0,min(1.0,$a))));
}
function hds_store_dto(array $row): array {
    $expired=($row['coord_source']??'manual')==='google'&&(!isset($row['expires_at'])||strtotime($row['expires_at'].' UTC')<=time());
    $dto=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'address'=>(string)$row['address'],'phone'=>(string)$row['phone'],
        'map_url'=>(string)$row['map_url'],'lat'=>$expired||$row['lat']===null?null:(float)$row['lat'],'lng'=>$expired||$row['lng']===null?null:(float)$row['lng'],
        'enabled'=>(bool)$row['enabled'],'revision'=>(int)$row['revision'],'coord_source'=>$row['coord_source']??'manual',
        'geocoded_at'=>$row['geocoded_at']??null,'expires_at'=>$row['expires_at']??null];
    return function_exists('htp_store_fields')?array_merge($dto,htp_store_fields((int)$row['id'])):$dto;
}
function hds_purge_store_expired(): void {
    // Do not acquire unrelated store write locks while an order/store mutation is active.
    // DTO filtering below still hides expired coordinates inside that transaction.
    if(db()->inTransaction())return;
    hds_exec("UPDATE dispatch_stores SET lat=NULL,lng=NULL WHERE coord_source='google' AND (expires_at IS NULL OR expires_at<=UTC_TIMESTAMP()) AND (lat IS NOT NULL OR lng IS NOT NULL)");
}
function hds_stores(bool $activeOnly=false): array {
    hds_purge_store_expired();
    return array_map('hds_store_dto',db()->query('SELECT * FROM dispatch_stores'.($activeOnly?' WHERE enabled=1':'').' ORDER BY id')->fetchAll());
}
function hds_store_get(int $id): ?array { hds_purge_store_expired();$row=hds_one('SELECT * FROM dispatch_stores WHERE id=?',[$id]);return $row?hds_store_dto($row):null; }
function hds_store_coordinate_provenance(array $input,?array $coords,string $address,?array $old): array {
    $manual=['coord_source'=>'manual','geocoded_at'=>null,'expires_at'=>null];if($coords===null)return $manual;
    $equal=fn($a,$b)=>$a!==null&&$b!==null&&abs((float)$a-(float)$b)<0.00000011;
    $same=$old!==null&&$equal($old['lat'],$coords['lat'])&&$equal($old['lng'],$coords['lng']);
    $preview=$_SESSION['hds_store_geo_preview']??null;$token=$input['preview_token']??'';
    $valid=is_array($preview)&&is_string($token)&&$token!==''&&hash_equals((string)$preview['token'],$token)&&
        (int)$preview['admin_id']===(int)($_SESSION['admin_id']??0)&&(int)$preview['issued_at']>time()-300&&
        hash_equals((string)$preview['address_hash'],hash('sha256',$address))&&$equal($preview['lat'],$coords['lat'])&&$equal($preview['lng'],$coords['lng']);
    if($valid)return ['coord_source'=>'google','geocoded_at'=>$preview['geocoded_at'],'expires_at'=>$preview['expires_at']];
    if($same&&($old['coord_source']??'manual')==='google'){
        if($address!==$old['address'])hds_fail('dispatch_location_refresh_required');
        return ['coord_source'=>'google','geocoded_at'=>$old['geocoded_at'],'expires_at'=>$old['expires_at']];
    }
    $source=$input['coord_source']??'manual';if(!is_string($source)||!in_array($source,['manual','maps_pin','google'],true))hds_fail('dispatch_invalid_coordinates');
    if($source==='google')hds_fail('dispatch_location_refresh_required');
    // A still-bound preview cannot be relabelled manual just by modifying a hidden field.
    if(is_array($preview)&&hash_equals((string)$preview['address_hash'],hash('sha256',$address))&&$equal($preview['lat'],$coords['lat'])&&$equal($preview['lng'],$coords['lng']))hds_fail('dispatch_location_refresh_required');
    return $manual;
}
function hds_store_save(array $input,?int $adminId=null): array {
    $adminId=hds_staff($adminId);if(db()->inTransaction())hds_fail('dispatch_inside_transaction');
    $rawId=$input['id']??0;$id=is_scalar($rawId)&&!is_bool($rawId)?filter_var($rawId,FILTER_VALIDATE_INT):false;if($id===false||$id<0)hds_fail('dispatch_invalid_store');
    $name=hds_text($input['name']??'',160);$address=hds_text($input['address']??'',500);if($name===''||$address==='')hds_fail('dispatch_invalid_store');
    $phone=hds_text($input['phone']??'',80);$url=hds_map_url($input['map_url']??'');
    $coords=hds_coordinates($input['lat']??null,$input['lng']??null);if($coords===null)$coords=hds_map_coordinates($url);
    $enabled=$input['enabled']??false;if(!in_array($enabled,[true,false,0,1,'0','1'],true))hds_fail('dispatch_invalid_store');$enabled=(int)(bool)$enabled;
    $revision=$input['expected_revision']??$input['revision']??null;
    if($id>0&&(!is_scalar($revision)||is_bool($revision)||filter_var($revision,FILTER_VALIDATE_INT)===false||(int)$revision<1))hds_fail('dispatch_changed');
    $pdo=db();$pdo->beginTransaction();
    try{
        if($id>0){$row=hds_one('SELECT * FROM dispatch_stores WHERE id=? FOR UPDATE',[$id]);if(!$row)hds_fail('dispatch_invalid_store');if((int)$row['revision']!==(int)$revision)hds_fail('dispatch_changed');
            $source=hds_store_coordinate_provenance($input,$coords,$address,$row);
            hds_exec('UPDATE dispatch_stores SET name=?,address=?,phone=?,map_url=?,lat=?,lng=?,coord_source=?,geocoded_at=?,expires_at=?,enabled=?,revision=revision+1,updated_at=UTC_TIMESTAMP(),updated_by_admin_id=? WHERE id=?',[$name,$address,$phone,$url,$coords['lat']??null,$coords['lng']??null,$source['coord_source'],$source['geocoded_at'],$source['expires_at'],$enabled,$adminId,$id]);
        }else{$source=hds_store_coordinate_provenance($input,$coords,$address,null);hds_exec('INSERT INTO dispatch_stores(name,address,phone,map_url,lat,lng,coord_source,geocoded_at,expires_at,enabled,created_at,updated_at,updated_by_admin_id) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?)',[$name,$address,$phone,$url,$coords['lat']??null,$coords['lng']??null,$source['coord_source'],$source['geocoded_at'],$source['expires_at'],$enabled,$adminId]);$id=(int)$pdo->lastInsertId();}
        if(function_exists('htp_store_save'))htp_store_save((int)$id,$input,$adminId);
        $result=hds_store_get((int)$id);$pdo->commit();return $result;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function hds_config_path(): string { return defined('HDS_CONFIG_FILE')?(string)HDS_CONFIG_FILE:dirname(__DIR__).'/config.dispatch.local.php'; }
function hds_validate_config(array $input): array {
    $enabled=$input['enabled']??false;if(!in_array($enabled,[true,false,0,1,'0','1'],true))hds_fail('dispatch_invalid_config');
    $key=$input['api_key']??'';if(!is_string($key)||strlen($key)>256||($key!==''&&!preg_match('/^[A-Za-z0-9_-]+$/D',$key)))hds_fail('dispatch_invalid_config');
    return ['enabled'=>(bool)$enabled,'api_key'=>$key,'country'=>'VN'];
}
function hds_private_config(): array {
    if(isset($GLOBALS['hds_config_cache']))return $GLOBALS['hds_config_cache'];$path=hds_config_path();$input=[];
    if(is_file($path)){try{$input=(static function($file){return require $file;})($path);}catch(Throwable $error){hds_fail('dispatch_invalid_config');}if(!is_array($input))hds_fail('dispatch_invalid_config');}
    return $GLOBALS['hds_config_cache']=hds_validate_config($input);
}
function hds_config_status(): array { $config=hds_private_config();return ['enabled'=>$config['enabled'],'key_configured'=>$config['api_key']!=='','country'=>'VN']; }
function hds_config_save(array $input): void {
    hds_staff();$path=hds_config_path();$lockPath=dirname($path).'/.dispatch-config-lock.php';$lock=@fopen($lockPath,'cb');
    if($lock===false)hds_fail('dispatch_config_save_failed');$temp=null;$handle=null;
    try{
        @chmod($lockPath,0600);if(!flock($lock,LOCK_EX))hds_fail('dispatch_config_save_failed');unset($GLOBALS['hds_config_cache']);$old=hds_private_config();
        $next=array_replace($old,$input);if(!array_key_exists('api_key',$input)||$input['api_key']==='')$next['api_key']=$old['api_key'];if(!empty($input['clear_key']))$next['api_key']='';
        $config=hds_validate_config($next);$temp=dirname($path).'/.dispatch-config-'.bin2hex(random_bytes(16)).'.php';$handle=@fopen($temp,'xb');if($handle===false)hds_fail('dispatch_config_save_failed');
        @chmod($temp,0600);$content="<?php\n// Server-only Google settings. Exclude from Git and deployment archives.\nreturn ".var_export($config,true).";\n";$written=0;
        while($written<strlen($content)){$bytes=fwrite($handle,substr($content,$written));if($bytes===false||$bytes===0)hds_fail('dispatch_config_save_failed');$written+=$bytes;}
        if(!fflush($handle))hds_fail('dispatch_config_save_failed');fclose($handle);$handle=null;if(!@rename($temp,$path))hds_fail('dispatch_config_save_failed');@chmod($path,0600);
        if(function_exists('opcache_invalidate'))@opcache_invalidate($path,true);unset($GLOBALS['hds_config_cache']);
    }finally{if(is_resource($handle))fclose($handle);if($temp!==null&&is_file($temp))@unlink($temp);flock($lock,LOCK_UN);fclose($lock);}
}
function hds_parse_geocode(array $response): array {
    $failure=fn($status)=>['status'=>$status,'lat'=>null,'lng'=>null];
    if(($response['status']??null)==='ZERO_RESULTS')return $failure('no_match');if(($response['status']??null)!=='OK')return $failure('geocode_failed');
    $results=$response['results']??null;if(!is_array($results)||!array_is_list($results)||!$results)return $failure('geocode_failed');if(count($results)!==1)return $failure('ambiguous');
    $row=$results[0];if(!is_array($row))return $failure('geocode_failed');if(!empty($row['partial_match']))return $failure('ambiguous');
    $geometry=$row['geometry']??[];if(!is_array($geometry)||!in_array($geometry['location_type']??null,['ROOFTOP','RANGE_INTERPOLATED'],true))return $failure('imprecise');
    $country='';$components=$row['address_components']??[];if(!is_array($components))return $failure('imprecise');
    foreach($components as $component)if(is_array($component)&&is_array($component['types']??null)&&in_array('country',$component['types'],true))$country=$component['short_name']??'';
    if($country!=='VN')return $failure('imprecise');$location=$geometry['location']??[];if(!is_array($location))return $failure('imprecise');
    try{$coords=hds_coordinates($location['lat']??null,$location['lng']??null);}catch(Throwable $error){return $failure('imprecise');}
    return $coords?['status'=>'ready']+$coords:$failure('imprecise');
}
/** Fixed server-only endpoint, strict timeout, no redirects, no raw response persistence. */
function hds_geocode(string $address,array $config): array {
    if(db()->inTransaction())hds_fail('dispatch_inside_transaction');
    if(isset($GLOBALS['hds_geocode_transport'])&&is_callable($GLOBALS['hds_geocode_transport'])){
        try{$result=($GLOBALS['hds_geocode_transport'])($address,$config);return is_array($result)?hds_parse_geocode($result):['status'=>'geocode_failed','lat'=>null,'lng'=>null];}
        catch(Throwable $error){return ['status'=>'geocode_failed','lat'=>null,'lng'=>null];}
    }
    if(!function_exists('curl_init'))return ['status'=>'geocode_failed','lat'=>null,'lng'=>null];
    $url='https://maps.googleapis.com/maps/api/geocode/json?'.http_build_query(['address'=>$address,'components'=>'country:VN','language'=>'vi','region'=>'vn','key'=>$config['api_key']],'','&',PHP_QUERY_RFC3986);
    $body='';$curl=curl_init($url);if($curl===false)return ['status'=>'geocode_failed','lat'=>null,'lng'=>null];
    try{
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>1048576)return 0;$body.=$chunk;return strlen($chunk);}]);
        if(curl_exec($curl)===false||(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)return ['status'=>'geocode_failed','lat'=>null,'lng'=>null];
        $result=json_decode($body,true,32,JSON_THROW_ON_ERROR);return is_array($result)?hds_parse_geocode($result):['status'=>'geocode_failed','lat'=>null,'lng'=>null];
    }catch(Throwable $error){return ['status'=>'geocode_failed','lat'=>null,'lng'=>null];}finally{curl_close($curl);}
}
/** Preview only: the store is saved separately after staff check the position. */
function hds_locate_store_address(string $address,int $adminId): array {
    hds_staff($adminId);if(db()->inTransaction())hds_fail('dispatch_inside_transaction');$address=hds_text($address,500);if($address==='')hds_fail('dispatch_invalid_store');$config=hds_private_config();
    if(!$config['enabled'])$result=['status'=>'disabled','lat'=>null,'lng'=>null];elseif($config['api_key']==='')$result=['status'=>'no_key','lat'=>null,'lng'=>null];else $result=hds_geocode($address,$config);
    $result['message_key']='dispatch_'.$result['status'];if($result['status']==='ready'){
        $result['coord_source']='google';$result['geocoded_at']=gmdate('Y-m-d H:i:s');$result['expires_at']=gmdate('Y-m-d H:i:s',time()+30*86400);$result['preview_token']=bin2hex(random_bytes(16));
        $_SESSION['hds_store_geo_preview']=['token'=>$result['preview_token'],'admin_id'=>$adminId,'issued_at'=>time(),'address_hash'=>hash('sha256',$address),'lat'=>$result['lat'],'lng'=>$result['lng'],'geocoded_at'=>$result['geocoded_at'],'expires_at'=>$result['expires_at']];
    }return $result;
}
function hds_nearest_store(float $lat,float $lng,array $stores): ?array {
    $best=null;foreach($stores as $store){if(empty($store['enabled'])||$store['lat']===null||$store['lng']===null)continue;
        $distance=hds_distance_km($lat,$lng,(float)$store['lat'],(float)$store['lng']);
        if($best===null||$distance<$best['distance_km']-0.000000001||(abs($distance-$best['distance_km'])<=0.000000001&&(int)$store['id']<(int)$best['store']['id']))$best=['store'=>$store,'distance_km'=>$distance];
    }return $best;
}
function hds_purge_expired(int $id): void {
    hds_exec("UPDATE orders SET recommended_store_id=NULL,delivery_lat=NULL,delivery_lng=NULL,delivery_distance_km=NULL,delivery_geo_at=NULL,delivery_geo_expires_at=NULL,delivery_geo_status='expired' WHERE id=? AND ((delivery_geo_expires_at IS NOT NULL AND delivery_geo_expires_at<=UTC_TIMESTAMP()) OR (delivery_geo_expires_at IS NULL AND (delivery_lat IS NOT NULL OR delivery_lng IS NOT NULL)))",[$id]);
}
/** Daily retention maintenance, independent of which orders staff happen to open. */
function hds_maintenance(): void {
    $pdo=db();if($pdo->inTransaction())hds_fail('dispatch_inside_transaction');$today=gmdate('Y-m-d');
    $key='dispatch_maintenance_date';$st=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$st->execute([$key]);if((string)$st->fetchColumn()===$today)return;
    $name='hds-retention:'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,40);$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);if((int)$lock->fetchColumn()!==1)return;
    try{
        $st->execute([$key]);if((string)$st->fetchColumn()===$today)return;
        hds_exec("UPDATE orders SET recommended_store_id=NULL,delivery_lat=NULL,delivery_lng=NULL,delivery_distance_km=NULL,delivery_geo_at=NULL,delivery_geo_expires_at=NULL,delivery_geo_status='expired' WHERE delivery_geo_expires_at IS NOT NULL AND delivery_geo_expires_at<=UTC_TIMESTAMP()");
        hds_purge_store_expired();hds_exec('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$today]);
    }finally{$lock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$lock->execute([$name]);}
}
function hds_dispatch_dto(array $order,?array $allStores=null): array {
    $allStores=$allStores??hds_stores();$storeById=[];foreach($allStores as $store)$storeById[(int)$store['id']]=$store;
    $status=(string)($order['delivery_geo_status']??'pending');$recommended=null;
    if(($order['delivery_lat']!==null||$order['delivery_lng']!==null)&&(($order['delivery_geo_expires_at']??null)===null||strtotime($order['delivery_geo_expires_at'].' UTC')<=time()))$status='expired';
    if(($order['delivery_geo_address_hash']??null)!==null&&!hash_equals((string)$order['delivery_geo_address_hash'],hash('sha256',(string)$order['address'])))$status='pending';
    $distance=null;
    if($status==='ready'&&$order['delivery_lat']!==null&&$order['delivery_lng']!==null){$nearest=hds_nearest_store((float)$order['delivery_lat'],(float)$order['delivery_lng'],$allStores);
        if($nearest){$recommended=$nearest['store'];$distance=$nearest['distance_km'];}else $status='no_stores';}
    $assigned=null;if(!empty($order['dispatch_store_id'])){$current=$storeById[(int)$order['dispatch_store_id']]??null;$assigned=['id'=>(int)$order['dispatch_store_id'],'name'=>(string)$order['dispatch_store_name'],'address'=>(string)$order['dispatch_store_address'],'map_url'=>(string)$order['dispatch_store_map_url'],'enabled'=>$current?(bool)$current['enabled']:false];}
    $visible=$status==='ready';return ['order_id'=>(int)$order['id'],'revision'=>(int)($order['dispatch_revision']??0),'order_status'=>(string)$order['status'],
        'can_assign'=>in_array($order['status'],['new','accepted'],true),'status'=>$status,'message_key'=>'dispatch_'.$status,
        'lat'=>$visible&&$order['delivery_lat']!==null?(float)$order['delivery_lat']:null,'lng'=>$visible&&$order['delivery_lng']!==null?(float)$order['delivery_lng']:null,
        'distance_km'=>$visible?$distance:null,
        'recommended_store'=>$recommended,'assigned_store'=>$assigned,'last_attempt_at'=>$order['delivery_geo_attempted_at']??null,
        'geocoded_at'=>$order['delivery_geo_at']??null,'expires_at'=>$order['delivery_geo_expires_at']??null,'recommended_at'=>$order['delivery_geo_at']??null,
        'assigned_at'=>$order['dispatch_assigned_at']??null,'assigned_by_admin_id'=>isset($order['dispatch_assigned_by_admin_id'])?(int)$order['dispatch_assigned_by_admin_id']:null,
        'stale'=>!$visible&&$order['delivery_lat']!==null];
}
function hds_order_dispatch(int $id): ?array { hds_staff();hds_purge_expired($id);$row=hds_one('SELECT * FROM orders WHERE id=?',[$id]);return $row?hds_dispatch_dto($row):null; }
/** One staff check and one store read for the whole page, including legacy rows. */
function hds_order_dispatch_list(array $orders): array {
    hds_staff();$ids=[];foreach($orders as $order){$id=$order['id']??null;if((is_int($id)||(is_string($id)&&ctype_digit($id)))&&(int)$id>0)$ids[(int)$id]=(int)$id;}
    if(!$ids)return [];$ids=array_values($ids);$placeholders=implode(',',array_fill(0,count($ids),'?'));
    hds_exec("UPDATE orders SET recommended_store_id=NULL,delivery_lat=NULL,delivery_lng=NULL,delivery_distance_km=NULL,delivery_geo_at=NULL,delivery_geo_expires_at=NULL,delivery_geo_status='expired' WHERE id IN ($placeholders) AND ((delivery_geo_expires_at IS NOT NULL AND delivery_geo_expires_at<=UTC_TIMESTAMP()) OR (delivery_geo_expires_at IS NULL AND (delivery_lat IS NOT NULL OR delivery_lng IS NOT NULL)))",$ids);
    $st=db()->prepare("SELECT * FROM orders WHERE id IN ($placeholders)");$st->execute($ids);$stores=hds_stores();$result=[];
    foreach($st->fetchAll() as $order)$result[(int)$order['id']]=hds_dispatch_dto($order,$stores);return $result;
}
function hds_history(int $id): array {
    hds_staff();$st=db()->prepare('SELECT h.*,a.username AS admin_name FROM dispatch_history h LEFT JOIN admins a ON a.id=h.admin_id WHERE h.order_id=? ORDER BY h.revision DESC');$st->execute([$id]);
    return array_map(function($row){foreach(['id','order_id','revision','admin_id'] as $key)$row[$key]=(int)$row[$key];$row['store_id']=$row['store_id']===null?null:(int)$row['store_id'];return $row;},$st->fetchAll());
}
function hds_assign_order(int $id,?int $storeId,int $adminId,int $expectedRevision): array {
    hds_staff($adminId);if(db()->inTransaction())hds_fail('dispatch_inside_transaction');if($expectedRevision<0||($storeId!==null&&$storeId<1))hds_fail('dispatch_invalid_store');
    $pdo=db();$pdo->beginTransaction();try{
        $order=hds_one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$order)hds_fail('dispatch_order_not_found');
        if(!in_array($order['status'],['new','accepted'],true))hds_fail('dispatch_order_final');if((int)($order['dispatch_revision']??0)!==$expectedRevision)hds_fail('dispatch_changed');
        $store=$storeId===null?null:hds_one('SELECT * FROM dispatch_stores WHERE id=? FOR UPDATE',[$storeId]);if($storeId!==null&&(!$store||!(bool)$store['enabled']))hds_fail('dispatch_store_unavailable');
        $revision=$expectedRevision+1;
        hds_exec('UPDATE orders SET dispatch_store_id=?,dispatch_store_name=?,dispatch_store_address=?,dispatch_store_map_url=?,dispatch_assigned_at=UTC_TIMESTAMP(),dispatch_assigned_by_admin_id=?,dispatch_revision=? WHERE id=?',[$storeId,$store['name']??null,$store['address']??null,$store['map_url']??null,$adminId,$revision,$id]);
        hds_exec('INSERT INTO dispatch_history(order_id,revision,store_id,name,address,map_url,admin_id,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$id,$revision,$storeId,$store['name']??null,$store['address']??null,$store['map_url']??null,$adminId]);
        $result=hds_dispatch_dto(hds_one('SELECT * FROM orders WHERE id=?',[$id]));$pdo->commit();
        // Báo hệ mới (Laravel) để bắn socket: cửa hàng cũ nhận "removed", cửa hàng mới nhận "dispatched"
        if(function_exists('hq_bridge_notify'))hq_bridge_notify('order.dispatched',$id,['old_store_id'=>$order['dispatch_store_id']!==null?(int)$order['dispatch_store_id']:null]);
        return $result;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function hds_refresh_order(int $id,int $adminId,int $expectedRevision): array { hds_staff($adminId);if($expectedRevision<0)hds_fail('dispatch_changed');return hds_recommend_order($id,true,$expectedRevision); }
/** Two short transactions surround IO; completion checks prevent stale requests from publishing. */
function hds_recommend_order(int $id,bool $force=false,?int $expectedRevision=null): array {
    $pdo=db();if($pdo->inTransaction())hds_fail('dispatch_inside_transaction');$config=hds_private_config();hds_purge_expired($id);$request=bin2hex(random_bytes(16));
    $pdo->beginTransaction();try{
        $order=hds_one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$order)hds_fail('dispatch_order_not_found');
        if(!in_array($order['status'],['new','accepted'],true))hds_fail('dispatch_order_final');if($expectedRevision!==null&&(int)($order['dispatch_revision']??0)!==$expectedRevision)hds_fail('dispatch_changed');
        $address=(string)$order['address'];$addressHash=hash('sha256',$address);$attempt=$order['delivery_geo_attempted_at']??null;
        if($attempt!==null&&strtotime($attempt.' UTC')>time()-30){if($force)hds_fail('dispatch_retry_later');$result=hds_dispatch_dto($order);$pdo->commit();return $result;}
        $cached=!$force&&$order['delivery_lat']!==null&&$order['delivery_lng']!==null&&($order['delivery_geo_address_hash']??'')===$addressHash&&($order['delivery_geo_at']??null)!==null&&($order['delivery_geo_expires_at']??null)!==null&&strtotime($order['delivery_geo_expires_at'].' UTC')>time()&&strtotime($order['delivery_geo_at'].' UTC')>time()-21600;
        hds_exec("UPDATE orders SET delivery_geo_status='pending',delivery_geo_request=?,delivery_geo_attempted_at=UTC_TIMESTAMP() WHERE id=?",[$request,$id]);$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    if(!$config['enabled'])$result=['status'=>'disabled'];elseif($config['api_key']==='')$result=['status'=>'no_key'];
    elseif(!array_filter(hds_stores(true),fn($store)=>$store['lat']!==null&&$store['lng']!==null))$result=['status'=>'no_stores'];
    elseif(trim($address)==='')$result=['status'=>'no_match'];elseif($cached)$result=['status'=>'ready','lat'=>(float)$order['delivery_lat'],'lng'=>(float)$order['delivery_lng']];
    else $result=hds_geocode($address,$config);
    $pdo->beginTransaction();try{
        $fresh=hds_one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$fresh)hds_fail('dispatch_order_not_found');
        if(($fresh['delivery_geo_request']??'')!==$request)hds_fail('dispatch_changed');
        $changed=!in_array($fresh['status'],['new','accepted'],true)||hash('sha256',(string)$fresh['address'])!==$addressHash||($expectedRevision!==null&&(int)($fresh['dispatch_revision']??0)!==$expectedRevision);
        if($changed){hds_exec("UPDATE orders SET delivery_geo_request=NULL,delivery_geo_status='pending' WHERE id=?",[$id]);$pdo->commit();hds_fail('dispatch_changed');}
        // A configuration edit during IO invalidates that result without exposing the key.
        unset($GLOBALS['hds_config_cache']);$current=hds_private_config();if($current!==$config)$result=['status'=>'geocode_failed'];
        if($result['status']==='ready'){
            $stores=array_map('hds_store_dto',$pdo->query('SELECT * FROM dispatch_stores WHERE enabled=1 ORDER BY id FOR UPDATE')->fetchAll());$nearest=hds_nearest_store((float)$result['lat'],(float)$result['lng'],$stores);
            $status=$nearest?'ready':'no_stores';$geoAt=$cached?$order['delivery_geo_at']:gmdate('Y-m-d H:i:s');$expiry=$cached?$order['delivery_geo_expires_at']:gmdate('Y-m-d H:i:s',time()+30*86400);
            hds_exec('UPDATE orders SET recommended_store_id=?,delivery_lat=?,delivery_lng=?,delivery_distance_km=?,delivery_geo_status=?,delivery_geo_request=NULL,delivery_geo_address_hash=?,delivery_geo_at=?,delivery_geo_expires_at=? WHERE id=?',[$nearest['store']['id']??null,$result['lat'],$result['lng'],$nearest['distance_km']??null,$status,$addressHash,$geoAt,$expiry,$id]);
        }else hds_exec('UPDATE orders SET delivery_geo_status=?,delivery_geo_request=NULL WHERE id=?',[$result['status'],$id]);
        $dto=hds_dispatch_dto(hds_one('SELECT * FROM orders WHERE id=?',[$id]));$pdo->commit();return $dto;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
