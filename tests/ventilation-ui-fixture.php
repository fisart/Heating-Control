<?php
declare(strict_types=1);
require __DIR__.'/ventilation-fixture.php';
$m=fixture();$GLOBALS['authenticated']=true;$server=['REQUEST_METHOD'=>'GET','HTTP_HOST'=>'native.example','HTTPS'=>'on','HTTP_COOKIE'=>'__Host-SECB_900='.str_repeat('a',43)];
$GLOBALS['instances'][950]=['module'=>'{95CDE37D-72CE-4360-BB91-573757C274A0}','moduleName'=>'KNX DPT 9','p'=>[]];$GLOBALS['parents'][301]=950;$GLOBALS['vars'][301]['VariableUpdated']=time()-86400;
$r=['page'=>privateCall($m,'response',$server,[]),'state'=>json_decode(privateCall($m,'response',$server,['action'=>'state'])['body'],true),'admin'=>json_decode(privateCall($m,'response',$server,['action'=>'admin'])['body'],true),'export'=>privateCall($m,'response',$server,['action'=>'export'])['body'],'guide'=>['text'=>file_get_contents(__DIR__.'/../VentilationControl/guide.md')]];
echo json_encode($r,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
