<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
$checks=0;
function check(bool $ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function near(float $a,float $b): bool {return abs($a-$b)<1e-7;}
$t=strtotime('2026-10-23T10:00:00+02:00');
$settings=['counterScale'=>1.,'airflowM3h'=>2000.,'fanCommand'=>90,'temperatureMaxAge'=>0,'gasEfficiencyPercent'=>90.,'fallbackGasPriceCt'=>null];
function series(int $t,int $seconds=900,float $energy=.5): array {
    $s=['hpOn'=>[$t=>true],'heating'=>[$t=>true],'gasOn'=>[$t=>false],'fanOn'=>[$t=>true],
        'fanSpeed'=>[$t=>90.],'incoming'=>[$t=>20.],'outgoing'=>[$t=>30.],'counter'=>[]];
    for($i=0;$i<=$seconds;$i+=60)$s['counter'][$t+$i]=1000+$energy*$i/$seconds;
    return $s;
}
function price(int $t,float $ct=20.): array {return ['start'=>$t,'end'=>$t+900,'price'=>$ct,'verified'=>true];}
$gas=[['start'=>0,'end'=>PHP_INT_MAX,'unitGrossCt'=>10.]];
$s=series($t);$r=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings);$v=$r['totals'];
check(near($v['electricityKWh'],.5)&&near($v['heatPumpCostEuro'],.1),'Measured meter consumption is priced, without a household/standby subtraction');
check(near($v['netHeatKWh'],1.675)&&near($v['achievedCOP'],3.35),'Recorded temperature rise gives integrated net air heat and achieved COP');
check(near($v['equivalentGasCostEuro'],1.675/.9*.1),'Equivalent gas uses useful heat, the configured efficiency and gas unit rate');
check(near($v['savingEuro'],1.675/.9*.1-.1)&&near($v['savingPercent'],(1.675/.9*.1-.1)/(1.675/.9*.1)*100),'Savings use comparable cost and gas-cost denominator');
check($v['completeComparison']&&near($v['comparisonCoveragePercent'],100.),'A fully covered period is complete');

