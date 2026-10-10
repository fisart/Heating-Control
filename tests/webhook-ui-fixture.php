<?php
require __DIR__.'/webhook-fixture.php';
$m=heatingFixture();$GLOBALS['authenticated']=true;
$m->ProcessHeating();
echo json_encode(['page'=>response($m),'state'=>json_decode(response($m,'GET',['view'=>'state'])['body'],true)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
