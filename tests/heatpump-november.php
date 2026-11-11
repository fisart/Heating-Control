<?php
declare(strict_types=1);
require __DIR__.'/heatpump-economics.php';
$before=$checks;
$add=json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-calibration-additions-20261111.json'),true,64,JSON_THROW_ON_ERROR);
$rows=HeatPumpMatrix::validate(HeatPumpMatrix::seed());
check(count($rows)===51&&count(HeatPumpSurface::observations())===47,'43 previous rows plus all eight November observations are bundled');
$originals=[];
foreach($add['points'] as $review) {
    $original=array_replace($review,$review['reviewedOriginal']);unset($original['reviewedOriginal'],$original['reviewNote']);
    $originals[]=$original;
    $accepted=HeatPumpMatrix::applyPointReview($original);
    check($accepted===$review,'Exact completed measurement receives its documented review');
    check(abs($review['estimatedHeatKWh']-$review['estimatedHeatKW']*$review['measurementSeconds']/3600)<1e-9,'Heat and electricity share retained duration');
    check($review['target']===10&&$review['usable'],'10C unoccupied target does not reject a calibration point');
    $tampered=$original;$tampered['netElectricityKWh']+=.01;
    check(HeatPumpMatrix::applyPointReview($tampered)===$tampered,'Changed counter energy cannot inherit a review');
    $future=$original;$future['runID']='future-run';
    check(HeatPumpMatrix::applyPointReview($future)===$future,'Other runs cannot inherit the reviewed acceptance');
}
$ninety=$add['points'][6];
check($ninety['powerPercent']===90&&$ninety['originalStable']===false&&$ninety['usable'],'90 percent is included with original stability warning retained');
check($add['points'][4]['excludedSeconds']===686&&$add['points'][7]['excludedSeconds']===751,'Both recorded interruption brackets remain excluded');
$m=fixture();$m->attributes['HeatPumpPerformanceModel']=json_encode(['schema'=>2,'points'=>array_slice($originals,0,7)]);
$report=json_encode(['schema'=>1,'complete'=>true,'modelQualified'=>true,'runID'=>$ninety['runID'],'points'=>$originals]);
$m->attributes['HeatPumpTestLastReport']=$report;
$effective=webInvoke($m,'measuredMatrix');
foreach($add['points'] as $p)check(array_values(array_filter($effective,static fn($r)=>$r['pointID']===$p['pointID']))[0]['usable'],'Stored pre-review model cannot hide an accepted November step');
check($m->attributes['HeatPumpTestLastReport']===$report,'Original report remains unchanged');
$GLOBALS['authenticated']=true;$reply=response($m,'GET',['view'=>'heatpump-surface']);
check($reply['status']===200,'Passkey-authenticated session can load charts');
$chart=json_decode($reply['body'],true,64,JSON_THROW_ON_ERROR);
check(count($chart['points'])===47&&count($chart['grid'])===count($chart['temperatures']),'Chart payload uses current observations and bounded surface grid');
check(count(array_filter($chart['points'],static fn($p)=>$p['runID']===$chart['latestRunID']))===8,'Chart includes all eight November points');
$GLOBALS['authenticated']=false;
check(response($m,'GET',['view'=>'heatpump-surface'])['status']===401,'Charts retain passkey protection');
echo 'PASS: '.($checks-$before)." November acceptance, model recovery, provenance and chart checks\n";
