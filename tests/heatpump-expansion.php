<?php
declare(strict_types=1);
require __DIR__.'/heatpump-economics.php';
$before=$checks;
$old=json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-calibration-20261016.json'),true)['points'];
$rows=HeatPumpMatrix::validate(HeatPumpMatrix::seed());
check(count($rows)===43,'Expansion retains 30 originals and appends 13 reviewed rows');
foreach($old as $i=>$p)foreach($p as $key=>$value)
    check($rows[$i][$key]===$value,'Every historical measured field remains unchanged: '.$p['pointID'].' / '.$key);
check(count(HeatPumpSurface::observations())===39,'39 counter-backed normal-heating observations fit the expanded surface');
$thermal=json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-cop-surface-observations.json'),true)['thermalOnlyObservations'];
check(count($thermal)===2&&$thermal[0]['estimatedSystemCOP']===null&&$thermal[1]['estimatedSystemCOP']===null,'Earliest run is preserved as thermal-only, without guessed COP');
$m=fixture();$m->properties['HeatPumpMeasuredMatrix']='[]';
$m->attributes['HeatPumpPerformanceModel']=json_encode(['schema'=>2,'points'=>[end($old)]]);
$actual=webInvoke($m,'measuredMatrix');
$original=array_values(array_filter($actual,static fn($p)=>$p['pointID']==='20261015-211706-139c48ad9dc5-100'))[0];
check(isset($original['supersededBy']),'A stored original cannot reintroduce the cross-step defrost-recovery period');
$candidate=HeatPumpSurface::choose(3.6,9.6,23,5,5,90,2000,3,40259);
check($candidate['powerPercent']===64&&$candidate['airRise']>=18.4&&$candidate['capacityAirRise']>=18.4,'New cold-weather data qualifies a capacity-supported 64 percent candidate');
$GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=3.6;
$GLOBALS['variables'][$m->properties['IncomingAirTempID']]['value']=9.6;
$m->properties['HeatPumpCOPMethod']='surface';$reason='';
$power=(new ReflectionMethod(HeatingControl::class,'measuredEconomicChoice'))->invokeArgs($m,[&$reason]);
check($power===64,'Actual economic path uses the expanded cold-weather surface');
$q=json_decode($GLOBALS['variables'][82001]['value'],true);$q['priceCt']=100;$GLOBALS['variables'][82001]['value']=json_encode($q);
check((new ReflectionMethod(HeatingControl::class,'measuredEconomicChoice'))->invokeArgs($m,[&$reason])===0,'Expensive electricity still selects gas');
require_once __DIR__.'/../HeatingControl/HeatPumpTransient.php';
$fixture=json_decode(file_get_contents(__DIR__.'/data/heatpump-transient-replay-20261023.json'),true);
$samples=[];$guard=[];
foreach($fixture['samples'] as $tuple) {
    [$timestamp,$incoming,$outgoing,$coil]=$tuple;
    $samples[]=compact('timestamp','incoming','outgoing','coil');
    HeatPumpTransient::observe($guard,$samples,$samples[0]['timestamp'],$timestamp);
}
check(count($guard['events'])===2,'Continuous replay finds both latest events across power boundaries');
foreach($fixture['events'] as $i=>$event)foreach($event as $key=>$value)
    check($guard['events'][$i][$key]===$value,'Documented event replay matches PHP: '.$i.' / '.$key);
echo 'PASS: '.($checks-$before)." preserved-row, expansion, replay and live-path checks\n";