$s=series($t,1800,2.);for($i=0;$i<=1800;$i+=60)$s['counter'][$t+$i]=1000+($i<=900?.5*$i/900:.5+1.5*($i-900)/900);
$v=HeatPumpCostAnalysis::day($t,$t+1800,$s,[price($t,20),price($t+900,40)],$gas,$settings)['totals'];
check(near($v['heatPumpCostEuro'],.7),'Variable tariffs are energy weighted, not an unweighted average price');
$s=series($t);$s['outgoing'][$t+600]=15.;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['netHeatKWh'],.000335*2000*(10*600-5*300)/3600),'Cooling during defrost remains signed, reducing useful delivered heat');
check(near($v['heatPumpCostEuro'],.1)&&near($v['electricityKWh'],.5),'Defrost electricity is retained in full');
$s=series($t);$s['hpOn'][$t+600]=false;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['netHeatKWh'],1.675),'Fan run-on retains identifiable heat-pump residual heat');
$s['gasOn'][$t+720]=true;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['netHeatKWh'],1.675*720/900),'Switching to gas ends attribution of air heat to the heat pump');
$s=series($t);$s['hpOn'][$t]=false;$s['fanOn'][$t]=false;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['heatPumpCostEuro'],.1)&&$v['equivalentGasCostEuro']===0.&&near($v['savingEuro'],-.1),'Standby electricity remains a cost even without delivered heat; no fan cost is added');
$s=series($t);$s['fanSpeed'][$t+300]=70.;$s['fanSpeed'][$t+600]=90.;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check($v['matchedSeconds']===600&&near($v['comparisonHeatPumpCostEuro'],.1*2/3)&&near($v['heatPumpCostEuro'],.1),'Unknown airflow excludes only unmatched comparison time while full electricity cost remains visible');
check(near($v['equivalentGasCostEuro'],1.675*2/3/.9*.1),'Gas and heat-pump figures always use identical covered intervals');
$s=series($t);$s['incoming']=[];$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check($v['comparisonHeatPumpCostEuro']===null&&$v['savingEuro']===null&&near($v['heatPumpCostEuro'],.1),'Missing thermal data does not become zero gas cost or erase measured electricity cost');
$s=series($t);$s['outgoing'][$t]=15.;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check($v['equivalentGasCostEuro']===null&&$v['savingEuro']===null&&$v['netHeatKWh']<0,'Defrost-only net cooling cannot be presented as gas savings');
$s=series($t);$bad=$s;for($i=480;$i<=900;$i+=60)$bad['counter'][$t+$i]-=500;
$r=HeatPumpCostAnalysis::day($t,$t+900,$bad,[price($t)],$gas,$settings);
check(!$r['totals']['completeComparison']&&isset($r['issues']['Meter counter reset or invalid reading']),'Counter reset is excluded rather than reconstructed');
$bad=$s;for($i=120;$i<660;$i+=60)unset($bad['counter'][$t+$i]);
$r=HeatPumpCostAnalysis::day($t,$t+900,$bad,[price($t)],$gas,$settings);
check(isset($r['issues']['Meter logging gap exceeds 180 seconds'])&&$r['totals']['heatPumpCostEuro']===null,'A logging gap with nonzero energy cannot fabricate tariff timing');
$r=HeatPumpCostAnalysis::day($t,$t+900,$s,[],$gas,$settings);
check($r['totals']['heatPumpCostEuro']===null&&$r['totals']['matchedSeconds']===0,'Current electricity price never replaces missing historical prices');
foreach([0.,-10.] as $ct){$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t,$ct)],$gas,$settings)['totals'];check(near($v['heatPumpCostEuro'],.5*$ct/100),'Zero and negative historical tariffs remain valid');}
$p=price($t);$p['verified']=false;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[$p],$gas,$settings)['totals'];
check($v['provisional']&&$v['unverifiedPriceSeconds']===900,'Unverified historical prices are explicitly provisional');
$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],[],array_replace($settings,['fallbackGasPriceCt'=>12.]))['totals'];
check($v['provisional']&&$v['assumedGasPriceSeconds']===900&&near($v['equivalentGasCostEuro'],1.675/.9*.12),'Configured gas tariff fallback is explicitly flagged as an assumption');
$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],[],$settings)['totals'];
check($v['equivalentGasCostEuro']===null,'Absent gas tariffs are not invented');
$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,array_replace($settings,['temperatureMaxAge'=>180]))['totals'];
check($v['heatSeconds']===180,'A positive configured temperature-age limit is respected');
$s=series($t);$s['heating'][$t]=false;$r=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings);
check($r['totals']['savingEuro']===null&&near($r['totals']['heatPumpCostEuro'],.1),'Cooling-mode electricity is still measured but cannot masquerade as an equivalent heating saving');
$s=series($t);foreach(['heating','gasOn','fanOn'] as $k)$s[$k]=[$t-7200=>array_values($s[$k])[0]];$s['hpOn']=[$t-600=>true,$t-60=>false];$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['netHeatKWh'],1.675),'Pre-period context can identify residual heat at the period boundary');

