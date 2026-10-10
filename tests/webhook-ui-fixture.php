<?php
require __DIR__.'/webhook-fixture.php';
$m=heatingFixture();$GLOBALS['authenticated']=true;
$GLOBALS['archiveLogging'][9]=[25911=>true,57694=>true];
$m->ProcessHeating();
echo json_encode(['page'=>response($m),'state'=>json_decode(response($m,'GET',['view'=>'state'])['body'],true)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
