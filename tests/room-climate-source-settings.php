<?php
declare(strict_types=1);
require __DIR__.'/room-climate.php';
$m=$n;$GLOBALS['instance']=$m;$m->properties['WebEnabled']=true;$m->properties['Rooms']=file_get_contents(__DIR__.'/../RoomClimate/rooms.json');$m->properties['SourceSettings']='{}';$GLOBALS['authenticated']=true;
$server=['REQUEST_METHOD'=>'GET','HTTP_HOST'=>'native.example','HTTPS'=>'on','HTTP_COOKIE'=>'__Host-SECB_118='.str_repeat('a',43)];
function responseSources($m,$server,$query,$body=''){return callPrivate($m,'response',$server,$query,$body);}
function roomByKey($rooms,$key){return array_values(array_filter($rooms,static fn($r)=>$r['key']===$key))[0];}
function sourceByID($room,$id){return array_values(array_filter($room['sources'],static fn($s)=>$s['id']===$id))[0]??null;}
$all=callPrivate($m,'sourceConfiguration');$active=callPrivate($m,'activeConfiguration');
foreach(['computer-room'=>[26654,53344,20226],'gym'=>[15718],'washroom'=>[34067],'guest-bathroom-gf'=>[23367]] as $key=>$ids)foreach($ids as $id){check(sourceByID(roomByKey($all,$key),$id)['enabled']===false,'Expected disabled Shelly source '.$id);check(sourceByID(roomByKey($active,$key),$id)===null,'Excluded source leaked into active readings '.$id);}
foreach([59814,52019] as $id)check(sourceByID(roomByKey($active,'master-bathroom'),$id)!==null,'Master bathroom exception lost');
check(sourceByID(roomByKey($active,'electronic-office'),31185)!==null,'Shelly thermostat without a FRITZ replacement excluded');
check(sourceByID(roomByKey($active,'guest-bathroom-gf'),24196)!==null,'FRITZ setpoint removed');
$r=responseSources($m,$server,['action'=>'source-settings']);$data=json_decode($r['body'],true);check($r['status']===200&&isset($data['csrf'],$data['revision']),'Protected settings did not load');
$payload=['csrf'=>$data['csrf'],'revision'=>$data['revision'],'updates'=>['computer-room:26654'=>true,'master-bathroom:59814'=>false]];
$post=array_replace($server,['REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>'https://native.example','CONTENT_TYPE'=>'application/json','HTTP_SEC_FETCH_SITE'=>'same-origin']);
$GLOBALS['authenticated']=false;check(responseSources($m,$post,['action'=>'source-settings'],json_encode($payload))['status']===401,'Anonymous settings write accepted');$GLOBALS['authenticated']=true;
check(responseSources($m,array_replace($post,['HTTP_ORIGIN'=>'https://evil.example']),['action'=>'source-settings'],json_encode($payload))['status']===403,'Cross-origin settings write accepted');
check(responseSources($m,array_replace($post,['HTTP_SEC_FETCH_SITE'=>'cross-site']),['action'=>'source-settings'],json_encode($payload))['status']===403,'Cross-site settings write accepted');
$wrong=$payload;$wrong['csrf']=str_repeat('0',64);check(responseSources($m,$post,['action'=>'source-settings'],json_encode($wrong))['status']===403,'Invalid CSRF accepted');
$other=$post;$other['HTTP_COOKIE']='__Host-SECB_118='.str_repeat('b',43);check(responseSources($m,$other,['action'=>'source-settings'],json_encode($payload))['status']===403,'CSRF not bound to current session');
$bad=$payload;$bad['updates']=['computer-room:99999'=>true];check(responseSources($m,$post,['action'=>'source-settings'],json_encode($bad))['status']===400,'Unknown source accepted');
$bad['updates']=['computer-room:26654'=>'true'];check(responseSources($m,$post,['action'=>'source-settings'],json_encode($bad))['status']===400,'Non-boolean override accepted');
$GLOBALS['kernelRunlevel']=10001;check(responseSources($m,$post,['action'=>'source-settings'],json_encode($payload))['status']===503,'Source settings changed before kernel readiness');$GLOBALS['kernelRunlevel']=KR_READY;
$GLOBALS['pending'][$m->InstanceID]=true;check(responseSources($m,$post,['action'=>'source-settings'],json_encode($payload))['status']===400,'Pending console changes overwritten');$GLOBALS['pending'][$m->InstanceID]=false;
$r=responseSources($m,$post,['action'=>'source-settings'],json_encode($payload));check($r['status']===200,'Valid source settings write failed');$active=callPrivate($m,'activeConfiguration');check(sourceByID(roomByKey($active,'computer-room'),26654)!==null,'Explicit re-enable ignored');check(sourceByID(roomByKey($active,'master-bathroom'),59814)===null,'Explicit disable ignored');
check(responseSources($m,$post,['action'=>'source-settings'],json_encode($payload))['status']===400,'Stale revision overwrote newer settings');
$history=callPrivate($m,'history',['room'=>'master-bathroom','range'=>'24h']);check(!in_array(59814,array_column($history['series'],'id'),true)&&in_array(52019,array_column($history['series'],'id'),true),'Room history did not honor source switches');
foreach([59814,52019,32551,44964] as $id)$GLOBALS['variables'][$id]=['VariableType'=>2,'value'=>20.0,'VariableUpdated'=>time(),'VariableChanged'=>time()];$GLOBALS['aggregateIDs']=[];callPrivate($m,'sparklines',['rooms'=>'master-bathroom']);check(!in_array(59814,$GLOBALS['aggregateIDs'],true),'Disabled source reached the tile chart archive');
$payload=json_decode($m->ExportConfig(),true);$overrides=json_decode($payload['properties']['SourceSettings'],true);check($overrides['computer-room:26654']===true&&$overrides['master-bathroom:59814']===false,'Export lost source settings');
$envelope=json_decode($m->ExportConfigurationBackup(),true);check(json_decode($m->ValidateConfigurationBackup(json_encode($envelope['payload'])),true)['valid'],'Configuration backup rejected source settings');check(!str_contains(json_encode($envelope),$m->attributes['SourceCSRFKey']),'CSRF key leaked to backup');
$m->ImportConfig(json_encode($payload));check(!$m->properties['WebEnabled']&&json_decode($m->properties['SourceSettings'],true)===$overrides,'Restore did not retain source choices safely');
unset($payload['properties']['SourceSettings']);$m->ImportConfig(json_encode($payload));check(($m->properties['SourceSettings']??null)==='{}','Older export did not receive default settings');
if($root=getenv('RCL_BACKUP_MODULE')){
    require_once $root.'/ConfigurationBackup/Engine.php';
    function IPS_GetModule($id){return ['Prefix'=>'RCL','ModuleName'=>'RoomClimate'];}
    function RCL_ExportConfigurationBackup($id){return $GLOBALS['instance']->ExportConfigurationBackup();}
    function RCL_ValidateConfigurationBackup($id,$json){return $GLOBALS['instance']->ValidateConfigurationBackup($json);}
    $properties=json_decode(IPS_GetConfiguration($m->InstanceID),true);$capture=MCBBackupProtocol::capture($m->InstanceID,RoomClimate::MODULE,$properties,[]);
    check(($capture['validation']['valid']??false)===true,'Actual Configuration Backup could not capture and validate source settings');
}
echo "Room source settings: security, defaults, visibility, charts and restore passed.\n";
