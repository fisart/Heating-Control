<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
$z=new DateTimeZone('Europe/Berlin');$time=fn($s)=>(new DateTimeImmutable($s,$z))->getTimestamp();$a=$time('2024-10-26 00:00:00');$b=$time('2024-10-29 00:00:00');$cutoff=$time('2024-10-28 12:00:00');
$m=newModule();$m->properties['WaterHistoryEnabled']=true;$m->properties['WaterHistoryHouseIncludesGarden']=true;$m->properties['WaterHistoryReviewedMetadataID']=9140;
$sources=['house'=>['counterID'=>9001,'scaleM3'=>.001,'source'=>'Fixture main'],'well'=>['counterID'=>9002,'scaleM3'=>.001,'source'=>'Fixture well']];$m->properties['WaterHistorySources']=json_encode($sources);
$ids=['MainCleanedCounterM3'=>9110,'MainObservedDailyM3'=>9111,'MainQualityFlags'=>9112,'WellCleanedCounterM3'=>9120,'WellObservedDailyM3'=>9121,'WellQualityFlags'=>9122,'ReferenceMainCounterM3'=>9130,'Metadata'=>9140];
$data=['schema'=>'Symcon.PreparedWaterHistory.v1','import_status'=>'verified','archive_id'=>14817,'source_ids'=>['house'=>9001,'well'=>9002],'target_variable_ids'=>$ids,'snapshot_to'=>$cutoff,'archive_read_end_epoch'=>$cutoff,'policy'=>['reference_daily_interpolation_allowed'=>false],'quality_flag_bits'=>['512'=>'Invoice-period bypass uncertainty'],
 'coverage'=>['house'=>['from'=>'2024-10-26','through'=>'2024-10-28'],'well'=>['from'=>'2024-10-26','through'=>'2024-10-28']],
 'invoice_comparisons'=>[['number'=>'fixture','from'=>'2024-10-25','through'=>'2024-10-25','supplier_main_m3'=>2.5,'cleaned_observed_m3'=>2.,'supplier_minus_observed_m3'=>.5]]];
