<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
class EconomicHeating extends HeatingControl {public int $clock=1791962400;protected function sourceClock():int{return $this->clock;}}
$checks=0;function check($ok,$why){global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
$seed=HeatPumpMatrix::validate(HeatPumpMatrix::seed());
check(count($seed)===8,'All reviewed steps retained');
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
check(HeatPumpMatrix::choose($seed,12,23,23,5,5,90,1500,3)===null,'Changed airflow cannot silently reuse old COP');
check(HeatPumpMatrix::choose($seed,-21,23,23,5,5,90,2000,3)===null,'Manufacturer lower boundary enforced');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,16,23,23,5,5,90,2000,3),'Outside air 16.0°C has no tested point within ±3.0°C'),'Outside mismatch states current temperature and tolerance');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,10,10,5,5,90,2000,3),'Intake air 10.0°C'),'Intake mismatch states the current intake');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,23,23,5,5,70,2000,3),'Requested fan speed 70%'),'Fan mismatch states requested and tested settings');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,23,23,5,5,90,1500,3),'Configured airflow 1500'),'Airflow mismatch explains the rejected calibration');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,12,23,50,5,5,90,2000,3),'required supply ≥55.0°C'),'Supply mismatch states the target-based requirement');
check(str_contains(HeatPumpMatrix::unavailableReason($seed,-21,23,23,5,5,90,2000,3),'supported operating range'),'Operating boundary is distinguished from missing tests');
$other=$seed[2];$other['pointID']='cold';$other['outside']=2;
check(count(HeatPumpMatrix::merge($seed,[$other]))===9,'New temperature point preserves existing measurements');
$legacy=$seed;$legacy[0]['electricalMethod']='old-hp-circuit';
try{HeatPumpMatrix::validate($legacy);check(false,'Legacy circuit provenance rejected');}catch(InvalidArgumentException $e){check(true,'Legacy circuit provenance rejected');}
try{HeatPumpMatrix::counterAt([['TimeStamp'=>0,'Value'=>100],['TimeStamp'=>60,'Value'=>99]],30);check(false,'Counter reset rejected');}catch(RuntimeException $e){check(true,'Counter reset rejected');}
try{HeatPumpMatrix::counterAt([['TimeStamp'=>0,'Value'=>100],['TimeStamp'=>100,'Value'=>200]],30);check(false,'Long counter gap rejected');}catch(RuntimeException $e){check(true,'Long counter gap rejected');}
check(HeatPumpMatrix::counterAt([['TimeStamp'=>0,'Value'=>100],['TimeStamp'=>60,'Value'=>160]],30)===130.0,'Counter boundaries interpolated');
function fixture():EconomicHeating {
 $m=heatingFixture(EconomicHeating::class);$m->properties['ElectricityPriceInstanceID']=20;
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
check(count(webInvoke($m,'measuredMatrix'))===8,'Existing wrong-circuit model does not overwrite house-meter calibration');
$m=fixture();$m->GetConfigurationForm();$m->ExportConfig();check(count(webInvoke($m,'measuredMatrix'))===8,'Form and backup retain populated matrix');
$GLOBALS['authenticated']=true;$state=json_decode(response($m,'GET',['view'=>'state'])['body'],true);
check(count($state['heatPumpTest']['matrixPoints'])===8,'Protected dashboard exposes full matrix');
echo "PASS: $checks measured economics / integration checks\n";
