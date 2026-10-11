<?php
declare(strict_types=1);

class IPSModule
{
    public int $InstanceID = 1;
    public array $properties = [], $attributes = [], $values = [], $buffers = [], $updates = [];
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyBoolean($n,$v) {$this->properties[$n]=$v;}
    public function RegisterPropertyFloat($n,$v) {$this->properties[$n]=$v;}
    public function RegisterPropertyInteger($n,$v) {$this->properties[$n]=$v;}
    public function RegisterPropertyString($n,$v) {$this->properties[$n]=$v;}
    public function RegisterAttributeString($n,$v) {$this->attributes[$n]=$v;}
    public function RegisterVariableBoolean($n,...$a) {$this->values[$n]=false;}
    public function RegisterVariableFloat($n,...$a) {$this->values[$n]=0.0;}
    public function RegisterVariableInteger($n,...$a) {$this->values[$n]=0;}
    public function RegisterVariableString($n,...$a) {$this->values[$n]='';}
    public function ReadPropertyBoolean($n) {return $this->properties[$n];}
    public function ReadPropertyFloat($n) {return (float)$this->properties[$n];}
    public function ReadPropertyInteger($n) {return $this->properties[$n];}
    public function ReadPropertyString($n) {return $this->properties[$n];}
    public function ReadAttributeString($n) {return $this->attributes[$n];}
    public function WriteAttributeString($n,$v) {$this->attributes[$n]=$v;}
    public function SetValue($n,$v) {$this->values[$n]=$v;}
    public function GetValue($n) {return $this->values[$n];}
    public function GetBuffer($n) {return $this->buffers[$n]??'';}
    public function SetBuffer($n,$v) {$this->buffers[$n]=$v;}
    public function RegisterTimer(...$a) {}
    public function SetTimerInterval($n,$v) {$this->updates['timer']=$v;}
    public function SetStatus($v) {$this->updates['status']=$v;}
    public function UpdateFormField($n,$f,$v) {$this->updates[$n][$f]=$v;}
    public function ReloadForm() {}
}
function IPS_VariableProfileExists($name) {return true;}
function IPS_SemaphoreEnter(...$args) {return true;}
function IPS_SemaphoreLeave(...$args) {}
function IPS_SetProperty($id,$name,$value) {if ($GLOBALS['saveFailure']??false) return false; $GLOBALS['instance']->properties[$name]=$value;}
function IPS_SetConfiguration($id,$json) {$GLOBALS['instance']->properties=json_decode($json,true,32,JSON_THROW_ON_ERROR);}
function IPS_ApplyChanges($id) {$GLOBALS['instance']->ApplyChanges();}
function IPS_VariableExists($id) {return isset($GLOBALS['history'][$id]);}
function AC_GetLoggingStatus($archive,$id) {return !isset($GLOBALS['unlogged'][$id]);}
function AC_GetLoggedValues($archive,$id,$a,$b,$limit) {
    if (($GLOBALS['saturated']??false) && $limit===10000) return array_fill(0,10000,['TimeStamp'=>$a,'Value'=>0]);
    $rows=array_filter($GLOBALS['history'][$id]??[],fn($r)=>$r['TimeStamp'] >= $a && $r['TimeStamp'] <= $b);
    usort($rows,fn($x,$y)=>$y['TimeStamp'] <=> $x['TimeStamp']);
    return array_slice($rows,0,$limit);
}
require __DIR__.'/../LichtBlickElectricityPrice/module.php';
class FixturePrices extends LichtBlickElectricityPrice
{
    public string $response='';
    public bool $fail=false;
    protected function download(string $postcode): string {
        if ($this->fail) throw new RuntimeException('Fixture network outage');
        return $this->response;
    }
}
function check($condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function throws(callable $fn,string $message): void {
    try {$fn();} catch (Throwable $e) {return;}
    throw new RuntimeException($message);
}
function quote(float $price,int $start): string {
    return json_encode(['status'=>'ok','dataPoints'=>[['time'=>gmdate('Y-m-d\TH:i:s\Z',$start),'valueInCents'=>$price]]]);
}
function newModule(): FixturePrices {
    $m=new FixturePrices();$m->Create();$GLOBALS['instance']=$m;
    $m->properties['PostalCode']='10115';$m->properties['ThresholdCt']=30.0;
    $m->ApplyChanges();return $m;
}
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
$form=json_decode(file_get_contents(__DIR__.'/../LichtBlickElectricityPrice/form.json'),true,32,JSON_THROW_ON_ERROR);
$fields=array_column($form['elements'],'name');check(in_array('SafetyMarginPercent',$fields,true),'Margin exposed in form.json');
echo "LichtBlick price, recommendation, restore, DST and guarded daily learning checks passed\n";
