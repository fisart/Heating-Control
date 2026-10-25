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
check(response($m,'GET',['view'=>'consumption-history','from'=>'2024-10-01','to'=>'2025-09-30'])['status']===401,'Anonymous consumption/cost history blocked');
check(response($m,'GET',['view'=>'electricity-price-comparison','from'=>'2026-10-01','to'=>'2026-10-10','fixedCt'=>'34'])['status']===401,'Anonymous weighted comparison blocked');
check(response($m,'GET',['view'=>'gas-history','from'=>'2024-10-26','to'=>'2024-10-28'])['status']===401,'Anonymous gas history blocked');
check(response($m,'HEAD')['status']===401,'Anonymous HEAD blocked');
check(response($m,'POST')['status']===405,'Read-only methods only');
$GLOBALS['authenticated']='true';check(response($m)['status']===303,'Strict Boolean authentication');
$GLOBALS['authenticated']=true;$page=response($m);
check($page['status']===200 && str_contains($page['body'],'Verbrauch &amp; Kosten') && !str_contains($page['body'],'{{'),'Authenticated template resolved');
check(str_contains($page['headers']['Content-Security-Policy'],"script-src 'nonce-") && str_contains($page['headers']['Content-Security-Policy'],"frame-ancestors 'none'"),'Nonce CSP and no frames');
check($page['headers']['Content-Security-Policy']!==response($m)['headers']['Content-Security-Policy'],'Fresh nonce');
check($page['headers']['Cache-Control']==='private, no-store, max-age=0','Private data never cached');
check(response($m,'GET',[],'evil.example')['status']===403,'Wrong host rejected');
check(response($m,'GET',[],'backup.example:46900')['status']===200,'Configured backup origin works');
check(response($m,'GET',['view'=>'command'])['status']===404,'Unknown endpoint rejected');
check(response($m,'GET',['view'=>'electricity-price-comparison','from'=>'2026-10-01','to'=>'2026-10-10','fixedCt'=>['34']])['status']===400,'Weighted comparison rejects array inputs');
check(response($m,'GET',['view'=>'electricity-price-comparison','from'=>'2026-10-01','to'=>'2026-10-10','fixedCt'=>'-1'])['status']===400,'Weighted comparison rejects invalid fixed prices');
check(response($m,'GET',['view'=>'electricity-price-comparison','from'=>'2026-10-01','to'=>'2026-10-10','fixedCt'=>'34'],'evil.example')['status']===403,'Weighted comparison enforces the portal origin');
$m->properties['Enabled']=true;$m->properties['TariffVerified']=true;
$m->properties['WeatherModelEnabled']=true;
$GLOBALS['liveVariables'][25911]=['value'=>7.0,'VariableType'=>2,'VariableUpdated'=>time()];
$start=(int)(floor(time()/900)*900);$m->response=quote(19,$start);$m->RefreshPrices();
$before=serialize([$m->values,$m->attributes,$m->buffers,$m->properties]);
$data=json_decode(response($m,'GET',['view'=>'state'])['body'],true);
check(!$data['recommendation']['valid'] && $data['recommendation']['source']===2,'No measured controller gives explicit comparison unavailable/gas fallback');
check(count($data['curve'])===0 && count($data['slots'])===1 && $data['values']['PriceValid'],'Model and actual price intervals');
check(serialize([$m->values,$m->attributes,$m->buffers,$m->properties])===$before,'Page reads never fetch, learn or mutate instance');
$historyResponse=response($m,'GET',['view'=>'price-history','from'=>'2026-10-01','to'=>'2026-10-11']);
check($historyResponse['status']===200&&json_decode($historyResponse['body'],true)['expectedIntervals']===1056,'Authenticated bounded history data');
check(response($m,'GET',['view'=>'price-history','from'=>'2026-09-30','to'=>'2026-10-11'])['status']===400,'Pre-contract chart rejected');
check(response($m,'GET',['view'=>'price-history','from'=>['2026-10-01'],'to'=>'2026-10-11'])['status']===400,'Array query input rejected');
check(response($m,'GET',['view'=>'price-history','from'=>'2026-10-01','to'=>'2028-01-01'])['status']===400,'Oversized history chart rejected');
check(response($m,'GET',['view'=>'price-history','from'=>'2026-10-01','to'=>'2026-10-11'],'evil.example')['status']===403,'History origin enforced');
check(response($m,'GET',['view'=>'consumption-history','from'=>'2024-10-01','to'=>'2025-09-30'],'evil.example')['status']===403,'Consumption history origin enforced');
check(response($m,'GET',['view'=>'consumption-history','from'=>'2024-10-01','to'=>'2025-09-30'])['status']===503,'Disconnected consumption history is unavailable');
check(response($m,'GET',['view'=>'consumption-history','from'=>['2024-10-01'],'to'=>'2025-09-30'])['status']===400,'Consumption query rejects array inputs');
check(response($m,'GET',['view'=>'gas-history','from'=>'2024-10-26','to'=>'2024-10-28'],'evil.example')['status']===403,'Gas history origin enforced');
check(response($m,'GET',['view'=>'gas-history','from'=>'2024-10-26','to'=>'2024-10-28'])['status']===503,'Disconnected gas history unavailable');
check(response($m,'GET',['view'=>'gas-history','from'=>['2024-10-26'],'to'=>'2024-10-28'])['status']===400,'Gas dates reject array inputs');
foreach(['water-history','total-consumption'] as $view){
    check(response($m,'GET',['view'=>$view,'from'=>'2024-10-26','to'=>'2024-10-28'],'evil.example')['status']===403,'Water/total history enforces origin');
    check(response($m,'GET',['view'=>$view,'from'=>['bad'],'to'=>'2024-10-28'])['status']===400,'Water/total reject array dates');
    check(response($m,'GET',['view'=>$view,'from'=>'2024-10-26','to'=>'2024-10-28'])['status']===200,'Unavailable streams are explicitly reported without losing all totals');
}
check(serialize([$m->values,$m->attributes,$m->buffers,$m->properties])===$before,'History reads do not mutate stored state');
$savedValidity=$m->values['PriceValid'];$m->values['PriceValid']=false;
$data=json_decode(response($m,'GET',['view'=>'state'])['body'],true);
check($data['values']['PriceAvailable']&&!$data['values']['PriceValid']&&!$data['recommendation']['valid'],'Unconfirmed tariff quote remains visible without a valid heating decision');$m->values['PriceValid']=$savedValidity;
$GLOBALS['instanceModules'][42]='{922E5A10-A048-463D-A973-97B4BBD9096B}';$GLOBALS['instanceProperties'][42]=['Enabled'=>true,'VaultInstanceID'=>7];
$navigationHooks=$GLOBALS['instanceProperties'][5]['Hooks'];
$GLOBALS['instanceProperties'][5]['Hooks']=json_encode([['Hook'=>'/hook/alarm_portal_42','TargetID'=>42]]);
check(str_contains(response($m)['body'],'href="/hook/alarm_portal_42"'),'Direct back link to the sole enabled portal');
check(str_contains(response($m)['body'],'target="_top"'),'Portal navigation targets top level');
$GLOBALS['instanceProperties'][42]['VaultInstanceID']=99;
check(!str_contains(response($m)['body'],'href="/hook/alarm_portal_42"'),'Other-vault portal is not a navigation destination');
$GLOBALS['instanceProperties'][42]['VaultInstanceID']=7;
$GLOBALS['instanceProperties'][5]['Hooks']='[]';
check(!str_contains(response($m)['body'],'href="/hook/alarm_portal_42"'),'Unregistered portal is not a navigation destination');
$GLOBALS['instanceProperties'][5]['Hooks']=$navigationHooks;
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


