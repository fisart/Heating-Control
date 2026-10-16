<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
require __DIR__.'/../LichtBlickElectricityPrice/module.php';
$checks=0;function check($ok,$reason){global $checks;if(!$ok)throw new RuntimeException($reason);$checks++;}
function elements(array $items):array {$out=[];foreach($items as $e){$out[]=$e;if(isset($e['items']))$out=array_merge($out,elements($e['items']));if(isset($e['popup']['items']))$out=array_merge($out,elements($e['popup']['items']));}return $out;}
foreach(['HeatingControl','LichtBlickElectricityPrice'] as $class) {
    $form=json_decode(file_get_contents(__DIR__.'/../'.$class.'/form.json'),true,64,JSON_THROW_ON_ERROR);
    $props=$class==='HeatingControl'?heatingFixture()->properties:(new ReflectionClass($class))->getConstant('DEFAULTS');
    $seen=[];$list=elements(array_merge($form['elements'],$form['actions']));
    foreach($list as $e) {
        if(isset($e['name'])&&!in_array($e['name'],['BackupJson','RoomConfigurationPopup','HeatPumpMatrixOverview'],true)) {
            check(array_key_exists($e['name'],$props),'Unregistered form input '.$class.' / '.$e['name']);
            check(!isset($seen[$e['name']]),'Duplicate input '.$class.' / '.$e['name']);$seen[$e['name']]=true;
            if(isset($e['minimum'])&&is_numeric($props[$e['name']]))check($props[$e['name']]>=$e['minimum']&&$props[$e['name']]<=$e['maximum'],'Default outside form range: '.$e['name']);
        }
        if(isset($e['onClick'])) {
            preg_match_all('/\b(?:HC|LBEP)_([A-Za-z0-9_]+)\(([^)]*)\)/',$e['onClick'],$matches,PREG_SET_ORDER);
            foreach($matches as $m) {
                $method=new ReflectionMethod($class,$m[1]);check($method->isPublic(),'Form action calls non-public API: '.$m[1]);
                $args=substr_count($m[2],',')+1; // first Symcon proxy argument is the instance ID
                check($args-1>=$method->getNumberOfRequiredParameters()&&$args-1<=$method->getNumberOfParameters(),'Wrong action argument count: '.$m[1]);
            }
        }
    }
    if($class==='LichtBlickElectricityPrice') {
        foreach((new ReflectionClass($class))->getConstant('RETIRED_PROPERTIES') as $key)check(!isset($seen[$key]),'Retired input still exposed: '.$key);
        foreach(['GasPriceCt','SafetyMarginPercent','HysteresisCt','ArchiveID','ConsumptionReportSMTPID'] as $key)check(isset($seen[$key]),'Required active setting removed: '.$key);
    }else check(!isset($seen['HeatPumpUseTestModel']),'Retired model switch remains exposed');
}
$m=heatingFixture();$form=json_decode($m->GetConfigurationForm(),true);
$overview=array_values(array_filter(elements($form['elements']),static fn($e)=>($e['name']??'')==='HeatPumpMatrixOverview'))[0];
check(count($overview['values'])===30,'Reorganized form still loads measured rows');
$room=array_values(array_filter($form['elements'],static fn($e)=>($e['name']??'')==='RoomConfigurationPopup'))[0];check(count($room['popup']['items'][1]['values'])===4,'Room editor still loads all rooms');
echo "PASS: $checks form-property, default, API-signature and layout-inventory checks\n";