// The shared fan can continue independently of both heat sources. Once a
// stopped heat pump's positive run-on has ended, later fan-only cooling is
// not defrost and must never cancel its earlier delivered heat.
$duration=15600;$s=series($t,$duration);
$s['hpOn'][$t+1200]=false;$s['incoming']=[$t=>23.];$s['outgoing']=[$t=>38.,$t+1200=>22.];
for($i=0;$i<=$duration;$i+=60)$s['counter'][$t+$i]=1000+min($i,1200)*3/3600+max(0,$i-1200)*.01/3600;
$prices=[];for($at=$t;$at<$t+$duration;$at+=900)$prices[]=price($at,30.);
$actual=HeatPumpCostAnalysis::day($t,$t+$duration,$s,$prices,[['start'=>0,'end'=>PHP_INT_MAX,'unitGrossCt'=>10.48]],array_replace($settings,['gasEfficiencyPercent'=>100.]));
check(near($actual['totals']['netHeatKWh'],3.35),'Four hours of independent fan cooling cannot cancel the preceding 20 minutes of heat-pump output');
check(near($actual['totals']['equivalentGasCostEuro'],.35108)&&near($actual['totals']['heatPumpCostEuro'],.312),'Idle-airflow fix restores equivalent gas cost without changing measured electricity cost');
check(near($actual['totals']['matchedHeatingHeatKWh'],3.35)&&$actual['totals']['matchedRunOnHeatKWh']===0.&&$actual['totals']['matchedCoolingHeatKWh']===0.,'Heat audit exposes real enabled heating without a false idle-cooling deduction');
check(near($actual['totals']['matchedEnabledCostEuro'],.3)&&near($actual['totals']['matchedOffCostEuro'],.012),'Off-period metered consumption is shown separately without hiding its actual cost');
$s=series($t);$s['outgoing'][$t+600]=15.;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['matchedHeatingHeatKWh'],6.7/6)&&near($v['matchedCoolingHeatKWh'],3.35/12)&&near($v['matchedNetHeatKWh'],$v['matchedHeatingHeatKWh']+$v['matchedRunOnHeatKWh']-$v['matchedCoolingHeatKWh']),'Active heating/cooling audit reconciles exactly to net heat, including defrost');
$s=series($t);$s['hpOn'][$t+600]=false;$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['matchedRunOnHeatKWh'],6.7/12),'Positive residual recovery stays separately visible after shutdown');
$s=series($t);$s['hpOn'][$t+300]=false;$s['outgoing'][$t+450]=20.;$s['outgoing'][$t+600]=24.;
$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['netHeatKWh'],.000335*2000*10*450/3600),'Unrelated later warming cannot revive a heat-pump run-on that already reached neutral');
$s=series($t);$s['hpOn'][$t+300]=false;$s['outgoing'][$t+450]=15.;$s['hpOn'][$t+600]=true;
$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check(near($v['netHeatKWh'],.000335*2000*(10*450-5*300)/3600),'A new enabled heating cycle still includes real negative heat, even after an idle-cooling period');
$s=series($t);foreach(['heating','gasOn','fanOn','fanSpeed','incoming'] as $k)$s[$k]=[$t-7200=>array_values($s[$k])[0]];
$s['hpOn']=[$t-600=>true,$t-300=>false];$s['outgoing']=[$t-7200=>30.,$t-180=>15.,$t=>24.];
$v=HeatPumpCostAnalysis::day($t,$t+900,$s,[price($t)],$gas,$settings)['totals'];
check($v['netHeatKWh']===0.,'Run-on ending before the chosen period is respected when replaying source context');

$now=strtotime('2026-11-01T12:00:00+01:00');
foreach(['2026-03-29'=>23,'2026-10-25'=>25] as $date=>$hours){[$a,$b]=HeatPumpCostAnalysis::dates($date,$date,$now);check($b-$a===$hours*3600,'Berlin DST day length: '.$date);}
foreach([['2026-02-30','2026-02-30'],['2026-10-10','2026-10-01'],['2026-10-01','2026-11-01'],['2026-11-02','2026-11-02']] as [$a,$b]) {
    try{HeatPumpCostAnalysis::dates($a,$b,$now);check(false,'Invalid dates rejected');}catch(InvalidArgumentException $e){check(true,'Invalid period rejected');}
}

