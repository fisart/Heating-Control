<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';

$zone=new DateTimeZone('Europe/Berlin');
$epoch=static fn(string $date):int=>(new DateTimeImmutable($date.' 00:00:00',$zone))->getTimestamp();
$start=$epoch('2024-10-26'); $end=$epoch('2024-10-29')-1;
$end1=$epoch('2024-10-27')-1; $end2=$epoch('2024-10-28')-1;
$ids=['CleanedCounterM3'=>101,'ObservedDailyM3'=>102,'ReferenceCounterM3'=>103,
    'ReferenceCounterKWh'=>104,'QualityFlags'=>105,'InvoicePeriods'=>106];
$data=['schema'=>'Symcon.GasReviewedSnapshot.v1','import_status'=>'verified',
    'from'=>'2024-10-26T00:00:00+02:00','to'=>'2024-10-28T23:59:59+01:00',
    'source_archive_id'=>14817,'target_variable_ids'=>$ids,'archive_read_end_epoch'=>$end,
    'quality_bits'=>['8'=>'no_archive_records_on_day_not_zero_consumption','256'=>'reset_after_long_interval_consumption_unknown'],
    'invoices'=>[['from'=>'2024-10-26','to'=>'2024-10-28','supplier_m3'=>12,'supplier_kwh'=>133,
        'cleaned_observed_m3'=>10,'supplier_minus_cleaned_m3'=>2,'supplier'=>'Fixture',
        'supply_gross_eur'=>25,'december_aid_gross_eur'=>0,'price_brake_credit_eur'=>2,'cost_after_aid_eur'=>23]]];
$rows=static fn(array $pairs):array=>array_map(static fn($p)=>['TimeStamp'=>$p[0],'Value'=>$p[1]],$pairs);
$GLOBALS['history'][101]=$rows([[$start,0.],[$end1,4.],[$end,10.],[$end+100,10.]]);
$GLOBALS['history'][102]=$rows([[$end1,4.],[$end,6.],[$end+100,6.]]);
$GLOBALS['history'][103]=$rows([[$start,0.],[$end,12.],[$end+100,12.]]);
$GLOBALS['history'][104]=$rows([[$start,0.],[$end,133.],[$end+100,133.]]);
$GLOBALS['history'][105]=$rows([[$end1,32],[$end2,32|8|256],[$end,32],[$end+100,32]]);
$GLOBALS['liveVariables'][106]=['value'=>json_encode($data),'VariableType'=>3,'VariableUpdated'=>time()];
$module=newModule();$learningIdentity=$module->attributes['LearningIdentity'];
foreach (['GasHistoryEnabled'=>true,'GasHistoryMetadataID'=>106,'GasHistoryCleanedCounterID'=>101,
    'GasHistoryDailyID'=>102,'GasHistoryReferenceM3ID'=>103,'GasHistoryReferenceKWhID'=>104,
    'GasHistoryQualityID'=>105,'GasHistoryLaterCounterID'=>107,'GasHistoryLaterCounterScale'=>.01] as $key=>$value) $module->properties[$key]=$value;
