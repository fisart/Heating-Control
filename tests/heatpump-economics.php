<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
class EconomicHeating extends HeatingControl {public int $clock=1791962400;protected function sourceClock():int{return $this->clock;}}
$checks=0;function check($ok,$why){global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
// Historical regression fixture: new default expansion is covered separately.
$seed=HeatPumpMatrix::validate(json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-calibration-20261016.json'),true)['points']);
check(count($seed)===30,'All reviewed steps retained');
$point=HeatPumpMatrix::choose($seed,12,23,23,5,5,90,2000,3);
check($point['powerPercent']===50,'50% is highest COP among stable supported points');
check(abs($point['estimatedSystemCOP']-3.6123684283)<.00001,'House-counter matched full-period COP reproduced');
check(!$seed[1]['usable']&&$seed[1]['estimatedSystemCOP']>$point['estimatedSystemCOP'],'Unsettled 40% cannot win even with higher measured COP');
$e=HeatPumpMatrix::economics($point,10.48,100,25,2,26,2);
check(abs($e['breakEvenEuro']-.3785762113)<.00001,'Break-even derives from gas tariff and COP, without arbitrary reference threshold');
check(abs($e['exitEuro']-.2839321585)<.00001&&abs($e['entryEuro']-.2639321585)<.00001,'Margin once and hysteresis below economic ceiling');
check($e['source']===1,'Enter heat pump below entry threshold');
check(HeatPumpMatrix::economics($point,10.48,100,25,2,27,2)['source']===2,'Gas remains gas inside hysteresis band');
check(HeatPumpMatrix::economics($point,10.48,100,25,2,27,1)['source']===1,'Heat pump remains on inside hysteresis band');
check(HeatPumpMatrix::economics($point,10.48,100,25,2,$e['exitCt'],1)['source']===2,'Equality at exit goes to gas');
check(abs(HeatPumpMatrix::economics($point,10.48,90,25,2,0,2)['breakEvenCt']-$e['breakEvenCt']/0.9)<.00001,'Gas efficiency explicitly changes comparison');
check(HeatPumpMatrix::choose($seed,2,23,23,5,5,90,2000,3)===null,'No outside-temperature extrapolation');
check(HeatPumpMatrix::choose($seed,12,10,10,5,5,90,2000,3)===null,'Warm-house calibration cannot invent holiday intake performance');
check(HeatPumpMatrix::choose($seed,12,23,23,5,5,70,2000,3)===null,'Fan context must match');
check(HeatPumpMatrix::choose($seed,12,23,23,5,5,90,1500,3,999)['pointID']===$point['pointID'],'Normal measured selection ignores airflow and excluded-flap matching');
check(HeatPumpMatrix::choose($seed,-21,23,23,5,5,90,2000,3)===null,'Manufacturer lower boundary enforced');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,19,23,23,5,5,90,2000,3),'Outside air 19.0°C has no tested point within ±3.0°C'),'Outside mismatch states current temperature and tolerance');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,10,10,5,5,90,2000,3),'Intake air 10.0°C'),'Intake mismatch states the current intake');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,23,23,5,5,70,2000,3),'Requested fan speed 70%'),'Fan mismatch states requested and tested settings');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,23,60,5,5,90,1500,3,999),'air-supply requirement'),'Failure reason proceeds to supply rather than airflow or flap matching');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,23,50,5,5,90,2000,3),'required supply ≥55.0°C'),'Supply mismatch states the target-based requirement');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,-21,23,23,5,5,90,2000,3),'supported operating range'),'Operating boundary is distinguished from missing tests');
$other=$seed[2];$other['pointID']='cold';$other['outside']=2;
check(count(HeatPumpMatrix::merge($seed,[$other]))===31,'New temperature point preserves existing measurements');
$legacy=$seed;$legacy[0]['electricalMethod']='old-hp-circuit';
try{HeatPumpMatrix::validate($legacy);check(false,'Legacy circuit provenance rejected');}catch(InvalidArgumentException $e){check(true,'Legacy circuit provenance rejected');}
try{HeatPumpMatrix::counterAt([['TimeStamp'=>0,'Value'=>100],['TimeStamp'=>60,'Value'=>99]],30);check(false,'Counter reset rejected');}catch(RuntimeException $e){check(true,'Counter reset rejected');}
try{HeatPumpMatrix::counterAt([['TimeStamp'=>0,'Value'=>100],['TimeStamp'=>100,'Value'=>200]],30);check(false,'Long counter gap rejected');}catch(RuntimeException $e){check(true,'Long counter gap rejected');}
check(HeatPumpMatrix::counterAt([['TimeStamp'=>0,'Value'=>100],['TimeStamp'=>60,'Value'=>160]],30)===130.0,'Counter boundaries interpolated');
function fixture():EconomicHeating {
 $m=heatingFixture(EconomicHeating::class);$m->properties['HeatPumpMeasuredMatrix']=json_encode(json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-calibration-20261016.json'),true)['points']);$m->properties['ElectricityPriceInstanceID']=20;
 $GLOBALS['instanceModules'][20]='{5C0EEDE9-5C09-4F18-BCD1-CE65CFA7B1B7}';
 $GLOBALS['instanceIdents'][20]=['RecommendationJSON'=>82000,'PriceQuoteJSON'=>82001];
 $GLOBALS['variables'][82000]=['VariableType'=>3,'value'=>json_encode(['schema'=>1,'valid'=>false,'source'=>2,'powerPercent'=>0,'reason'=>'Legacy weather says gas'])];
 $GLOBALS['variables'][82001]=['VariableType'=>3,'value'=>json_encode(['schema'=>1,'enabled'=>true,'valid'=>true,'updatedAt'=>$m->clock,'validUntil'=>$m->clock+900,'priceCt'=>20,'gasPriceCt'=>10.48,'safetyMarginPercent'=>25,'hysteresisCt'=>2])];
 $GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=12.0;
 $GLOBALS['variables'][$m->properties['IncomingAirTempID']]['value']=23.0;
 $GLOBALS['variables'][$m->properties['FanDesiredSpeedID']]['value']=90;
 $m->values['AutomaticSourceSelection']=true;return $m;
}
$m=fixture();$m->properties['HeatPumpUseTestModel']=false;$reason='';$power=(new ReflectionMethod(HeatingControl::class,'measuredEconomicChoice'))->invokeArgs($m,[&$reason]);
check($power===50,'Retired legacy switch cannot bypass the measured model');
// Exercise sourceChoice itself with gas and heat-pump source mappings and mirrored variables.
$method=new ReflectionMethod(HeatingControl::class,'sourceChoice');$queue=[];$choice=$method->invokeArgs($m,[2,&$queue]);
check($choice===[1,50]&&$queue[$m->properties['OperatingModeID']]===1,'Actual source path uses measured threshold and mirrors HP mode');
$q=json_decode($GLOBALS['variables'][82001]['value'],true);$q['priceCt']=40;$GLOBALS['variables'][82001]['value']=json_encode($q);$queue=[];$choice=$method->invokeArgs($m,[1,&$queue]);
check($choice===[2,0]&&$m->values['HeatPumpBreakEvenEuro']>0,'Expensive quote selects gas while preserving reviewable calculated threshold');
$q['priceCt']=20;$q['validUntil']=$m->clock;$GLOBALS['variables'][82001]['value']=json_encode($q);$queue=[];$choice=$method->invokeArgs($m,[1,&$queue]);
check($choice===[2,0]&&$m->values['HeatPumpBreakEvenEuro']===0.0,'Expired quote clears thresholds and falls back to gas');
$m=fixture();$GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=2.0;$queue=[];$choice=$method->invokeArgs($m,[1,&$queue]);
check($choice===[2,0]&&str_contains($m->values['HeatPumpEconomicsJSON'],'No qualified point'),'Untested weather selects gas with test-needed explanation');
$fallback=json_decode($m->values['HeatPumpEconomicsJSON'],true);
check($fallback['outside']===2&&$fallback['incoming']===23&&$fallback['fanSpeed']===90,'Unsupported weather retains current environmental inputs');
check(str_contains($fallback['reason'],'Outside air 2.0°C')&&str_contains($fallback['reason'],'±3.0°C'),'Fallback snapshot names the failed matching criterion');
check(!isset($fallback['point'])&&!isset($fallback['breakEvenCt']),'Unsupported conditions never expose a made-up threshold or COP');
$m=fixture();$m->attributes['HeatPumpPerformanceModel']=json_encode(['schema'=>1,'points'=>[['powerPercent'=>100,'estimatedSystemCOP'=>9]]]);
check(count(webInvoke($m,'measuredMatrix'))===30,'Existing wrong-circuit model does not overwrite house-meter calibration');
$m=fixture();$m->GetConfigurationForm();$m->ExportConfig();check(count(webInvoke($m,'measuredMatrix'))===30,'Form and backup retain populated matrix');
$GLOBALS['authenticated']=true;$state=json_decode(response($m,'GET',['view'=>'state'])['body'],true);
check(count($state['heatPumpTest']['matrixPoints'])===30,'Protected dashboard exposes full matrix');
$afternoon=HeatPumpMatrix::choose($seed,15.6,23.7,23,5,5,90,2000,3);
check($afternoon['powerPercent']===40&&abs($afternoon['estimatedSystemCOP']-3.5682758776)<0.00001,'Explicitly reviewed afternoon 40% point is the best matching COP');
$prices=HeatPumpMatrix::economics($afternoon,10.48,100,5,1,30,2);
check(abs($prices['breakEvenCt']-37.3955311977)<0.00001&&abs($prices['entryCt']-34.5257546378)<0.00001&&abs($prices['exitCt']-35.5257546378)<0.00001,'Reviewed 40% prices use current displayed tariff assumptions');
$original=$afternoon;$original['pointID']=$afternoon['reviewedSourcePointID'];$original['stable']=false;$original['usable']=false;unset($original['reviewNote']);
$reviewed=HeatPumpMatrix::applyPointReview($original);
check($reviewed['usable']&&$reviewed['originalStable']===false&&$original['stable']===false,'Reviewed overlay preserves the original exclusion');
foreach([['pointID'=>'other-run-40'],['estimatedSystemCOP'=>4.0],['airRise'=>30.0],['electricalMethod'=>'old-hp-circuit']] as $bad)
 check(HeatPumpMatrix::applyPointReview(array_replace($original,$bad))['usable']===false,'Review cannot promote a different run, changed measurement or wrong electrical source');
$m=fixture();$m->properties['HeatPumpMeasuredMatrix']=json_encode(array_slice($seed,0,8));
$rawReport=json_encode(['schema'=>1,'modelQualified'=>true,'points'=>[$original]]);$m->attributes['HeatPumpTestLastReport']=$rawReport;
$matrix=webInvoke($m,'measuredMatrix');
check(count($matrix)===9&&HeatPumpMatrix::choose($matrix,15.6,23.7,23,5,5,90,2000,3)['powerPercent']===40,'Existing custom matrix recovers the owner-reviewed point from its qualified completed report');
check($m->attributes['HeatPumpTestLastReport']===$rawReport,'Matrix lookup does not rewrite original report data');
$GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=15.6;$GLOBALS['variables'][$m->properties['IncomingAirTempID']]['value']=23.7;
$queue=[];$choice=$method->invokeArgs($m,[2,&$queue]);
check($choice===[1,40],'Automatic integration selects reviewed 40% under matching conditions');
$view=webInvoke($m,'heatPumpTestView');check($view['points'][0]['usable']&&str_contains($view['points'][0]['reviewNote'],'exchanger'),'Protected test view identifies reviewed acceptance');
$m->attributes['HeatPumpTestLastReport']=json_encode(['schema'=>1,'modelQualified'=>false,'points'=>[$original]]);
check(count(webInvoke($m,'measuredMatrix'))===8,'Unqualified or cancelled reports do not auto-import reviewed points');
$night=HeatPumpMatrix::choose($seed,5.1,23.4,23,5,5,90,2000,3);
check($night['powerPercent']===50&&abs($night['estimatedSystemCOP']-3.2380217954)<0.00001,'Cold context selects reviewed 50% rather than warm-weather COP');
$prices=HeatPumpMatrix::economics($night,10.48,100,5,1,30,2);
check(abs($prices['entryCt']-31.2377449951)<0.00001&&abs($prices['exitCt']-32.2377449951)<0.00001,'Cold 50% thresholds reproduced');
$nightOriginals=[];
foreach(array_slice($seed,16,6) as $row){$row['pointID']=$row['reviewedSourcePointID'];$row['runID']=substr($row['pointID'],0,strrpos($row['pointID'],'-'));$row['stable']=$row['originalStable'];$row['usable']=$row['originalUsable'];$row['stability']=$row['reviewedStability'];unset($row['reviewNote'],$row['reviewedPartialRunReason']);$nightOriginals[]=$row;}
check(HeatPumpMatrix::applyPointReview($nightOriginals[2])['usable']===true,'Cold 50% exact measurement has a reviewed drift acceptance');
check(HeatPumpMatrix::applyPointReview($nightOriginals[5])['usable']===false,'Defrost-like 80% transient remains excluded');
$bad=$nightOriginals[2];$bad['stability']['powerHalfWindowFraction']=0.8;
check(HeatPumpMatrix::applyPointReview($bad)['usable']===false,'Review cannot mask altered or failed electrical stability');
$m=fixture();$m->properties['HeatPumpMeasuredMatrix']=json_encode(array_slice($seed,0,8));
$nightReport=['schema'=>1,'runID'=>$nightOriginals[0]['runID'],'complete'=>false,'reason'=>'Configuration changed or Symcon restarted','modelQualified'=>false,'points'=>$nightOriginals];
$rawNight=json_encode($nightReport);$m->attributes['HeatPumpTestLastReport']=$rawNight;$matrix=webInvoke($m,'measuredMatrix');
check(count($matrix)===14&&HeatPumpMatrix::choose($matrix,5.1,23.4,23,5,5,90,2000,3)['powerPercent']===50,'Reviewed completed cold steps are recovered despite the interrupted run status');
check($m->attributes['HeatPumpTestLastReport']===$rawNight,'Interrupted source report stays byte-for-byte unchanged');
foreach([['reason'=>'Cancelled by user'],['runID'=>'another-run'],['schema'=>2]] as $bad){$m->attributes['HeatPumpTestLastReport']=json_encode(array_replace($nightReport,$bad));check(count(webInvoke($m,'measuredMatrix'))===8,'Different interrupted reasons, run identities or schemas cannot inherit this review');}
$m->attributes['HeatPumpTestLastReport']=$rawNight;
$GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=5.1;$GLOBALS['variables'][$m->properties['IncomingAirTempID']]['value']=23.4;
$queue=[];$choice=$method->invokeArgs($m,[2,&$queue]);check($choice===[1,50],'Actual automatic selection uses the cold reviewed point');
check(HeatPumpMatrix::choose(array_slice($seed,0,22),5.1,10,10,5,5,90,2000,3)===null,'Historical warm-intake points alone cannot qualify a cold intake');
$colder=HeatPumpMatrix::choose($seed,5.1,10,10,5,5,90,2000,3);
check($colder!==null&&$colder['electricalMethod']==='dedicated-heatpump-import-counter'&&abs($colder['incoming']-10)<=3&&abs($colder['outside']-5.1)<=3,'New colder-inlet measurements qualify only within the unchanged three-degree tolerance');
echo "PASS: $checks measured economics / integration checks\n";

