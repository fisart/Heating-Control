<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
$checks=0;
function check($truth,$why) { global $checks; if (!$truth) throw new RuntimeException($why); $checks++; }
$m=heatingFixture();
if (($argv[1] ?? '')==='--no-auth-api') { check(response($m)['status']===503,'Missing authentication API fails closed'); echo "Missing authentication API passed.\n"; exit; }
check(response($m)['status']===303,'Anonymous redirects to existing passkey portal');
check(response($m)['headers']['Location']==='/hook/secrets_7?portal=1&return=%2Fhook%2Fheating_1','Fixed local return path');
check(response($m,'GET',['view'=>'state'])['status']===401,'Anonymous cannot read heating state');
check(response($m,'POST',[],['control'=>'WinterID','value'=>false])['status']===401,'Anonymous cannot write');
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Anonymous causes no writes');
$GLOBALS['authenticated']='true';check(response($m)['status']===303,'Authentication must be strict Boolean');
$GLOBALS['authenticated']=true;
$page=response($m);check($page['status']===200&&str_contains($page['body'],'Room climate'),'Authenticated HTML page');
check(!str_contains($page['body'],'{{'),'All template substitutions resolved');
check(str_contains($page['headers']['Content-Security-Policy'],"frame-ancestors 'none'")&&str_contains($page['headers']['Content-Security-Policy'],"script-src 'nonce-"),'Restrictive nonce CSP');
check($page['headers']['Cache-Control']==='private, no-store, max-age=0','Sensitive page never cached');
check($page['headers']['Content-Security-Policy']!==response($m)['headers']['Content-Security-Policy'],'Fresh CSP nonce');
$s=json_decode(response($m,'GET',['view'=>'state'])['body'],true);
check(count($s['rooms'])===4&&$s['rooms'][0]['actual']===23.4&&$s['editable'],'Existing room data and controls exposed');
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Reading state and rendering send no commands');
check(response($m,'GET',[],null,['HTTP_HOST'=>'evil.example'])['status']===403,'Unknown host denied');
check(response($m,'POST',[],['control'=>'WinterID','value'=>false],['HTTP_ORIGIN'=>'https://evil.example'])['status']===403,'Cross-origin write denied');
check(response($m,'POST',[],['control'=>'WinterID','value'=>false],['HTTP_X_HEATING_CSRF'=>'invalid'])['status']===403,'Invalid CSRF denied');
check(response($m,'POST',[],['control'=>'WinterID','value'=>false],['HTTP_SEC_FETCH_SITE'=>'cross-site'])['status']===403,'Cross-site write denied');
check(response($m,'POST',[],['control'=>'WinterID','value'=>false],['HTTP_ORIGIN'=>''])['status']===403,'Missing origin denied');
$oldCookie=$_COOKIE;$_COOKIE['SEC_PORTAL_V2_7']='other-session';
check(response($m,'POST',[],['control'=>'WinterID','value'=>false],['HTTP_X_HEATING_CSRF'=>$s['csrf']])['status']===403,'CSRF is bound to authenticated session');$_COOKIE=$oldCookie;
check(response($m,'POST',[],'{oops')['status']===422,'Malformed JSON rejected');
check(response($m,'POST',[],str_repeat('x',16385))['status']===413,'Oversized request rejected');
check(response($m,'POST',[],['control'=>'WinterID','value'=>false],['CONTENT_TYPE'=>'text/plain'])['status']===415,'Non-JSON content rejected');
check(response($m,'DELETE')['status']===405,'Unsupported method denied');
foreach([['control'=>'FanOnID','value'=>true],['control'=>'room:99','value'=>24],['control'=>'WinterID','value'=>1],['control'=>'room:0','value'=>'24'],['control'=>'room:0','value'=>31],['control'=>'OperatingModeID','value'=>99],['control'=>'FanDesiredSpeedID','value'=>50.5],['control'=>'WinterID','value'=>false,'id'=>12345]] as $bad) {
    check(response($m,'POST',[],$bad)['status']===422,'Unsupported/invalid command rejected');
}
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'All rejected writes leave variables untouched');
$m->properties['DryRun']=true;check(response($m,'POST',[],['control'=>'room:0','value'=>24])['status']===422,'Dry run refuses all web writes');
check(!json_decode(response($m,'GET',['view'=>'state'])['body'],true)['editable'],'Dry-run state read-only');
$m->properties['DryRun']=false;$m->properties['Enabled']=false;
check(response($m,'POST',[],['control'=>'room:0','value'=>24])['status']===422,'Disabled controller refuses writes');$m->properties['Enabled']=true;
$GLOBALS['profiles']['RoomProfile']=['MinValue'=>18,'MaxValue'=>25];$GLOBALS['variables'][12594]['VariableCustomProfile']='RoomProfile';
check(response($m,'POST',[],['control'=>'room:0','value'=>26])['status']===422,'Native variable profile bounds respected');
$r=response($m,'POST',[],['control'=>'room:0','value'=>24.0]);check($r['status']===200&&GetValue(12594)===24.0,'Room target written through configured variable');
check((json_decode($m->attributes['DemandLatch'],true)['Living and Dining Room'] ?? false)===true && count($GLOBALS['directWrites'])>1,'New room target evaluated by original heating controller');
$r=response($m,'POST',[],['control'=>'room:0','value'=>24.5],['CONTENT_TYPE'=>null,'HTTP_CONTENT_TYPE'=>'application/json; charset=utf-8']);
check($r['status']===200&&GetValue(12594)===24.5,'Symcon HTTP_CONTENT_TYPE accepts the page JSON and applies the target');
$r=response($m,'POST',[],['control'=>'room:0','value'=>24.0],['CONTENT_TYPE'=>'','HTTP_CONTENT_TYPE'=>'Application/JSON']);
check($r['status']===200&&GetValue(12594)===24.0,'Empty standard content type falls back to the Symcon header');
$before=[$GLOBALS['directWrites'],$GLOBALS['commands']];
foreach ([['CONTENT_TYPE'=>null,'HTTP_CONTENT_TYPE'=>'text/plain'],['CONTENT_TYPE'=>null,'HTTP_CONTENT_TYPE'=>null],
          ['CONTENT_TYPE'=>'text/plain','HTTP_CONTENT_TYPE'=>'application/json']] as $headers) {
    check(response($m,'POST',[],['control'=>'room:0','value'=>24.5],$headers)['status']===415,'Missing/non-JSON/conflicting content type rejected');
}
check(response($m,'POST',[],'{oops',['CONTENT_TYPE'=>null,'HTTP_CONTENT_TYPE'=>'application/json'])['status']===422,'Symcon header still requires valid JSON');
check([$GLOBALS['directWrites'],$GLOBALS['commands']]===$before,'Rejected header/body variants cannot change heating variables');
$m=heatingFixture();$GLOBALS['authenticated']=true;$m->ProcessHeating();check($m->values['ResidualHeating'],'Eligible recovery active before switching');
$r=response($m,'POST',[],['control'=>'RecoveryEnableID','value'=>false]);check($r['status']===200&&!GetValue(61003)&&!$m->values['ResidualHeating'],'Switch off stops recovery via existing controller');
check(in_array([46921,false],$GLOBALS['directWrites'],true),'Recovery switch off stops fan');
$r=response($m,'POST',[],['control'=>'RecoveryEnableID','value'=>true]);check($r['status']===200&&$m->values['ResidualHeating'],'Recovery can be permitted again');
$m=heatingFixture();$GLOBALS['authenticated']=true;$GLOBALS['variables'][12594]['VariableCustomAction']=123;
$r=response($m,'POST',[],['control'=>'room:0','value'=>24]);check($r['status']===200&&in_array([12594,24.0],$GLOBALS['commands'],true),'Existing custom action used for target');
check(!json_decode($r['body'],true)['confirmed'],'Unconfirmed action not falsely reported as applied');
$m=heatingFixture();$GLOBALS['authenticated']=true;$m->properties['FanDesiredSpeedID']=$m->properties['FanSpeedID'];
check(response($m,'POST',[],['control'=>'FanDesiredSpeedID','value'=>42])['status']===422,'Input aliases to actuator rejected');
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Aliased actuator untouched');
$m=heatingFixture();$GLOBALS['authenticated']=true;
check(response($m,'GET',['view'=>'state'],null,['HTTP_HOST'=>'backup.example'])['status']===200,'Configured backup portal origin supported');
check(response($m,'POST',[],['control'=>'HolidayID','value'=>true],['HTTP_HOST'=>'backup.example','HTTP_ORIGIN'=>'https://backup.example'])['status']===200,'Backup origin controls use same authenticated session API');
$GLOBALS['authenticated']=false;check(response($m,'GET',['view'=>'state'])['status']===401,'Session revocation checked for every refresh');
$GLOBALS['authThrows']=true;$r=response($m);check($r['status']===503&&!str_contains($r['body'],'Private vault'),'Vault failure fails closed without leakage');$GLOBALS['authThrows']=false;
$m=heatingFixture();$GLOBALS['authenticated']=true;$GLOBALS['instanceModules'][8]=$GLOBALS['instanceModules'][7];$GLOBALS['instanceProperties'][8]=$GLOBALS['instanceProperties'][7];
check(response($m)['status']===503,'Ambiguous automatic vault selection rejected');$m->properties['VaultInstanceID']=7;check(response($m)['status']===200,'Explicit vault selection');
$m->properties['VaultInstanceID']=5;check(response($m)['status']===503,'Wrong module cannot authenticate');
$m=heatingFixture();$before=$GLOBALS['instanceProperties'][5]['Hooks'];$m->ApplyChanges();check($GLOBALS['instanceProperties'][5]['Hooks']===$before,'Apply lifecycle does not modify another instance');
$m->SetupHook();$hooks=json_decode($GLOBALS['instanceProperties'][5]['Hooks'],true);check(count($hooks)===2&&end($hooks)===['Hook'=>'/hook/heating_1','TargetID'=>1],'Webhook registered without losing unrelated entries');
$m->SetupHook();check(count(json_decode($GLOBALS['instanceProperties'][5]['Hooks'],true))===2,'Registration idempotent');
$m->properties['WebEnabled']=false;$m->SetupHook();check(json_decode($GLOBALS['instanceProperties'][5]['Hooks'],true)===json_decode($before,true),'Disabling web removes only its own hook');
$m->properties['WebEnabled']=true;$GLOBALS['instanceProperties'][5]['Hooks']='[{"Hook":"/hook/heating_1","TargetID":99}]';$m->SetupHook();check($GLOBALS['instanceProperties'][5]['Hooks']==='[{"Hook":"/hook/heating_1","TargetID":99}]','Cannot take another hook owner');
$m=heatingFixture();$GLOBALS['pendingChanges']=true;$before=$GLOBALS['instanceProperties'][5]['Hooks'];$m->SetupHook();check($GLOBALS['instanceProperties'][5]['Hooks']===$before,'Pending webhook edits preserved');
$m=heatingFixture();$backup=$m->ExportConfig();$m->ImportConfig($backup);check(!$m->properties['WebEnabled']&&!$m->properties['Enabled']&&$m->properties['DryRun'],'Restore retains auth selection but disables web and controller');
echo "Heating webhook: $checks assertions passed.\n";