$module->ApplyChanges();check($module->attributes['LearningIdentity']===$learningIdentity,'Gas history configuration does not reset the heating model');
$before=serialize([$module->properties,$module->values,$module->attributes,$GLOBALS['history']]);
$report=json_decode($module->GetGasHistory('2024-10-26','2024-10-28','daily'),true);
check(count($report['points'])===3,'All calendar days including unknown days are retained');
check($report['points'][1]['end']-$report['points'][1]['start']===25*3600,'Berlin DST day has 25 hours');
check($report['points'][1]['observedM3']===null && (float)$report['points'][1]['knownObservedM3']===0.,'Missing observation is null, not measured zero');
check(abs($report['totals']['knownObservedM3']-10.)<1e-9 && $report['totals']['missingObservationDays']===1,'Observed subtotal excludes missing day');
check((float)$report['totals']['invoicedM3']===12. && (float)$report['totals']['invoicedKWh']===133.,'Authoritative sparse bill references retained');
check((float)$report['totals']['supplyGrossEuro']===25. && (float)$report['totals']['creditsEuro']===2. && (float)$report['totals']['costAfterAidEuro']===23.,'Aid kept separate from gross invoice cost');
check(!$report['dailyReferenceAllocation'] && !$report['dailyKWhConversion'] && !$report['dailyCostAllocation'],'No invented daily reference, kWh or costs');
check(serialize([$module->properties,$module->values,$module->attributes,$GLOBALS['history']])===$before,'Reads do not mutate archives or instance');
$partial=json_decode($module->GetGasHistory('2024-10-28','2024-10-28','daily'),true);
check($partial['totals']['completeInvoices']===0 && !$partial['invoices'][0]['wholeInvoiceSelected'],'Partial selection does not allocate a whole invoice to selected days');
check((float)$partial['points'][0]['observedM3']===6.,'Selected day uses previous counter boundary');
$monthly=json_decode($module->GetGasHistory('2024-10-26','2024-10-28','monthly'),true);
check($monthly['points'][0]['observedM3']===null && (float)$monthly['points'][0]['knownObservedM3']===10.,'Incomplete bucket exposes a subtotal separately');
throws(fn()=>$module->GetGasHistory('2024-10-25','2024-10-28'),'Before reviewed history rejected');
throws(fn()=>$module->GetGasHistory('2024-10-26','2024-10-28','hourly'),'Invalid grouping rejected');
$GLOBALS['history'][103][1]['Value']=13.;
throws(fn()=>$module->GetGasHistory('2024-10-26','2024-10-28'),'Changed reference counter rejected');
$GLOBALS['history'][103][1]['Value']=12.;
$GLOBALS['history'][102][1]['Value']=7.;
throws(fn()=>$module->GetGasHistory('2024-10-26','2024-10-28'),'Changed daily history rejected by cumulative counter');
$GLOBALS['history'][102][1]['Value']=6.;
$wrong=$ids;$wrong['ObservedDailyM3']=999;
throws(fn()=>LichtBlickGasHistory::metadata(106,$wrong,14817),'Metadata binds the intended target IDs');

$GLOBALS['history'][107]=$rows([[$end,10000.],[$epoch('2024-10-29')+3600,10100.],
    [$epoch('2024-10-29')+7200,0.],[$epoch('2024-10-30')+3600,50.]]);
$adapter=new LichtBlickGasHistory(14817,$ids);
$later=$adapter->later(107,.01,$end+1,$epoch('2024-10-31'));
check($later[0]['observedM3']===null && ($later[0]['qualityFlags']&512),'Later counter drop stays unresolved; positive rebounds are not declared clean consumption');
check(abs($later[1]['observedM3']-.5)<1e-9 && ($later[1]['qualityFlags']&1024),'Later monotonic segment is marked unreviewed');
$joined=LichtBlickGasHistory::report([
    ['start'=>$start,'end'=>$start+3600,'observedM3'=>4.,'qualityFlags'=>128,'source'=>'reviewed-archive'],
    ['start'=>$start+3600,'end'=>$start+7200,'observedM3'=>.5,'qualityFlags'=>1024,'source'=>'later-meter-archive']
],$data,$start,$start+7200,'daily');
check(count($joined['points'])===1 && $joined['points'][0]['knownObservedM3']===4.5,'Snapshot cutoff and later same-day events merge once');
$backup=json_decode($module->ExportConfig(),true);foreach(array_keys($backup['configuration']) as $key)if(str_starts_with($key,'GasHistory'))unset($backup['configuration'][$key]);
check(str_starts_with($module->ImportConfig(json_encode($backup)),'Imported') && !$module->properties['GasHistoryEnabled'],'Pre-gas backup remains compatible without silently enabling gas history');
echo "Gas references, snapshot markers, missing days, DST, residuals, aid, later resets, cutoff merging, archive integrity, read-only behavior and legacy config checks passed.\n";
