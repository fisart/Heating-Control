<?php
declare(strict_types=1);
require __DIR__.'/symcon-stub.php';
function IPS_InstanceExists($id){return isset($GLOBALS['instances'][$id]);}
function IPS_HasChanges($id){return $GLOBALS['pending'][$id]??false;}
function IPS_GetInstance($id){return ['ModuleInfo'=>['ModuleID'=>$GLOBALS['instances'][$id]['module']]];}
function IPS_GetProperty($id,$key){return $GLOBALS['instances'][$id]['properties'][$key];}
function IPS_GetInstanceListByModuleID($id){return array_keys(array_filter($GLOBALS['instances'],static fn($i)=>$i['module']===$id));}
function SECB_GetNativePortalLoginURL($id,$path,$json){$s=json_decode($json,true);if(($s['HTTP_HOST']??'')!=='native.example')throw new RuntimeException('origin rejected');return 'https://native.example/hook/secrets_frontend_119#legacy-return='.rawurlencode($path);}
function SECB_IsNativePortalAuthenticated($id){return $GLOBALS['authenticated'];}
function AC_GetLoggingStatus($a,$id){return !($GLOBALS['unlogged'][$id]??false);}
function AC_GetAggregationType($a,$id){return $GLOBALS['aggregation'][$id]??0;}
function AC_GetLoggedValues($a,$id,$from,$to,$limit){$GLOBALS['historyReads']++;return $GLOBALS['historyRows']??[['TimeStamp'=>$to-20,'Value'=>21.2],['TimeStamp'=>$to-20,'Value'=>99],['TimeStamp'=>$to-100,'Value'=>20]];}
function AC_GetAggregatedValues($a,$id,$level,$from,$to,$limit){$GLOBALS['historyReads']++;return [['TimeStamp'=>$to-100,'Avg'=>21.3]];}
require __DIR__.'/../RoomClimate/module.php';
function check($b,$why){$GLOBALS['checks']++;if(!$b)throw new RuntimeException($why);}
function callPrivate($m,$name,...$args){return (new ReflectionMethod($m,$name))->invoke($m,...$args);}
$GLOBALS['checks']=0;$GLOBALS['authenticated']=false;$GLOBALS['historyReads']=0;
$GLOBALS['instances']=[118=>['module'=>'{52165EDD-B2C0-40EC-8A59-42A97319B6E2}','properties'=>['Enabled'=>true,'AdminOrigin'=>'https://native.example','FrontendInstanceID'=>119]],119=>['module'=>'{8D6AE8F7-EA3A-4513-AA14-C3F9613B0E83}','properties'=>['Enabled'=>true,'AdminOrigin'=>'https://native.example','BackendInstanceID'=>118]],200=>['module'=>'{43192F0B-135B-4CE7-A0A7-1475603F3060}','properties'=>[]]];
$m=new RoomClimate();$m->Create();$GLOBALS['instance']=$m;
$defaults=callPrivate($m,'configuration');check(count($defaults)===21,'Missing rooms');check(array_sum(array_map(fn($r)=>count($r['sources']),$defaults))===103,'Missing source mappings');
$m->properties['Rooms']=json_encode([['key'=>'test','floor'=>'Ground floor','name'=>'Test <script>','sources'=>[['id'=>123,'kind'=>'temperature','label'=>'HomeMatic'],['id'=>124,'kind'=>'humidity','label'=>'HomeMatic'],['id'=>125,'kind'=>'target','label'=>'Air target']]]]);
foreach([123=>21.2,124=>50,125=>22] as $id=>$value)$GLOBALS['variables'][$id]=['VariableType'=>2,'value'=>$value,'VariableUpdated'=>time(),'VariableChanged'=>time()];
$server=['REQUEST_METHOD'=>'GET','HTTP_HOST'=>'native.example','HTTPS'=>'on'];
$r=callPrivate($m,'response',$server,[]);check($r['status']===303,'Anonymous page allowed');check($m->buffers===[],'Anonymous request read sensor data');
check(callPrivate($m,'response',$server,['action'=>'history','room'=>'test'])['status']===401,'Anonymous history allowed');check($GLOBALS['historyReads']===0,'Anonymous archive read');
$GLOBALS['authenticated']=true;$r=callPrivate($m,'response',$server,[]);check($r['status']===200&&isset($r['headers']['Content-Security-Policy']),'HTML CSP missing');
$r=callPrivate($m,'response',array_replace($server,['HTTP_HOST'=>'evil.example']),['action'=>'state']);check($r['status']===503,'Origin not validated');
$r=callPrivate($m,'response',$server,['action'=>'state']);$d=json_decode($r['body'],true);check($d['full']&&count($d['values'])===3,'Initial state incomplete');
$r=callPrivate($m,'response',$server,['action'=>'state','cursor'=>$d['cursor']]);$same=json_decode($r['body'],true);check(!$same['full']&&!$same['values'],'Unchanged state retransmitted');check(strlen($r['body'])<160,'Idle delta too large');
$GLOBALS['variables'][123]['value']=22.1;$r=callPrivate($m,'response',$server,['action'=>'state','cursor'=>$d['cursor']]);$changed=json_decode($r['body'],true);check(count($changed['values'])===1&&isset($changed['values'][123]),'Delta failed');
$GLOBALS['variables'][123]['VariableUpdated']=time()-90000;$d=callPrivate($m,'state',$changed['cursor']);check($d['values']->{123}['status']==='stale','Old reading not marked stale');
$m->properties['StaleSeconds']=3600; // Existing installations retain the former field on import.
$GLOBALS['variables'][123]['VariableUpdated']=time()-86399;check(callPrivate($m,'reading',123,'temperature')['status']==='ok','23-hour measurement classified as inactive');
$GLOBALS['variables'][123]['VariableUpdated']=time()-86400;check(callPrivate($m,'reading',123,'temperature')['status']==='stale','24-hour boundary not stale');
$GLOBALS['variables'][125]['VariableUpdated']=time()-864000;check(callPrivate($m,'reading',125,'target')['status']==='ok','Unchanged target classified as stale');
$GLOBALS['variables'][124]['value']=110;$d=callPrivate($m,'state','');check($d['values']->{124}['status']==='invalid','Invalid humidity accepted');
check(callPrivate($m,'response',$server,['action'=>'history','room'=>'other'])['status']===400,'Arbitrary room history allowed');
$d=callPrivate($m,'history',['room'=>'test','range'=>'1h']);check(count($d['series'])===3,'Not all room series loaded');check(count($d['series'][0]['points'])===2&&$d['series'][0]['points'][1][1]===21.2,'Duplicate archive timestamps changed source precedence');
$reads=$GLOBALS['historyReads'];callPrivate($m,'history',['room'=>'test','range'=>'1h']);check($reads===$GLOBALS['historyReads'],'History cache failed');
$GLOBALS['authenticated']=false;check(callPrivate($m,'response',$server,['action'=>'history','room'=>'test','range'=>'1h'])['status']===401,'Cached data bypasses authentication');$GLOBALS['authenticated']=true;
$GLOBALS['pending'][118]=true;check(callPrivate($m,'backend')===0,'Pending backend accepted');$GLOBALS['pending'][118]=false;
$GLOBALS['instances'][118]['properties']['Enabled']=false;check(callPrivate($m,'backend')===0,'Disabled backend accepted');$GLOBALS['instances'][118]['properties']['Enabled']=true;
$GLOBALS['instances'][119]['properties']['AdminOrigin']='https://other.example';check(callPrivate($m,'backend')===0,'Mismatched frontend origin accepted');$GLOBALS['instances'][119]['properties']['AdminOrigin']='https://native.example';
$GLOBALS['authenticated']='1';check(callPrivate($m,'response',$server,['action'=>'state'])['status']===401,'Truthy authentication accepted');$GLOBALS['authenticated']=true;
check(callPrivate($m,'response',array_replace($server,['REQUEST_METHOD'=>'POST']),['action'=>'state'])['status']===405,'Web mutation accepted');
$m->SetBuffer('History','');$GLOBALS['unlogged'][124]=true;$d=callPrivate($m,'history',['room'=>'test','range'=>'1h']);check($d['series'][1]['points']===[]&&str_contains($d['series'][1]['status'],'Logging'),'Unlogged humidity fabricated');
$export=$m->ExportConfig();$envelope=json_decode($m->ExportConfigurationBackup(),true);$validation=json_decode($m->ValidateConfigurationBackup(json_encode($envelope['payload'])),true);check($validation['valid'],'Backup validator rejected own export');
$m->properties['DebugEnabled']=true;callPrivate($m,'debug','test');check(str_contains($m->values['Debug'],'test'),'Debug not below instance');
$bad=json_decode($export,true);$bad['properties']['Rooms']='broken';try{$m->ImportConfig(json_encode($bad));throw new RuntimeException('Bad import accepted');}catch(JsonException $e){}check($m->properties['WebEnabled'],'Failed import mutated configuration');
// Override lifecycle only for the standalone stub (it has no SetStatus implementation).
class ImportClimate extends RoomClimate {public function ApplyChanges(){}}
$n=new ImportClimate();$n->Create();$GLOBALS['instance']=$n;$n->ImportConfig($export);check(!$n->properties['WebEnabled'],'Import enabled web access');
check(!str_contains(file_get_contents(__DIR__.'/../RoomClimate/module.php'),'RequestAction('),'Dashboard writes external controls');
echo 'Room climate: '.$GLOBALS['checks']." assertions passed.\n";
