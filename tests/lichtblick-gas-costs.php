<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
$zone=new DateTimeZone('Europe/Berlin');
$day=static fn(string $d):int=>(new DateTimeImmutable($d.' 00:00:00',$zone))->getTimestamp();
$base=['from'=>'2024-10-26','through'=>'2024-10-28','unitGrossCt'=>20.,'basicAnnualGrossEuro'=>365.,
    'basicDayDivisor'=>365,'kwhPerM3'=>10.,'conversionEstimated'=>false,'source'=>'Fixture gross tariff','conversionSource'=>'Fixture billed factor'];
$rates=LichtBlickGasCosts::tariffs(json_encode([$base]));
$points=[];
foreach(['2024-10-26'=>4.,'2024-10-27'=>null,'2024-10-28'=>6.] as $date=>$m3) {
    $a=$day($date);$b=(new DateTimeImmutable($date.' 00:00:00',$zone))->modify('+1 day')->getTimestamp();
    $points[]=['start'=>$a,'end'=>$b,'observedM3'=>$m3,'qualityFlags'=>$m3===null?8:0,'source'=>'reviewed-archive'];
}
$priced=LichtBlickGasCosts::apply($points,$rates);
check($priced[0]['convertedKWh']===40. && $priced[0]['variableCostEuro']===8. && $priced[0]['calculatedCostEuro']===9.,'Observed volume times billed conversion times gross dated rate, plus basic charge');
check($priced[1]['fixedCostEuro']===1. && $priced[1]['variableCostEuro']===null && $priced[1]['knownCalculatedCostEuro']===1. && $priced[1]['calculatedCostEuro']===null,'Missing volume retains known basic fee but no invented energy cost');
check($priced[1]['end']-$priced[1]['start']===25*3600 && $priced[1]['fixedCostEuro']===1.,'DST does not add an extra hour of daily basic charge');
$data=['to'=>'2024-10-28T23:59:59+01:00','quality_bits'=>[],
    'invoices'=>[['from'=>'2024-10-26','to'=>'2024-10-28','supplier_m3'=>12,'supplier_kwh'=>133,'supply_gross_eur'=>25,
        'december_aid_gross_eur'=>0,'price_brake_credit_eur'=>2,'cost_after_aid_eur'=>23]]];