class CostHeating extends HeatingControl {public int $clock=1792830600;protected function sourceClock():int{return $this->clock;}}
function LBEP_GetPriceHistory($id,$from,$to): string {$GLOBALS['priceReads'][]=[$id,$from,$to];return json_encode(['schema'=>1,'intervals'=>$GLOBALS['costPrices']]);}
function costFixture(): CostHeating {
    $m=heatingFixture(CostHeating::class);$m->clock=strtotime('2026-10-24T12:00:00+02:00');$m->properties['HeatPumpTestArchiveID']=9;$m->properties['ElectricityPriceInstanceID']=77;
    $GLOBALS['instanceModules'][77]='{5C0EEDE9-5C09-4F18-BCD1-CE65CFA7B1B7}';
    $GLOBALS['instanceProperties'][77]=['GasPriceCt'=>10.,'GasHistoryTariffs'=>'[]','PriceHistoryStartDate'=>'2026-10-01'];
    $GLOBALS['costRows']=[];$GLOBALS['costPrices']=[];$GLOBALS['priceReads']=[];
    $GLOBALS['archiveReader']=static function($archive,$id,$from,$to,$limit): array {
        $rows=array_values(array_filter($GLOBALS['costRows'][$id]??[],static fn($r)=>$r['TimeStamp']>=$from&&$r['TimeStamp']<=$to));
        usort($rows,static fn($a,$b)=>$b['TimeStamp']<=>$a['TimeStamp']);return $limit?array_slice($rows,0,$limit):$rows;
    };
    $a=strtotime('2026-10-23T00:00:00+02:00');$b=$a+86400;
    foreach(['HeatPumpOnID'=>true,'HeatPumpHeatModeID'=>true,'GasPumpID'=>false,'FanOnID'=>true,'FanSpeedID'=>90.,'IncomingAirTempID'=>20.,'OutgoingAirTempID'=>30.] as $property=>$value) {
        $id=$m->properties[$property];$GLOBALS['costRows'][$id]=[['TimeStamp'=>$a-7200,'Value'=>$value]];$GLOBALS['archiveLogging'][9][$id]=true;
    }
    $id=$m->properties['HeatPumpTestDedicatedCounterID'];$GLOBALS['archiveLogging'][9][$id]=true;
    for($at=$a-180;$at<=$b+180;$at+=60)$GLOBALS['costRows'][$id][]=['TimeStamp'=>$at,'Value'=>1000+($at-$a)/3600];
    for($at=$a;$at<$b;$at+=900)$GLOBALS['costPrices'][]=price($at,20.);
    $GLOBALS['authenticated']=true;return $m;
}
$m=costFixture();$before=[$m->properties,$m->attributes,$m->values,$GLOBALS['variables'],$GLOBALS['archiveLogging']];
$report=json_decode($m->GetHeatPumpCostAnalysis('2026-10-23','2026-10-23'),true);
check(near($report['summary']['heatPumpCostEuro'],4.8)&&near($report['summary']['electricityKWh'],24.),'Public API reads the dedicated circuit counter and stored retail prices');
check(near($report['summary']['netHeatKWh'],160.8)&&near($report['summary']['equivalentGasCostEuro'],16.08),'Read-only report values agree with an independent daily heat/gas calculation');
check($report['assumptions']['fanCostIncluded']===false&&$report['assumptions']['fixedChargesIncluded']===false&&$report['assumptions']['baselineSubtracted']===false,'Report explicitly excludes fan/fixed costs and household baseline subtraction');
check($report['calculationVersion']===2&&near($report['summary']['matchedHeatingHeatKWh'],160.8),'Public report includes the corrected calculation version and reconciled heat breakdown');
check(near($report['assumptions']['gasPriceMinCt'],10.)&&near($report['assumptions']['gasPriceMaxCt'],10.),'Gas rate used by the comparison is exposed on the page');
check($before===[$m->properties,$m->attributes,$m->values,$GLOBALS['variables'],$GLOBALS['archiveLogging']]&&!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Analysis does not alter controls, settings, logging or module state');
$response=response($m,'GET',['view'=>'heatpump-costs','from'=>'2026-10-23','through'=>'2026-10-23']);
check($response['status']===200&&json_decode($response['body'],true)['schema']===1,'Protected page receives the cost analysis');
$csv=response($m,'GET',['view'=>'heatpump-costs-csv','from'=>'2026-10-23','through'=>'2026-10-23']);
check($csv['status']===200&&str_starts_with($csv['headers']['Content-Type'],'text/csv')&&str_contains($csv['body'],'2026-10-23'),'CSV endpoint exports period figures with a safe dated filename');
check(str_contains($csv['body'],'Positive run-on heat kWh')&&str_contains($csv['body'],'Electricity cost while off EUR')&&str_contains($csv['body'],'Calculation version'),'CSV carries the heat and off-period electricity audit needed to verify a suspicious comparison');
$GLOBALS['authenticated']=false;
check(response($m,'GET',['view'=>'heatpump-costs','from'=>'2026-10-23','through'=>'2026-10-23'])['status']===401,'Cost report cannot bypass passkey authentication');
check(response($m,'GET',['view'=>'heatpump-costs-csv','from'=>'2026-10-23','through'=>'2026-10-23'])['status']===401,'CSV cannot bypass passkey authentication');
$GLOBALS['authenticated']=true;
check(response($m,'GET',['view'=>'heatpump-costs','from'=>['bad'],'through'=>'2026-10-23'])['status']===422,'Malformed period input is rejected');
check(response($m,'GET',['view'=>'heatpump-costs','from'=>'2026-10-01','through'=>'2026-11-02'])['status']===422,'Oversized period is rejected before archive reads');
$m->properties['Enabled']=false;$m->properties['DryRun']=true;
check(response($m,'GET',['view'=>'heatpump-costs','from'=>'2026-10-23','through'=>'2026-10-23'])['status']===200,'Read-only analysis remains available with heating disabled');
$m=costFixture();$GLOBALS['archiveLogging'][9][$m->properties['IncomingAirTempID']]=false;
$r=json_decode($m->GetHeatPumpCostAnalysis('2026-10-23','2026-10-23'),true);
check($r['summary']['savingEuro']===null&&near($r['summary']['heatPumpCostEuro'],4.8)&&count($r['warnings'])>0,'Missing logged temperatures explain unavailable savings while preserving measured electricity cost');
$m=costFixture();$GLOBALS['costPrices'][0]['price']=null;
$r=json_decode($m->GetHeatPumpCostAnalysis('2026-10-23','2026-10-23'),true);
check(!$r['summary']['completeComparison']&&$r['summary']['matchedSeconds']===86400-900,'One missing tariff interval produces an explicit matched-time subtotal');
$m=costFixture();$id=$m->properties['IncomingAirTempID'];$GLOBALS['costRows'][$id][]=['TimeStamp'=>strtotime('2026-10-23T01:00:00+02:00'),'Value'=>22.];$GLOBALS['costRows'][$id][]=['TimeStamp'=>strtotime('2026-10-23T01:00:00+02:00'),'Value'=>23.];
$r=json_decode($m->GetHeatPumpCostAnalysis('2026-10-23','2026-10-23'),true);
check($r['summary']['savingEuro']===null&&str_contains(implode(' ',$r['warnings']),'Conflicting'),'Conflicting archive events cannot silently choose a temperature');
$m=costFixture();$rate=['from'=>'2026-10-01','through'=>null,'unitGrossCt'=>12.,'basicAnnualGrossEuro'=>365.,'basicDayDivisor'=>365,'kwhPerM3'=>11.,'conversionEstimated'=>false,'source'=>'Reviewed tariff','conversionSource'=>'Invoice'];
$GLOBALS['instanceProperties'][77]['GasHistoryTariffs']=json_encode([$rate]);
$r=json_decode($m->GetHeatPumpCostAnalysis('2026-10-23','2026-10-23'),true);
check(near($r['summary']['equivalentGasCostEuro'],160.8*.12)&&!$r['summary']['provisional'],'Dated gas unit tariff overrides the current fallback, without adding the daily fixed gas charge');
check(near($r['assumptions']['gasPriceMinCt'],12.)&&near($r['assumptions']['gasPriceMaxCt'],12.),'Displayed tariff range excludes an unused fallback rate');
$m=costFixture();$r=json_decode($m->GetHeatPumpCostAnalysis('2026-10-24','2026-10-24'),true);
check($r['to']===$m->clock,'Today ends at actual calculation time rather than including future electricity use');
$m=costFixture();$id=$m->properties['IncomingAirTempID'];$a=strtotime('2026-10-23T00:00:00+02:00');$GLOBALS['costRows'][$id]=[];
for($i=0;$i<11000;$i++)$GLOBALS['costRows'][$id][]=['TimeStamp'=>$a+$i,'Value'=>20.];
$reader=new HeatPumpCostArchive(9,$m->clock);$dense=$reader->read($id,'temperature',$a,$a+10999);
check(count($dense)===11000,'Dense archive reads split around the native 10000-row limit without truncation');
echo "PASS: $checks period-cost calculation, archive, access and read-only checks\n";
