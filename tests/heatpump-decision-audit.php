<?php
declare(strict_types=1);
require __DIR__.'/../HeatingControl/HeatPumpDecisionAudit.php';
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
$t=1792706400;
function decision(int $t,int $previous,float $price):string{return json_encode(['schema'=>1,'updatedAt'=>$t,'validUntil'=>$t+900,'valid'=>true,
    'previousEconomicSource'=>$previous,'currentPriceCt'=>$price,'entryCt'=>30.,'exitCt'=>31.,'point'=>['estimatedSystemCOP'=>3.5],'powerPercent'=>50,'reason'=>'Recorded model and thresholds']);}
$s=['selectedMode'=>[$t=>1.],'fanOn'=>[$t=>true],'automatic'=>[$t=>true],'decision'=>[$t=>decision($t,1,30.5)]];
$review=static fn($s,$ex=[])=>HeatPumpDecisionAudit::review($t,$t+900,$s,$ex,1,2);
$r=$review($s);check($r[0]['expectedSource']===1&&$r[0]['status']==='Matches recorded thresholds','Hysteresis allows an existing heat pump to remain selected between entry and exit');
$s['decision'][$t]=decision($t,2,30.5);$r=$review($s);
check($r[0]['expectedSource']===2&&$r[0]['status']==='Selection differs from recorded thresholds','Gas remains selected in the band when the prior economic choice was gas');
$d=json_decode($s['decision'][$t],true);unset($d['previousEconomicSource']);$s['decision'][$t]=json_encode($d);$r=$review($s);
check($r[0]['expectedSource']===null&&$r[0]['status']==='Unverifiable','Legacy snapshots without prior state do not invent a hysteresis decision');
$s['automatic'][$t]=false;$r=$review($s);check($r[0]['status']==='Manual','Manual selections are not judged as automatic choices');
$s['automatic']=[];$r=$review($s);check($r[0]['status']==='Unverifiable','Missing historical automatic mode is not replaced with its current value');
$s['automatic'][$t]=true;$s['decision'][$t]=json_encode(['schema'=>1,'updatedAt'=>$t,'valid'=>false,'source'=>2,'reason'=>'Current quote unavailable']);$s['selectedMode'][$t]=2.;$r=$review($s);
check($r[0]['status']==='Recorded gas fallback','Missing-price fallback is not rejudged using a subsequently repaired price');
$s['decision']=[$t-1800=>decision($t-1800,1,20)];$r=$review($s);check($r[0]['status']==='Unverifiable','Expired decision context is not a verified choice');
$s['fanOn'][$t]=false;check($review($s)===[],'Fan-OFF choices are outside this report');
$s['fanOn'][$t+300]=true;$s['decision'][$t+300]=decision($t+300,2,35.);$r=$review($s);
check(count($r)===1&&$r[0]['rowType']==='Fan-on start'&&$r[0]['expectedSource']===2,'Fan startup captures selection context even without a source change');
check($review($s,[['from'=>$t,'to'=>$t+900]])===[],'Intentional tests are excluded from decision review too');
echo "PASS: $checks archived decision checks\n";