$report=LichtBlickGasHistory::report($priced,$data,$day('2024-10-26'),$day('2024-10-29'),'monthly',$rates);
check($report['totals']['knownCalculatedCostEuro']===23. && $report['totals']['knownVariableCostEuro']===20. && $report['totals']['knownFixedCostEuro']===3.,'Independent gross subtotal separates variable and fixed cost');
check($report['points'][0]['calculatedCostEuro']===null && $report['totals']['incompleteCostDays']===1,'Incomplete costs remain marked separately from known subtotal');
check($report['invoices'][0]['supplyMinusCalculatedKnownEuro']===2. && $report['invoices'][0]['calculationIncomplete'],'Invoice discrepancy stays explicit, not fitted into prices');
check($report['totals']['creditsEuro']===2. && $report['totals']['knownCalculatedCostEuro']===23.,'Invoice aid is not deducted from independently calculated daily costs');
$next=$base;$next['from']='2024-10-29';$next['through']=null;$next['unitGrossCt']=10.;$next['kwhPerM3']=11.;$next['conversionEstimated']=true;
$newRates=LichtBlickGasCosts::tariffs(json_encode([$base,$next]));
$future=[['start'=>$day('2024-10-29'),'end'=>$day('2024-10-30'),'observedM3'=>2.,'qualityFlags'=>1024,'source'=>'later-meter-archive']];
$new=LichtBlickGasCosts::apply($future,$newRates)[0];
check(abs($new['calculatedCostEuro']-3.2)<1e-9 && $new['unitGrossCt']===10. && ($new['qualityFlags']&4096),'Dated change applies; carried-forward factor is marked estimated');
$missing=LichtBlickGasCosts::apply($future,[])[0];
check($missing['knownCalculatedCostEuro']===null && $missing['convertedKWh']===null && ($missing['qualityFlags']&2048),'Missing tariff stays unknown, not zero or current-price backfill');
$reset=$future;$reset[0]['qualityFlags']|=1;
$resetCost=LichtBlickGasCosts::apply($reset,$newRates)[0];
check($resetCost['calculatedCostEuro']===null && abs($resetCost['knownCalculatedCostEuro']-3.2)<1e-9,'Reset interval retains observed subtotal without declaring a complete cost');
$split=$points[1];$split['observedM3']=0.;$split['qualityFlags']=0;
$cut=$split['start']+3600;$first=$split;$second=$split;$first['end']=$cut;$second['start']=$cut;
$two=LichtBlickGasCosts::apply([$first,$second],$rates);
check(abs($two[0]['fixedCostEuro']+$two[1]['fixedCostEuro']-1.)<1e-9,'Snapshot cutoff and later same-day segment do not duplicate basic charge');
$overlap=$next;$overlap['from']='2024-10-28';
throws(fn()=>LichtBlickGasCosts::tariffs(json_encode([$base,$overlap])),'Overlapping tariff periods rejected');
$bad=$base;$bad['kwhPerM3']=0.;throws(fn()=>LichtBlickGasCosts::tariffs(json_encode([$bad])),'Invalid conversion rejected');
$bad=$base;$bad['source']='';throws(fn()=>LichtBlickGasCosts::tariffs(json_encode([$bad])),'Unattributed prices rejected');
$m=newModule();$identity=$m->attributes['LearningIdentity'];$m->properties['GasHistoryTariffs']=json_encode([$base]);$m->ApplyChanges();
check($m->attributes['LearningIdentity']===$identity,'Gas valuation does not change heating learning identity');
$zeroPeriods=LichtBlickNoConsumption::periods(json_encode([['from'=>'2024-10-27','through'=>'2024-10-27','utilities'=>['electricity','gas','water'],'source'=>'User confirmed outage']]));
$original=$points;
$zero=LichtBlickGasCosts::apply(LichtBlickNoConsumption::apply($points,$zeroPeriods,'gas'),$rates);
check($zero[1]['observedM3']===0. && $zero[1]['convertedKWh']===0. && $zero[1]['variableCostEuro']===0. && $zero[1]['calculatedCostEuro']===1.,'Confirmed outage has zero energy and retains contractual basic fee');
check(($zero[1]['qualityFlags']&8192) && $zero[0]['observedM3']===4. && $zero[2]['observedM3']===6. && $points===$original,'Only confirmed full period changes in memory; input/archive data untouched');
$crossing=$points[0];$crossing['end']=$points[1]['end'];
check(!isset(LichtBlickNoConsumption::apply([$crossing],$zeroPeriods,'gas')[0]['confirmedNoConsumption']),'Partially overlapping boundary is not declared zero');
$electric=[['start'=>$points[1]['start'],'end'=>$points[1]['start']+900,'qualityFlags'=>128,'cleanedKWh'=>null,'referenceKWh'=>0.2,'estimatedResetKWh'=>0.1,'fixedCostEuro'=>0.03,'tariffCostEuro'=>0.09]];
$ez=LichtBlickNoConsumption::apply($electric,$zeroPeriods,'electricity')[0];
check($ez['cleanedKWh']===0. && $ez['referenceKWh']===0. && $ez['tariffCostEuro']===0.03 && $ez['estimatedResetKWh']===0. && ($ez['qualityFlags']&16384),'Electricity allocation conflict is flagged, consumption is zero, fixed fee retained');
$positive=$points;$positive[1]['observedM3']=2.;
check(LichtBlickNoConsumption::apply($positive,$zeroPeriods,'gas')[1]['qualityFlags']&16384,'Conflicting observed volume is flagged explicitly');
$zeroReport=LichtBlickGasHistory::report($zero,$data,$day('2024-10-26'),$day('2024-10-29'),'daily',$rates);
check($zeroReport['totals']['missingObservationDays']===0 && $zeroReport['totals']['incompleteCostDays']===0,'Confirmed zero resolves missing consumption/cost without spreading invoice residual');
check($zeroReport['totals']['confirmedZeroDays']===1 && $zeroReport['points'][1]['confirmedNoConsumption']===true,'Full zero day remains explicit');
$zm=LichtBlickGasHistory::report($zero,$data,$day('2024-10-26'),$day('2024-10-29'),'monthly',$rates);
check($zm['points'][0]['confirmedNoConsumption']===false,'Month containing one zero day is not entirely declared zero');
echo "Dated gas prices, separate basic charges, conversion estimates, DST, partial-day charges, missing tariffs/consumption, reset gaps, invoice residuals and independent aid checks passed.\n";
