<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
$kernel=sys_get_temp_dir().'/lbep-consumption-'.bin2hex(random_bytes(6));mkdir($kernel,0700,true);
function IPS_GetKernelDir(){return $GLOBALS['kernel'].'/';}
function timestamp(string $day):int{return (new DateTimeImmutable($day,new DateTimeZone('Europe/Berlin')))->getTimestamp();}
$a=timestamp('2024-10-26');$b=timestamp('2024-10-29');$r=timestamp('2026-10-01');
$ids=['CleanedCounter'=>101,'ReferenceCounter'=>102,'QualityFlags'=>103];
$intervals=[];$energy=0.;$ref=0.;$quality=[];
for($date=new DateTimeImmutable('2024-10-26',new DateTimeZone('Europe/Berlin'));$date->getTimestamp()<$b;$date=$date->modify('+1 day')){
 $start=$date->getTimestamp();$end=$date->modify('+1 day')->getTimestamp();$kwh=10.;$flags=129|($date->format('d')==='27'?4:0);
 $intervals[]=['start'=>$start,'end'=>$end,'cleaned_kwh'=>$kwh,'reference_kwh'=>11.,'quality_flags'=>$flags,'unit_price_gross_ct_per_kwh'=>30.,'fixed_cost_eur_candidate'=>1.,'price_verified'=>true,'price_source'=>'invoice','estimated_component_kwh'=>0.];
}
// Include a provisional app period after a discontinuity only in the metadata.
// Requests select complete native intervals, not invented daily expansions.
$intervals[]=['start'=>$r,'end'=>$r+900,'cleaned_kwh'=>1.,'reference_kwh'=>1.1,'quality_flags'=>641,'unit_price_gross_ct_per_kwh'=>40.,'fixed_cost_eur_candidate'=>0.,'price_verified'=>false,'price_source'=>'app-normalized-estimate','estimated_component_kwh'=>0.];
$data=['schema'=>'Symcon.PreparedElectricityHistory.v1','archive_id'=>14817,'from'=>'2024-10-26T00:00:00+02:00','to_exclusive'=>'2026-10-01T00:15:00+02:00','intervals'=>$intervals,'quality_bits'=>['clock_corrected'=>4,'reference_normalized'=>128,'no_consumption_reference'=>256,'retail_price_estimated'=>512],
'reference_reconciliation'=>[['invoice_number'=>'fixture','from'=>'2024-10-26','to_inclusive'=>'2024-10-28','billed_kwh'=>33.,'billed_gross_eur'=>14.,'dated_tariff_cost_on_reference_curve_eur'=>12.9,'billing_reconciliation_eur'=>1.1]]];
$GLOBALS['history']=[];
foreach($intervals as $i){$s=$i['start'];$e=$i['end'];foreach([101,102] as $id)$GLOBALS['history'][$id][]=['TimeStamp'=>$s,'Value'=>$id===101?$energy:$ref];$quality[]=['TimeStamp'=>$s,'Value'=>$i['quality_flags']];$energy+=$i['cleaned_kwh'];$ref+=$i['reference_kwh'];foreach([101,102] as $id){$v=$id===101?$energy:$ref;$GLOBALS['history'][$id][]=['TimeStamp'=>$e-1,'Value'=>$v];$GLOBALS['history'][$id][]=['TimeStamp'=>$e,'Value'=>$v];}}
$GLOBALS['history'][103]=$quality;$adapter=new LichtBlickConsumptionHistory(14817,$ids);$data['_archive_id']=14817;
$rows=$adapter->prepared($data,$a,$b);check(count($rows)===3 && $rows[1]['end']-$rows[1]['start']===90000,'25-hour DST day stays one daily interval');
$report=LichtBlickConsumptionHistory::report($rows,$data,$a,$b,'daily');
check(abs($report['totals']['knownCostWithFullPeriodReconciliationEuro']-14)<1e-9,'Complete invoice cost reconciles exactly');
check(abs($report['totals']['knownCleanedKWh']-30)<1e-9 && abs($report['totals']['knownReferenceKWh']-33)<1e-9,'Physical and reference series stay separate');
$partial=LichtBlickConsumptionHistory::report(array_slice($rows,0,1),$data,$a,$a+86400,'daily');check($partial['totals']['fullPeriodReconciliationEuro']===0.0 && !$partial['references'][0]['wholeReferenceSelected'],'Partial period does not receive arbitrary bill residual');
$GLOBALS['history'][101][0]['Value']+=1.;throws(fn()=>$adapter->prepared($data,$a,$b),'Modified archive rejected');$GLOBALS['history'][101][0]['Value']-=1.;
$quote=['start'=>$r,'end'=>$r+900,'source'=>'lichtblick-quote','verified'=>true,'price'=>20.];$reprice=$adapter->prepared($data,$r,$r+900,[$quote]);check(abs($reprice[0]['tariffCostEuro']-.22)<1e-9 && !($reprice[0]['qualityFlags']&512),'Confirmed quote supersedes app estimate without changing archive');
$estimatedRows=$adapter->prepared($data,$r,$r+900);
$estimatedReport=LichtBlickConsumptionHistory::report($estimatedRows,$data,$r,$r+900,'daily');
check($estimatedReport['points'][0]['unconfirmedPriceIntervals']===1,'Daily buckets retain unconfirmed-price provenance for total reports');
check((LichtBlickConsumptionHistory::report($reprice,$data,$r,$r+900,'daily')['points'][0]['unconfirmedPriceIntervals']??0)===0,'Verified replacement quote clears daily provisional-price flag');
// Dense live fixture exercises adaptive 10,000-record limit and reset rejection.
$live=$r+900;$GLOBALS['history'][104]=[];
for($t=$live-180;$t<=$live+3600+180;$t+=60)$GLOBALS['history'][104][]=['TimeStamp'=>$t,'Value'=>100+($t-$live)/900];
$prices=[['start'=>$live,'end'=>$live+900,'price'=>30.,'source'=>'lichtblick-quote','verified'=>true]];
$later=$adapter->later(104,1.,$prices,$live,$live+3600,$live+3700);
check(count($later)===4 && abs($later[0]['cleanedKWh']-1.)<1e-9 && abs($later[0]['tariffCostEuro']-.3)<1e-9,'Live quarter-hour counter/quote pairing');
check($later[1]['tariffCostEuro']===null && ($later[1]['qualityFlags']&4096),'Missing price stays a cost gap');
check($later[0]['fixedCostEuro']===null && ($later[0]['qualityFlags']&256),'No invented basic charge or later reference calibration');
foreach($GLOBALS['history'][104] as &$p)if($p['TimeStamp']===$live+420)$p['Value']=0.;unset($p);
$reset=$adapter->later(104,1.,$prices,$live,$live+900,$live+1000);check($reset[0]['cleanedKWh']===null && ($reset[0]['qualityFlags']&2048),'Live reset is a gap, never guessed energy');
$GLOBALS['unlogged'][104]=true;$unlogged=$adapter->later(104,1.,$prices,$live,$live+900,$live+1000);check($unlogged[0]['cleanedKWh']===null,'Unlogged meter never fabricated from current value');unset($GLOBALS['unlogged'][104]);
$GLOBALS['history'][105]=[];for($t=$live;$t<$live+11000;$t++)$GLOBALS['history'][105][]=['TimeStamp'=>$t,'Value'=>(float)$t];check(count($adapter->rows(105,$live,$live+10999))===11000,'Adaptive query retains all dense records');
$dir=$kernel.'/media/prepared';mkdir($dir,0700,true);unset($data['_archive_id']);$payload=json_encode($data,JSON_THROW_ON_ERROR);file_put_contents($dir.'/strom-aufbereitung-geprueft.json',$payload);
$manifest=['schema'=>'Symcon.PreparedElectricityImport.v1','archive_id'=>14817,'payload_sha256'=>hash('sha256',$payload),'summary'=>['status'=>'import_saved','target_variable_ids'=>$ids,'from'=>$data['from'],'to_exclusive'=>$data['to_exclusive']]];file_put_contents($dir.'/import-manifest.json',json_encode($manifest));
$module=newModule();$module->properties['PreparedHistoryEnabled']=true;$module->properties['PreparedHistoryManifest']='media/prepared/import-manifest.json';$module->properties['PreparedCleanedCounterID']=101;$module->properties['PreparedReferenceCounterID']=102;$module->properties['PreparedQualityID']=103;
$before=serialize([$module->properties,$module->values,$module->attributes,$GLOBALS['history']]);
$api=json_decode($module->GetConsumptionHistory('2024-10-26','2024-10-28','daily'),true);check(count($api['points'])===3,'Public read-only module API uses configured archives');check(serialize([$module->properties,$module->values,$module->attributes,$GLOBALS['history']])===$before,'History reads do not mutate module or archives');
throws(fn()=>$module->GetConsumptionHistory('2024-10-25','2024-10-28'),'Before prepared range rejected');throws(fn()=>$module->GetConsumptionHistory('2024-10-26','2024-10-28','nonsense'),'Invalid grouping rejected');
$bad=$ids;$bad['CleanedCounter']=999;throws(fn()=>LichtBlickConsumptionHistory::dataset($dir.'/import-manifest.json',$bad),'Manifest IDs enforce intended dataset');
file_put_contents($dir.'/strom-aufbereitung-geprueft.json',$payload.' ');throws(fn()=>$module->GetConsumptionHistory('2024-10-26','2024-10-28'),'Changed dataset hash rejected');
$backup=json_decode($module->ExportConfig(),true);foreach(array_keys($backup['configuration']) as $k)if(str_starts_with($k,'Prepared')||str_starts_with($k,'Consumption'))unset($backup['configuration'][$k]);check(str_starts_with($module->ImportConfig(json_encode($backup)),'Imported'),'Old configuration backup remains compatible');check(!$module->properties['PreparedHistoryEnabled'],'Old backup does not silently enable prepared history');
foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);rmdir($kernel.'/media');rmdir($kernel);
echo "Prepared consumption archives, invoice totals/residuals, DST, quote priority, raw live gaps/resets, limits, identities, read-only API and legacy config checks passed.\n";