$GLOBALS['liveVariables'][9140]=['VariableType'=>3,'value'=>json_encode($data)];
$rows=static fn($pairs)=>array_map(fn($r)=>['TimeStamp'=>$r[0],'Value'=>$r[1]],$pairs);$d2=$time('2024-10-27 00:00:00');$d3=$time('2024-10-28 00:00:00');
$GLOBALS['history'][9110]=$rows([[$a,0.],[$d2,2.],[$d3,2.],[$cutoff,3.],[time(),999.]]);
$GLOBALS['history'][9111]=$rows([[$a,2.],[$d2,0.],[$d3,1.],[time(),999.]]);$GLOBALS['history'][9112]=$rows([[$a,0],[$d2,0],[$d3,0],[time(),999]]);
$GLOBALS['history'][9120]=$rows([[$a,0.],[$d2,.1],[$d3,.1],[$cutoff,.1],[time(),999.]]);$GLOBALS['history'][9121]=$rows([[$a,.1],[$d2,0.],[$d3,0.],[time(),999.]]);$GLOBALS['history'][9122]=$rows([[$a,0],[$d2,32],[$d3,16],[time(),999]]);
$GLOBALS['history'][9130]=$rows([[$time('2024-10-25 00:00:00'),0.],[$a,2.5],[time(),999.]]);
$GLOBALS['history'][9001]=$rows([[$a-1,5000],[$a+100,7000],[$d2+100,0],[$cutoff,8000],[$time('2024-10-28 18:00:00'),8500]]);
$GLOBALS['history'][9002]=$rows([[$a-1,9900],[$a+100,10000],[$d2+100,0],[$d2+102,10000],[$cutoff,10000],[$time('2024-10-28 13:00:00'),0],[$time('2024-10-28 13:00:02'),10000],[$time('2024-10-28 14:00:00'),9999],[$time('2024-10-28 15:00:00'),10000]]);
$tariff=['stream'=>'house','from'=>'2024-10-26','through'=>'2024-10-28','drinkingNetEuroM3'=>2.,'vatPercent'=>7.,'drinkingBasicNetEuroDay'=>.1,'sewageGrossEuroM3'=>3.,'sewageBasicGrossEuroDay'=>.2,'rainAreaPerDay'=>29/365,'rainGrossRate'=>1.,'source'=>'Fixture invoice rates'];$garden=$tariff;$garden['stream']='garden';$garden['sewageGrossEuroM3']=$garden['drinkingBasicNetEuroDay']=$garden['sewageBasicGrossEuroDay']=$garden['rainAreaPerDay']=0.;$m->properties['WaterHistoryTariffs']=json_encode([$tariff,$garden]);
$identity=$m->attributes['LearningIdentity'];$m->ApplyChanges();check($m->attributes['LearningIdentity']===$identity&&$m->updates['status']===102,'Reviewed water configuration does not reset heating learning');
$before=serialize($GLOBALS['history']);$properties=$m->properties;
$h=json_decode($m->GetWaterHistory('2024-10-26','2024-10-28','daily'),true);$e=$h['electronic'];$fee=.107+.2+29/365;
check(count($e['streams']['house']['points'])===3&&abs($e['streams']['house']['totals']['knownM3']-3.5)<1e-9,'Prepared partial last day merges later raw increases once');
check($e['streams']['well']['totals']['knownM3']==.1&&$e['streams']['well']['totals']['knownCostEuro']==0.,'Prepared well noise and later zero/rebounds do not become consumption or costs');
check(abs($e['streams']['house']['totals']['knownCostEuro']-(3.5*5.14+3*$fee))<1e-9,'Missing manual garden data does not hide main consumption or duplicate fixed charges');
check($e['streams']['house']['totals']['completeVolume']&&$e['streams']['house']['totals']['completeCost'],'Missing electronic garden meter does not make captured main-water costs incomplete');
$total=json_decode($m->GetTotalConsumption('2024-10-26','2024-10-28','selected'),true);
check(abs($total['totals']['knownWaterM3']-3.6)<1e-9&&$total['streams']['garden']['referenceOnlyVolume'],'Total uses captured main plus well without garden double counting');
check(serialize($GLOBALS['history'])===$before&&$m->properties===$properties,'Water and total APIs never write source or target archives/settings');
$GLOBALS['history'][9112][1]['Value']=512;$h=json_decode($m->GetWaterHistory('2024-10-26','2024-10-28','daily'),true);
check($h['electronic']['streams']['house']['totals']['missingVolumeDays']===1&&abs($h['electronic']['streams']['house']['totals']['knownM3']-3.5)<1e-9,'Bypass period is incomplete while captured quantities remain visible');
$GLOBALS['history'][9112][1]['Value']=0;$GLOBALS['history'][9111][0]['Value']=10.;$h=json_decode($m->GetWaterHistory('2024-10-26','2024-10-28'),true);
check($h['electronic']['streams']['house']['totals']['knownM3']===null&&str_contains($h['electronic']['streams']['house']['error'],'readback differs'),'Changed prepared values fail closed without fallback to unhealthy raw history');$GLOBALS['history'][9111][0]['Value']=2.;
$GLOBALS['history'][9130][1]['Value']=3.;$h=json_decode($m->GetWaterHistory('2024-10-26','2024-10-28'),true);check(str_contains($h['electronic']['streams']['house']['error'],'reference volumes differ'),'Sparse reference tampering rejected');$GLOBALS['history'][9130][1]['Value']=2.5;
$quiet=LichtBlickReviewedWater::later(14817,$sources['well'],'well',$time('2025-01-01'),$time('2025-03-01'));
check(count($quiet)===59&&array_sum(array_column($quiet,'observedM3'))===0.,'Long winter well inactivity is a valid held-counter zero change');
$GLOBALS['history'][9002][]=['TimeStamp'=>$time('2025-01-02'),'Value'=>9000];throws(fn()=>LichtBlickReviewedWater::later(14817,$sources['well'],'well',$time('2025-01-01'),$time('2025-03-01')),'Unknown later well reset must not become a reconstructed volume');
// Full reviewed main history must remain usable under Symcon's 64 MiB limit.
$data['snapshot_to']=$data['archive_read_end_epoch']=time()-60;
$data['coverage']['house']=['from'=>'2017-01-01','through'=>(new DateTimeImmutable('@'.$data['snapshot_to']))->setTimezone($z)->format('Y-m-d')];
$data['coverage']['well']=['from'=>'2019-08-06','through'=>$data['coverage']['house']['through']];
foreach(['house'=>'Main','well'=>'Well'] as $key=>$prefix){$sum=0.;$d=new DateTimeImmutable($data['coverage'][$key]['from'],$z);$counter=[['TimeStamp'=>$d->getTimestamp(),'Value'=>0.]];$daily=[];$quality=[];
 while($d->getTimestamp()<=$data['snapshot_to']){$next=$d->modify('+1 day');$sum+=$key==='house'?.01:.02;$counter[]=['TimeStamp'=>min($next->getTimestamp(),$data['snapshot_to']),'Value'=>$sum];$daily[]=['TimeStamp'=>$d->getTimestamp(),'Value'=>$key==='house'?.01:.02];$quality[]=['TimeStamp'=>$d->getTimestamp(),'Value'=>$key==='house'&&$d->format('Y-m-d')<'2022-04-16'?128:0];$d=$next;}
 $GLOBALS['history'][$ids[$prefix.'CleanedCounterM3']]=$counter;$GLOBALS['history'][$ids[$prefix.'ObservedDailyM3']]=$daily;$GLOBALS['history'][$ids[$prefix.'QualityFlags']]=$quality;
}unset($counter,$daily,$quality);
$GLOBALS['history'][9001]=[['TimeStamp'=>$data['snapshot_to'],'Value'=>40000]];$GLOBALS['history'][9002]=[['TimeStamp'=>$data['snapshot_to'],'Value'=>200000]];
$GLOBALS['liveVariables'][9140]['value']=json_encode($data);$tariff['from']='2017-01-01';$tariff['through']=null;$garden['from']='2017-01-01';$garden['through']=null;$m->properties['WaterHistoryTariffs']=json_encode([$tariff,$garden]);
$full=json_decode($m->GetTotalConsumption('2017-01-01',$data['coverage']['house']['through'],'monthly'),true);
check($full['streams']['house']['knownVolume']>30&&count($full['points'])>100,'Full ten-year reviewed water range renders in combined monthly totals');
check(memory_get_peak_usage(true)<64*1048576,'Full reviewed range remains under the Symcon 64 MiB memory budget');
unset($full);
echo "Reviewed water identity, sparse references, daily/cumulative checks, registration cutoff, live partial-day merge, winter inactivity, well rebounds, main prices, garden inclusion and read-only APIs passed.\n";
