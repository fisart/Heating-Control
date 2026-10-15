<?php
declare(strict_types=1);
require __DIR__.'/heatpump-economics.php';
$startChecks=$checks;

// Coefficients must reproduce the saved surface, including internal spline
// intervals and both domain edges. These references come from the original fit.
foreach([[5,30,2.455443352314879],[12,50,3.4899480878184344],[16,48,3.614691620879209],[8.5,49,3.386573512081473]] as [$t,$power,$cop]) {
    check(abs(HeatPumpSurface::cop($t,$power)-$cop)<0.000001,'Saved mathematical surface reproduced: '.$t.'C / '.$power.'%');
}
foreach([[4.99,50],[16.01,50],[12,29],[12,101],[NAN,50]] as [$t,$p]) {
    try {HeatPumpSurface::cop($t,$p);check(false,'Out-of-domain COP must fail');}catch(InvalidArgumentException $e){check(true,'Surface domain is not extrapolated');}
}
$candidate=HeatPumpSurface::choose(12,23,23,5,5,90,2000,3,40259);
check($candidate['powerPercent']===49&&$candidate['modeled']&&$candidate['copMethod']==='surface','Surface searches one-percent power commands and labels estimates');
check(abs($candidate['estimatedSystemCOP']-3.492345925332406)<1e-10,'Selected power uses the saved spline COP');
check($candidate['predictedOutgoing']>=28&&$candidate['airRise']>=5,'Surface candidate still meets air-supply requirements');
check(!isset($candidate['stable'])&&!isset($candidate['usable']),'A modeled candidate does not acquire measured quality flags');
try {HeatPumpMatrix::validate([$candidate]);check(false,'Modeled row must not enter measured history');}catch(InvalidArgumentException $e){check(true,'Modeled candidate cannot masquerade as a measured matrix row');}
check(count(HeatPumpSurface::observations())===20,'Only the twenty accepted fit observations support the surface');
check($candidate['electricalMethod']==='house-counter-minus-pretest-baseline','Surface retains its actual historical meter basis');
foreach([[2,23,23,5,5,90,2000,3,40259],[17,23,23,5,5,90,2000,3,40259],[12,10,10,5,5,90,2000,3,40259],
    [12,23,60,5,5,90,2000,3,40259],[12,23,23,5,5,80,2000,3,40259],[12,23,23,5,5,90,1500,3,40259],[12,23,23,5,5,90,2000,3,999]] as $args)
    check(HeatPumpSurface::choose(...$args)===null,'Unsupported weather/intake/supply/fan/airflow/flap cannot authorize a surface threshold');

$m=fixture();check($m->properties['HeatPumpCOPMethod']==='measured','Upgrade default retains the measured-point method');
$reason='';$fn=new ReflectionMethod(HeatingControl::class,'measuredEconomicChoice');
check($fn->invokeArgs($m,[&$reason])===50,'Default path still chooses the exact recorded 50% COP');
$measured=json_decode($m->values['HeatPumpEconomicsJSON'],true);
check($measured['copMethod']==='measured'&&abs($measured['point']['estimatedSystemCOP']-3.612368428301706)<1e-10,'Measured COP is not smoothed or replaced');
$m->properties['HeatPumpCOPMethod']='surface';
check($fn->invokeArgs($m,[&$reason])===49,'Automatic source-selection input uses the surface candidate');
$surface=json_decode($m->values['HeatPumpEconomicsJSON'],true);
check($surface['copMethod']==='surface'&&$surface['surfaceVersion']===HeatPumpSurface::definition()['version'],'Economic snapshot names selected method and surface version');
$sourceChoice=new ReflectionMethod(HeatingControl::class,'sourceChoice');$queue=[];
check($sourceChoice->invokeArgs($m,[2,&$queue])===[1,49]&&$queue[$m->properties['OperatingModeID']]===1,'Actual automatic control path accepts intermediate surface power and mirrors heat-pump source');

check(str_contains($reason,'COP surface (modeled)'),'Operating reason clearly distinguishes modeled COP');
check(abs($surface['breakEvenCt']-10.48*$surface['point']['estimatedSystemCOP'])<1e-9,'Surface COP defines the economic break-even threshold');
check(abs($surface['exitCt']-$surface['breakEvenCt']*.75)<1e-9&&abs($surface['entryCt']-($surface['exitCt']-2))<1e-9,'Safety margin and hysteresis apply exactly once with surface COP');
check(str_contains($surface['assumptions'],'frozen house-meter'),'Surface assumptions do not claim new-meter calibration');

