<?php
require __DIR__.'/webhook-fixture.php';
$m=heatingFixture();$GLOBALS['authenticated']=true;
$GLOBALS['instanceModules'][10]='{922E5A10-A048-463D-A973-97B4BBD9096B}';
$GLOBALS['instanceProperties'][10]=['Enabled'=>true,'VaultInstanceID'=>7];
$GLOBALS['instanceProperties'][5]['Hooks']='[{"Hook":"/hook/alarm_portal_10","TargetID":10}]';
$GLOBALS['archiveLogging'][9]=[25911=>true,57694=>true];
$m->ProcessHeating();
echo json_encode(['page'=>response($m),'state'=>json_decode(response($m,'GET',['view'=>'state'])['body'],true)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
