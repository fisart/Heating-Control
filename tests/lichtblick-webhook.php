<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
function IPS_InstanceExists($id) {return isset($GLOBALS['instanceModules'][$id]);}
function IPS_GetInstance($id) {return ['ModuleInfo'=>['ModuleID'=>$GLOBALS['instanceModules'][$id]]];}
function IPS_GetInstanceListByModuleID($module) {return array_keys(array_filter($GLOBALS['instanceModules'],fn($v)=>$v===$module));}
function IPS_GetProperty($id,$key) {return $GLOBALS['instanceProperties'][$id][$key]??'';}
function IPS_HasChanges($id) {return $GLOBALS['pendingChanges']??false;}
if (($argv[1]??'') !== '--no-auth-api') {
    function SEC_IsPortalAuthenticated($id) {
        if ($GLOBALS['authThrows']??false) throw new RuntimeException('Secret auth internals');
        return $GLOBALS['authenticated']??false;
    }
}
function response($m,$method='GET',$query=[],$host='prices.example'): array {
    return (new ReflectionMethod(LichtBlickElectricityPrice::class,'webResponse'))->invoke($m,
        ['REQUEST_METHOD'=>$method,'HTTP_HOST'=>$host],$query);
}
$GLOBALS['instanceModules']=[7=>'{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}',5=>'{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'];
$GLOBALS['instanceProperties']=[7=>['PortalEnabled'=>true,'PortalOrigin'=>'https://prices.example','PortalBackupOrigin'=>'https://backup.example:46900'],5=>['Hooks'=>'[{"Hook":"/hook/other","TargetID":88}]']];
$m=newModule();$m->properties['WebEnabled']=true;
if (($argv[1]??'') === '--no-auth-api') {check(response($m)['status']===503,'Missing authentication API must fail closed');echo "LichtBlick missing auth API passed.\n";exit;}
check(response($m)['status']===303 && response($m)['headers']['Location']==='/hook/secrets_7?portal=1&return=%2Fhook%2Flichtblick_1','Existing portal login with fixed return path');
check(response($m,'GET',['view'=>'state'])['status']===401,'Anonymous cannot read data');
check(response($m,'GET',['view'=>'price-history','from'=>'2026-10-01','to'=>'2026-10-11'])['status']===401,'Anonymous history blocked');
check(response($m,'HEAD')['status']===401,'Anonymous HEAD blocked');
check(response($m,'POST')['status']===405,'Read-only methods only');
$GLOBALS['authenticated']='true';check(response($m)['status']===303,'Strict Boolean authentication');
$GLOBALS['authenticated']=true;$page=response($m);
check($page['status']===200 && str_contains($page['body'],'LichtBlick & heating') && !str_contains($page['body'],'{{'),'Authenticated template resolved');
check(str_contains($page['headers']['Content-Security-Policy'],"script-src 'nonce-") && str_contains($page['headers']['Content-Security-Policy'],"frame-ancestors 'none'"),'Nonce CSP and no frames');
check($page['headers']['Content-Security-Policy']!==response($m)['headers']['Content-Security-Policy'],'Fresh nonce');
check($page['headers']['Cache-Control']==='private, no-store, max-age=0','Private data never cached');
check(response($m,'GET',[],'evil.example')['status']===403,'Wrong host rejected');
check(response($m,'GET',[],'backup.example:46900')['status']===200,'Configured backup origin works');
check(response($m,'GET',['view'=>'command'])['status']===404,'Unknown endpoint rejected');
$m->properties['Enabled']=true;$m->properties['TariffVerified']=true;
$m->properties['WeatherModelEnabled']=true;
$GLOBALS['liveVariables'][25911]=['value'=>7.0,'VariableType'=>2,'VariableUpdated'=>time()];
$start=(int)(floor(time()/900)*900);$m->response=quote(19,$start);$m->RefreshPrices();
$before=serialize([$m->values,$m->attributes,$m->buffers,$m->properties]);
$data=json_decode(response($m,'GET',['view'=>'state'])['body'],true);
check($data['recommendation']['valid'] && $data['recommendation']['source']===1 && $data['recommendation']['powerPercent']===35,'Dashboard publishes coherent source/power');
check(count($data['curve'])===4 && count($data['slots'])===1 && $data['values']['PriceValid'],'Model and actual price intervals');
check(serialize([$m->values,$m->attributes,$m->buffers,$m->properties])===$before,'Page reads never fetch, learn or mutate instance');
$historyResponse=response($m,'GET',['view'=>'price-history','from'=>'2026-10-01','to'=>'2026-10-11']);
check($historyResponse['status']===200&&json_decode($historyResponse['body'],true)['expectedIntervals']===1056,'Authenticated bounded history data');
check(response($m,'GET',['view'=>'price-history','from'=>'2026-09-30','to'=>'2026-10-11'])['status']===400,'Pre-contract chart rejected');
check(response($m,'GET',['view'=>'price-history','from'=>['2026-10-01'],'to'=>'2026-10-11'])['status']===400,'Array query input rejected');
check(response($m,'GET',['view'=>'price-history','from'=>'2026-10-01','to'=>'2028-01-01'])['status']===400,'Oversized history chart rejected');
check(response($m,'GET',['view'=>'price-history','from'=>'2026-10-01','to'=>'2026-10-11'],'evil.example')['status']===403,'History origin enforced');
check(serialize([$m->values,$m->attributes,$m->buffers,$m->properties])===$before,'History reads do not mutate stored state');
$savedValidity=$m->values['PriceValid'];$m->values['PriceValid']=false;
$data=json_decode(response($m,'GET',['view'=>'state'])['body'],true);
check($data['values']['PriceAvailable']&&!$data['values']['PriceValid']&&!$data['recommendation']['valid'],'Unconfirmed tariff quote remains visible without a valid heating decision');$m->values['PriceValid']=$savedValidity;
$GLOBALS['instanceModules'][42]='{922E5A10-A048-463D-A973-97B4BBD9096B}';$GLOBALS['instanceProperties'][42]=['Enabled'=>true];
check(str_contains(response($m)['body'],'href="/hook/alarm_portal_42"'),'Direct back link to the sole enabled portal');
unset($GLOBALS['instanceModules'][42]);
$snapshot=$m->values['RecommendationJSON'];$expired=json_decode($snapshot,true);$expired['updatedAt']=time()-181;$m->values['RecommendationJSON']=json_encode($expired);
$data=json_decode(response($m,'GET',['view'=>'state'])['body'],true);check(!$data['recommendation']['valid'] && $data['recommendation']['source']===2 && $data['recommendation']['powerPercent']===0,'Expired decision displays gas fallback, zero power');
$expired=json_decode($snapshot,true);$expired['validUntil']=time();$m->values['RecommendationJSON']=json_encode($expired);
check(!json_decode(response($m,'GET',['view'=>'state'])['body'],true)['recommendation']['valid'],'Exclusive recommendation deadline');
$m->values['RecommendationJSON']=$snapshot;$GLOBALS['liveVariables'][25911]['VariableUpdated']=time()-900;
$data=json_decode(response($m,'GET',['view'=>'state'])['body'],true);check(!$data['values']['WeatherModelValid'] && !$data['recommendation']['valid'],'Paused evaluation cannot leave stale weather decision green');
$GLOBALS['liveVariables'][25911]['VariableUpdated']=time();$m->values['PriceEnd']=time();
$data=json_decode(response($m,'GET',['view'=>'state'])['body'],true);check(!$data['values']['PriceValid'] && !$data['recommendation']['valid'],'Expired price rejected without mutating stored variables');
$GLOBALS['authenticated']=false;check(response($m,'GET',['view'=>'state'])['status']===401,'Revoked session checked on each request');$GLOBALS['authenticated']=true;
$GLOBALS['authThrows']=true;$r=response($m);check($r['status']===503 && !str_contains($r['body'],'Secret auth'),'Auth errors fail closed, no internals leaked');$GLOBALS['authThrows']=false;
$GLOBALS['instanceProperties'][7]['PortalEnabled']=false;check(response($m)['status']===503,'Disabled vault rejected');$GLOBALS['instanceProperties'][7]['PortalEnabled']=true;
$GLOBALS['instanceModules'][8]=$GLOBALS['instanceModules'][7];$GLOBALS['instanceProperties'][8]=$GLOBALS['instanceProperties'][7];
check(response($m)['status']===503,'Ambiguous vault selection fails closed');$m->properties['VaultInstanceID']=7;check(response($m)['status']===200,'Explicit vault resolves ambiguity');unset($GLOBALS['instanceModules'][8]);
$m->properties['VaultInstanceID']=5;check(response($m)['status']===503,'Wrong module cannot authenticate');$m->properties['VaultInstanceID']=7;
$GLOBALS['instanceProperties'][7]['PortalOrigin']='http://prices.example';check(response($m)['status']===503,'HTTPS required');$GLOBALS['instanceProperties'][7]['PortalOrigin']='https://prices.example';
$m->properties['WebEnabled']=false;check(response($m)['status']===503,'Page disabled immediately');$m->properties['WebEnabled']=true;
$registry=$GLOBALS['instanceProperties'][5]['Hooks'];$m->ApplyChanges();check($GLOBALS['instanceProperties'][5]['Hooks']===$registry,'Registration deferred outside ApplyChanges');
$m->SetupHook();$hooks=json_decode($GLOBALS['instanceProperties'][5]['Hooks'],true);
check(end($hooks)===['Hook'=>'/hook/lichtblick_1','TargetID'=>1] && $hooks[0]['TargetID']===88,'Register own page, preserve unrelated routes');
$count=$GLOBALS['hookApplies'];$m->SetupHook();check($GLOBALS['hookApplies']===$count,'Idempotent registration');
$GLOBALS['pendingChanges']=true;$m->SetupHook();check($m->updates['timers']['HookSetup']===1500 && $GLOBALS['hookApplies']===$count,'Pending registry edits preserved/retried');$GLOBALS['pendingChanges']=false;
$m->properties['WebEnabled']=false;$m->SetupHook();check(json_decode($GLOBALS['instanceProperties'][5]['Hooks'],true)===json_decode($registry,true),'Disable removes only own route');
$m->properties['WebEnabled']=true;$GLOBALS['instanceProperties'][5]['Hooks']='[{"Hook":"/hook/lichtblick_1","TargetID":99}]';$m->SetupHook();
check(str_contains($m->values['WebPath'],'another object') && $GLOBALS['hookApplies']===$count+1,'Cannot steal a route from another owner');
echo "LichtBlick secure read-only page and webhook lifecycle checks passed.\n";
