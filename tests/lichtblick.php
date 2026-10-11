<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
$start=(int)(floor(time()/900)*900);
$slots=LichtBlickEconomics::parsePrices(quote(-2.5,$start),1.5);
check($slots[0]['price']===-1.0,'Negative price/correction preserved');
check(LichtBlickEconomics::slotAt($slots,$start+899)!==null,'Interval valid before boundary');
check(LichtBlickEconomics::slotAt($slots,$start+900)===null,'Exclusive endpoint; no invented next slot');
throws(fn()=>LichtBlickEconomics::parsePrices('{"status":"ok","dataPoints":[]}',0),'Empty response accepted');
$duplicate=json_decode(quote(20,$start),true);$duplicate['dataPoints'][]=$duplicate['dataPoints'][0];
throws(fn()=>LichtBlickEconomics::parsePrices(json_encode($duplicate),0),'Duplicate slot accepted');
$bad=json_decode(quote(20,$start),true);$bad['dataPoints'][0]['time']='2026-10-11T00:00:00';
throws(fn()=>LichtBlickEconomics::parsePrices(json_encode($bad),0),'Offset-free date accepted');
foreach (['2025-03-31'=>23,'2025-10-27'=>25,'2025-01-02'=>24] as $today=>$hours) {
    $now=(new DateTimeImmutable($today.' 12:00',new DateTimeZone('Europe/Berlin')))->getTimestamp();
    [, $a,$b]=LichtBlickEconomics::previousDay($now);check(($b-$a)/3600===$hours,'DST day length');
}
$m=newModule();check($m->properties['SafetyMarginPercent']===25.0,'Default margin');
$m->response=quote(19.0,$start);$m->RefreshPrices();
check($m->values['RecommendedSource']===0 && !$m->values['PriceValid'],'Unverified tariff must not recommend');
$m->properties['Enabled']=true;$m->properties['TariffVerified']=true;$m->ApplyChanges();
check($m->values['RecommendedSource']===1 && $m->values['RecommendationValid'],'Cheap price selects heat pump');
check($m->values['RecommendedHeatPumpPowerPercent']===50,'Configured operating capacity published');
$snapshot=json_decode($m->values['RecommendationJSON'],true,16,JSON_THROW_ON_ERROR);
check($snapshot['schema']===1 && $snapshot['source']===1 && $snapshot['valid']===true && $snapshot['powerPercent']===50 && $snapshot['validUntil']===$start+900,'Atomic valid recommendation with interval deadline');
$m->properties['ReferenceCapacityPercent']=35;$m->EvaluatePrices();
check($m->values['RecommendedHeatPumpPowerPercent']===35 && json_decode($m->values['RecommendationJSON'],true)['powerPercent']===35,'Changed operating capacity published atomically');
$m->properties['ReferenceCapacityPercent']=50;
check($m->values['EffectiveThresholdCt']===22.5 && $m->values['EntryThresholdCt']===20.5,'Margin applied exactly once');
$m->response=quote(21.0,$start);$m->RefreshPrices();check($m->values['RecommendedSource']===1,'HP retained inside hysteresis');
$m->response=quote(22.5,$start);$m->RefreshPrices();check($m->values['RecommendedSource']===2,'Equality selects gas');
$m->response=quote(21.0,$start);$m->RefreshPrices();check($m->values['RecommendedSource']===2,'Gas retained inside hysteresis');
$m->properties['SafetyMarginPercent']=10.0;$m->ApplyChanges();check($m->values['EffectiveThresholdCt']===27.0,'Editable margin used');
$m->fail=true;$m->RefreshPrices();check($m->values['RecommendationValid'],'Valid covered cache survives fetch outage');
$m->values['LastSuccessfulFetch']=time()-25*3600;$m->EvaluatePrices();
check($m->values['RecommendedSource']===2 && !$m->values['RecommendationValid'] && !$m->values['HeatPumpRecommended'],'Expired data falls back to gas');
check($m->values['RecommendedHeatPumpPowerPercent']===0 && json_decode($m->values['RecommendationJSON'],true)['validUntil']===0,'Invalid recommendations expire and carry no operating capacity');
$m->properties['PostalCode']='20099';$m->ApplyChanges();check($m->attributes['PriceCache']==='' && !$m->values['PriceValid'] && !$m->properties['TariffVerified'],'Postcode change invalidates cache and tariff confirmation');
$export=$m->ExportConfig();$backup=json_decode($export,true);check($backup['configuration']['SafetyMarginPercent']==10.0,'Margin exported');
$backup['configuration']['SafetyMarginPercent']=101;
$old=$m->properties;check(str_starts_with($m->ImportConfig(json_encode($backup)),'Import failed') && $old===$m->properties,'Bad backup writes nothing');
check(str_starts_with($m->ImportConfig($export),'Imported'),'Backup imports');
check(!$m->properties['Enabled'] && !$m->properties['TariffVerified'] && !$m->properties['DailyLearningEnabled'],'Restore disabled and tariff unverified');