// Between weather runs, exact measured matching may have no point while the
// fitted surface interpolates its supported 3D plane.
$GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=8.5;
$m->properties['HeatPumpCOPMethod']='measured';
check($fn->invokeArgs($m,[&$reason])===0,'Existing measured tolerance remains unchanged in the temperature gap');
$m->properties['HeatPumpCOPMethod']='surface';
check($fn->invokeArgs($m,[&$reason])>0,'Surface supplies an estimate between the accepted temperature runs');
$GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=4;
check($fn->invokeArgs($m,[&$reason])===0&&str_contains($reason,'5–16°C'),'Surface mode falls back to gas outside its domain');
$fallback=json_decode($m->values['HeatPumpEconomicsJSON'],true);
check(!$fallback['valid']&&$fallback['copMethod']==='surface'&&!isset($fallback['point'])&&!isset($fallback['breakEvenCt']),'Unsupported surface conditions do not silently use exact points or an invented COP');
$m=fixture();$m->properties['HeatPumpCOPMethod']='surface';$q=json_decode($GLOBALS['variables'][82001]['value'],true);$q['validUntil']=$m->clock;$GLOBALS['variables'][82001]['value']=json_encode($q);
check($fn->invokeArgs($m,[&$reason])===0&&$m->values['HeatPumpBreakEvenEuro']===0.0,'Surface mode cannot bypass expired price verification');
$m=fixture();$m->properties['HeatPumpCOPMethod']='unknown';
check($fn->invokeArgs($m,[&$reason])===0&&str_contains($reason,'Invalid COP method'),'Invalid method fails to gas');

// The public/page selector persists the choice, without enabling automatic
// heating or overwriting manual desired values, and respects both web gates.
$m=fixture();$m->values['AutomaticSourceSelection']=false;
$desired=GetValue($m->properties['HeatPumpDesiredPowerID']);
$m->SetHeatPumpCOPMethod('surface');
check($m->properties['HeatPumpCOPMethod']==='surface'&&!$m->values['AutomaticSourceSelection']&&GetValue($m->properties['HeatPumpDesiredPowerID'])===$desired,'Selector preserves automatic/manual choice and manual desired power');
$backup=json_decode($m->ExportConfig(),true);check($backup['config']['HeatPumpCOPMethod']==='surface','Selected COP method is included in the normal backup');
$GLOBALS['authenticated']=true;
check(response($m,'POST',[],['control'=>'HeatPumpCOPMethod','value'=>'measured'])['status']===200&&$m->properties['HeatPumpCOPMethod']==='measured','Protected page can select measured points');
check(response($m,'POST',[],['control'=>'HeatPumpCOPMethod','value'=>'surface'])['status']===200&&$m->properties['HeatPumpCOPMethod']==='surface','Protected page can select surface mode');
foreach(['unknown',true,1,[]] as $bad)check(response($m,'POST',[],['control'=>'HeatPumpCOPMethod','value'=>$bad])['status']===422,'Invalid selector value is rejected');
check(response($m,'POST',[],['control'=>'HeatPumpCOPMethod','value'=>'measured'],['HTTP_X_HEATING_CSRF'=>'bad'])['status']===403,'COP method cannot bypass CSRF');
$GLOBALS['authenticated']=false;check(response($m,'POST',[],['control'=>'HeatPumpCOPMethod','value'=>'measured'])['status']===401,'COP method cannot bypass passkey authentication');
$GLOBALS['authenticated']=true;$m->attributes['HeatPumpTestState']=json_encode(['schema'=>1,'phase'=>'reboot_wait']);
check(response($m,'POST',[],['control'=>'HeatPumpCOPMethod','value'=>'measured'])['status']===422&&$m->properties['HeatPumpCOPMethod']==='surface','Active/paused test prevents a method change');
$m->attributes['HeatPumpTestState']='';$m->properties['DryRun']=true;
check(response($m,'POST',[],['control'=>'HeatPumpCOPMethod','value'=>'measured'])['status']===422,'Dry-run page remains read-only');
echo 'PASS: '.($checks-$startChecks)." COP surface, method selection, threshold and control checks\n";