// Archive fixtures: stable holiday rooms, no radiator/DHW heat, separate pure-source days.
$m=newModule();$m->properties['DailyLearningEnabled']=true;$m->properties['MinimumReferenceDays']=3;
$m->ApplyChanges();$c=$m->properties;
[$date,$a,$b]=LichtBlickEconomics::previousDay(time());
$GLOBALS['history']=[];
$temps=[25911=>5.0,15709=>15.0,57694=>10.0,44502=>10.0,15880=>10.0,57255=>10.0];
function addDay(array $c,string $date,int $source): void {
    $a=(new DateTimeImmutable($date,new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $b=(new DateTimeImmutable($date,new DateTimeZone('Europe/Berlin')))->modify('+1 day')->getTimestamp();
    $values=[41085=>$source,46970=>true,35931=>$source===1,51011=>true,19207=>50.0,32875=>$source===2,44860=>false,20574=>false,
        12594=>10.0,40975=>10.0,30481=>10.0,37341=>10.0];
    foreach ($values as $id=>$v) $GLOBALS['history'][$id][]=['TimeStamp'=>$a,'Value'=>$v];
    foreach ($GLOBALS['fixtureTemps'] as $id=>$v) {
        for($t=$a;$t<$b;$t+=3600) $GLOBALS['history'][$id][]=['TimeStamp'=>$t,'Value'=>$v];
    }
    foreach ([25105=>($source===1?0.2:10),12254=>($source===1?35:0),10054=>($source===1?5:4.896)] as $id=>$delta) {
        $old=0;
        foreach ($GLOBALS['history'][$id]??[] as $r) $old=max($old,$r['Value']);
        $scale=$id===25105?1:0.001;
        $GLOBALS['history'][$id][]=['TimeStamp'=>$a,'Value'=>$old];
        $GLOBALS['history'][$id][]=['TimeStamp'=>$b-1,'Value'=>$old+$delta/$scale];
    }
}
$GLOBALS['fixtureTemps']=$temps;
$dayStart=new DateTimeImmutable($date,new DateTimeZone('Europe/Berlin'));
for($i=3;$i>=1;$i--) {
    $d=$dayStart->modify('-'.$i.' days')->format('Y-m-d');addDay($c,$d,1);
    $status=$m->AddReferenceDay($d);check(str_starts_with($status,'Stored reference'),'Historical day accepted: '.$status);
}
addDay($c,$date,2);$m->CalculateYesterday();
check($m->properties['ThresholdCt'] > 30 && $m->properties['ThresholdCt'] <= 33,'Accepted match updates original threshold gradually: '.$m->values['DailyStatus']);
check($m->properties['SafetyMarginPercent']===25.0,'Learning retains margin');
$accepted=$m->properties['ThresholdCt'];$m->CalculateYesterday();check($m->properties['ThresholdCt']===$accepted,'Repeated calculation cannot compound same day');
$m->attributes['LastAppliedDay']='';$GLOBALS['saveFailure']=true;$m->CalculateYesterday();
check($m->properties['ThresholdCt']===$accepted && $m->attributes['LastAppliedDay']==='', 'Failed threshold save retains value and permits retry');
$GLOBALS['saveFailure']=false;
$m->properties['MinimumReferenceDays']=14;$m->CalculateYesterday();
check($m->properties['ThresholdCt']===$accepted && str_contains($m->values['DailyStatus'],'need 14'),'Insufficient references retain threshold');
$history=$GLOBALS['history'];$GLOBALS['history'][25105][]=['TimeStamp'=>$b-2,'Value'=>-1];
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Counter reset accepted');$GLOBALS['history']=$history;
$GLOBALS['history'][57694][]=['TimeStamp'=>$a+120,'Value'=>-127];
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Failed sensor accepted');$GLOBALS['history']=$history;
$GLOBALS['history'][44860][]=['TimeStamp'=>$a+3600,'Value'=>true];
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Radiator contamination accepted');$GLOBALS['history']=$history;
$GLOBALS['history'][12594][]=['TimeStamp'=>$a+3600,'Value'=>11.0];
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Changed room target accepted');$GLOBALS['history']=$history;
$GLOBALS['history'][35931][]=['TimeStamp'=>$a+3600,'Value'=>true];
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Wrong manual mapping with opposite HP activity accepted');$GLOBALS['history']=$history;
$GLOBALS['history'][41085][]=['TimeStamp'=>$a+3600,'Value'=>1];
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Mixed-source day accepted');$GLOBALS['history']=$history;
$hpDate=$dayStart->modify('-1 day');$hpA=$hpDate->getTimestamp();$hpB=$dayStart->getTimestamp();
$GLOBALS['history'][19207][]=['TimeStamp'=>$hpA+3600,'Value'=>60.0];
throws(fn()=>(new LichtBlickHistory(14817,$hpA,$hpB))->day($c),'Different capacity accepted');$GLOBALS['history']=$history;
$GLOBALS['unlogged'][12594]=true;
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Unlogged target accepted');unset($GLOBALS['unlogged'][12594]);
$GLOBALS['saturated']=true;
throws(fn()=>(new LichtBlickHistory(14817,$a,$b))->day($c),'Truncated archive accepted');$GLOBALS['saturated']=false;
$hp=['source'=>1,'profile'=>'[10]','outside'=>5,'inside'=>[10],'hours'=>24,'degreeHours'=>120,'gasM3'=>0.2,'electricityKWh'=>40];
$gas=$hp;$gas['source']=2;$gas['gasM3']=10;$gas['electricityKWh']=4.896;
check(LichtBlickEconomics::candidate($hp,$gas,10.48,11.166973,0.204)!==null,'Plausible match');
$gas['outside']=7;check(LichtBlickEconomics::candidate($hp,$gas,10.48,11.166973,0.204)===null,'Different weather excluded');
// Weather curves combine an empirical reference with manufacturer/site priors.
$w=newModule();$wc=$w->properties;
$rows=LichtBlickWeatherModel::curve($wc['WeatherCurve']);
$points=[-7=>65,2=>50,7=>35,12=>35];
foreach ($points as $temperature=>$power) {
    $p=LichtBlickWeatherModel::estimate($wc,$temperature);
    check($p['powerPercent']===$power,'Weather knot power');
    check($p['systemCOP']<$p['cop'],'Fan overhead reduces system COP');
}
check(abs(LichtBlickWeatherModel::estimate($wc,2)['thresholdCt']-30)<1e-9,'Reference threshold preserved exactly');
$mid=LichtBlickWeatherModel::estimate($wc,4.5);
check($mid['powerPercent']===45 && $mid['thresholdCt']>30,'Interpolation and five-percent command quantization');
check(LichtBlickWeatherModel::estimate($wc,20)['cop']===7.26,'Warm end holds last COP, no extrapolation');
throws(fn()=>LichtBlickWeatherModel::estimate($wc,-7.01),'Cold extrapolation accepted');
$double=$wc;$double['GasPriceCt']*=2;
check(abs(LichtBlickWeatherModel::estimate($double,7)['thresholdCt']/LichtBlickWeatherModel::estimate($wc,7)['thresholdCt']-2)<1e-9,'Gas tariff change scales threshold');
foreach(['[]','[{"outsideC":2}]','[{"outsideC":2,"powerPercent":50,"cop":3,"heatPumpKW":2},{"outsideC":2,"powerPercent":35,"cop":4,"heatPumpKW":1}]'] as $bad) throws(fn()=>LichtBlickWeatherModel::curve($bad),'Bad curve accepted');
$w->properties['Enabled']=true;$w->properties['TariffVerified']=true;$w->properties['WeatherModelEnabled']=true;
$GLOBALS['liveVariables'][25911]=['VariableType'=>2,'VariableUpdated'=>time(),'value'=>7.0];
$w->response=quote(19,$start);$w->RefreshPrices();
check($w->values['RecommendedSource']===1 && $w->values['RecommendedHeatPumpPowerPercent']===35,'Live weather power in source recommendation');
$weatherPlan=json_decode($w->values['RecommendationJSON'],true);
check($weatherPlan['powerPercent']===35 && $weatherPlan['valid'] && $w->values['WeatherModelValid'],'Coherent weather source/power snapshot');
check($w->values['ReferenceThresholdCt']===30.0 && $w->values['ThresholdCt']>30,'Reference distinct from current weather threshold');
check(abs($w->values['EffectiveThresholdCt']/$w->values['ThresholdCt']-.75)<1e-9,'Margin applied once to weather threshold');
$old=$w->values['WeatherOutsideTemperatureC'];$GLOBALS['liveVariables'][25911]['value']=7.2;$w->EvaluatePrices();
check($w->values['WeatherOutsideTemperatureC']===$old,'Temperature deadband holds model point');
$GLOBALS['liveVariables'][25911]['value']=2.0;$w->EvaluatePrices();
check($w->values['RecommendedHeatPumpPowerPercent']===50 && abs($w->values['ThresholdCt']-30)<1e-9,'Temperature update changes power and crossover together');
$GLOBALS['liveVariables'][25911]['VariableUpdated']=time()-850;$w->EvaluatePrices();
check(json_decode($w->values['RecommendationJSON'],true)['validUntil']<=time()+50,'Snapshot expires with outside sensor');
$GLOBALS['liveVariables'][25911]['VariableUpdated']=time()-900;$w->EvaluatePrices();
check(!$w->values['RecommendationValid'] && $w->values['RecommendedSource']===2 && $w->values['PriceValid'],'Stale outside gas fallback distinct from valid tariff price');
check(!$w->values['WeatherModelValid'] && $w->values['WeatherHeatPumpPowerPercent']===0 && $w->values['RecommendedHeatPumpPowerPercent']===0,'Invalid weather clears old candidate/power');
$GLOBALS['liveVariables'][25911]=['VariableType'=>2,'VariableUpdated'=>time(),'value'=>-8.0];$w->EvaluatePrices();
check($w->values['RecommendedSource']===2&&!$w->values['WeatherModelValid'],'Below cold endpoint gas fallback');
$GLOBALS['liveVariables'][25911]['value']=7.0;$w->response=quote(100,$start);$w->RefreshPrices();
check($w->values['WeatherHeatPumpPowerPercent']===35 && $w->values['RecommendedHeatPumpPowerPercent']===0 && $w->values['RecommendedSource']===2,'Weather candidate visible while gas preferred');
$edited=json_decode($w->properties['WeatherCurve'],true);$edited[2]['powerPercent']=40;$w->properties['WeatherCurve']=json_encode($edited);$w->ApplyChanges();
check($w->values['WeatherHeatPumpPowerPercent']===40,'Edited curve applied');
$weatherBackup=$w->ExportConfig();$wb=json_decode($weatherBackup,true);
check($wb['configuration']['WeatherCurve']===$w->properties['WeatherCurve'],'Curve exported');
foreach(array_keys($wb['configuration']) as $key) if(str_starts_with($key,'Weather')||$key==='OutsideTemperatureMaxAgeSeconds')unset($wb['configuration'][$key]);
check(str_starts_with($w->ImportConfig(json_encode($wb)),'Imported')&&!$w->properties['WeatherModelEnabled'],'Old schema-1 backup gets weather-off defaults');
// Weather learner verifies varying command levels and normalizes a day's factor.
$GLOBALS['history']=$history;$weatherConfig=$c;$weatherConfig['WeatherModelEnabled']=true;
$expected=LichtBlickWeatherModel::estimate($weatherConfig,5.0);
foreach($GLOBALS['history'][19207] as &$r)$r['Value']=$expected['powerPercent'];unset($r);
$weatherDay=(new LichtBlickHistory(14817,$hpA,$hpB))->day($weatherConfig);
check(abs($weatherDay['weatherFactor']-$expected['factor'])<1e-9,'Weather learning factor weighted from HP operating intervals');
$GLOBALS['history'][19207][]=['TimeStamp'=>$hpA+3600,'Value'=>100];
throws(fn()=>(new LichtBlickHistory(14817,$hpA,$hpB))->day($weatherConfig),'Weather learner accepts wrong capacity');
$GLOBALS['history']=$history;
// End-to-end daily update saves the normalized reference, then applies weather and margin once.
$learn=newModule();$learn->properties['WeatherModelEnabled']=true;$learn->properties['DailyLearningEnabled']=true;
$learn->ApplyChanges();$c=$learn->properties;$GLOBALS['history']=[];
foreach(range(3,1) as $i){
    $d=$dayStart->modify('-'.$i.' days')->format('Y-m-d');addDay($c,$d,1);
}
foreach($GLOBALS['history'][19207] as &$r)$r['Value']=LichtBlickWeatherModel::estimate($c,5.0)['powerPercent'];unset($r);
foreach(range(3,1) as $i)check(str_starts_with($learn->AddReferenceDay($dayStart->modify('-'.$i.' days')->format('Y-m-d')),'Stored reference'),'Weather reference accepted');
addDay($c,$date,2);$learn->CalculateYesterday();
$learnedDays=json_decode($learn->attributes['ReferenceDays'],true);
$hpRef=$learnedDays[$dayStart->modify('-1 day')->format('Y-m-d')];
$raw=LichtBlickEconomics::candidate($hpRef,$learnedDays[$date],$c['GasPriceCt'],$c['GasKWhPerM3'],$c['ElectricalBackgroundKW']);
check(abs($learn->values['DailyCandidateCt']-$raw/$hpRef['weatherFactor'])<1e-9,'Daily candidate normalized to reference weather');
check(abs($learn->properties['ThresholdCt']-LichtBlickEconomics::smooth(30.0,$raw/$hpRef['weatherFactor']))<1e-9,'Normalized threshold saved to configuration');
$GLOBALS['liveVariables'][25911]=['VariableType'=>2,'VariableUpdated'=>time(),'value'=>7.0];$learn->EvaluatePrices();
check(abs($learn->values['ThresholdCt']-LichtBlickWeatherModel::estimate($learn->properties,7.0)['thresholdCt'])<1e-9,'Learned reference weather adjustment recomputed');
check(abs($learn->values['EffectiveThresholdCt']-$learn->values['ThresholdCt']*.75)<1e-9,'Learned weather margin applied once');
$form=json_decode(file_get_contents(__DIR__.'/../LichtBlickElectricityPrice/form.json'),true,32,JSON_THROW_ON_ERROR);
$fields=array_column($form['elements'],'name');check(in_array('SafetyMarginPercent',$fields,true),'Margin exposed in form.json');
echo "LichtBlick price, recommendation, restore, DST and guarded daily learning checks passed\n";
