<?php
declare(strict_types=1);

/** Separate water streams, dated original-script charges, no archive writes. */
final class LichtBlickWaterHistory
{
    public const KEYS=['house','garden','well'];
    public static function sources(string $json): array
    {
        if(strlen($json)>16384)throw new InvalidArgumentException('Water source configuration is too large');
        $sources=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($sources) || array_diff(array_keys($sources),self::KEYS))throw new InvalidArgumentException('Select house, garden and well water sources');
        foreach($sources as $key=>&$s){
            if(!is_array($s) || !is_int($s['counterID']??null) || $s['counterID']<0
                || !is_numeric($s['scaleM3']??null) || !is_finite((float)$s['scaleM3']) || $s['scaleM3']<=0 || $s['scaleM3']>100
                || !is_string($s['source']??null) || trim($s['source'])==='' || strlen($s['source'])>256)throw new InvalidArgumentException('Water meter ID, m3 scale and source are required');
            $s['scaleM3']=(float)$s['scaleM3'];
        }unset($s);
        $ids=array_filter(array_column($sources,'counterID'));
        if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Each water stream needs a distinct meter');
        return $sources;
    }
    public static function tariffs(string $json): array
    {
        if(strlen($json)>65536)throw new InvalidArgumentException('Water tariff history is too large');
        $rows=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($rows) || !array_is_list($rows) || count($rows)>100)throw new InvalidArgumentException('Use a list of dated water tariffs');
        $last=[];
        foreach($rows as &$r){
            if(!is_array($r) || !in_array($r['stream']??'', ['house','garden'],true) || !is_string($r['from']??null)
                || !array_key_exists('through',$r) || ($r['through']!==null && !is_string($r['through']))
                || !is_string($r['source']??null) || trim($r['source'])==='' || strlen($r['source'])>256)throw new InvalidArgumentException('Water tariff stream, dates and source are required');
            [$r['start']]=LichtBlickPriceStore::dates($r['from'],$r['from']);
            $r['end']=$r['through']===null?PHP_INT_MAX:LichtBlickPriceStore::dates($r['from'],$r['through'],2192)[1];
            if($r['start']<($last[$r['stream']]??0))throw new InvalidArgumentException('Water tariffs overlap or are not ordered per stream');
            foreach(['drinkingNetEuroM3','vatPercent','drinkingBasicNetEuroDay','sewageGrossEuroM3','sewageBasicGrossEuroDay','rainAreaPerDay','rainGrossRate'] as $key){
                if(!is_numeric($r[$key]??null) || !is_finite((float)$r[$key]) || $r[$key]<0 || $r[$key]>10000)throw new InvalidArgumentException('Invalid water tariff component: '.$key);
                $r[$key]=(float)$r[$key];
            }
            if($r['vatPercent']>100)throw new InvalidArgumentException('Invalid water VAT');
            if(isset($r['estimated']) && !is_bool($r['estimated']))throw new InvalidArgumentException('Water tariff estimate status must be boolean');
            if(isset($r['rainExcludeLeapDay']) && !is_bool($r['rainExcludeLeapDay']))throw new InvalidArgumentException('Rainwater leap-day rule must be boolean');
            $last[$r['stream']]=$r['end'];
        }unset($r);return $rows;
    }
    public static function invoices(string $json): array
    {
        if(strlen($json)>65536)throw new InvalidArgumentException('Water invoice references are too large');
        $rows=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>100)throw new InvalidArgumentException('Use a list of water invoice references');
        foreach($rows as &$r){
            foreach(['number','from','through','source'] as $key)if(!is_string($r[$key]??null)||trim($r[$key])===''||strlen($r[$key])>256)throw new InvalidArgumentException('Water invoice dates, number and source are required');
            [$r['start'],$r['end']]=LichtBlickPriceStore::dates($r['from'],$r['through'],2192);
            foreach(['mainM3','sewageM3','gardenDeductedM3','supplyGrossEuro','billedGrossEuro'] as $key)if(!is_numeric($r[$key]??null)||!is_finite((float)$r[$key])||$r[$key]<0||$r[$key]>100000)throw new InvalidArgumentException('Invalid water invoice reference value');
            if(abs($r['mainM3']-$r['sewageM3']-$r['gardenDeductedM3'])>1e-6)throw new InvalidArgumentException('Water invoice volume references do not reconcile');
        }unset($r);return $rows;
    }
    public static function days(int $from,int $to): array
    {
        $z=new DateTimeZone('Europe/Berlin');$d=(new DateTimeImmutable('@'.$from))->setTimezone($z)->setTime(0,0);$rows=[];
        while($d->getTimestamp()<$to){$next=$d->modify('+1 day');$rows[]=['start'=>max($from,$d->getTimestamp()),'end'=>min($to,$next->getTimestamp())];$d=$next;}return $rows;
    }
    public static function meter(int $archive,array $source,int $from,int $to): array
    {
        $adapter=new LichtBlickConsumptionHistory($archive,[]);$id=$source['counterID'];$events=$adapter->rows($id,$from,$to-1);
        $seed=AC_GetLoggedValues($archive,$id,0,$from-1,1);
        $previous=is_array($seed) && isset($seed[0]['Value']) && is_numeric($seed[0]['Value'])?(float)$seed[0]['Value']:null;
        if($previous!==null && (!is_finite($previous) || $previous<0))throw new RuntimeException('Invalid water meter baseline');
        $rows=[];$cursor=0;$stamps=array_keys($events);$count=count($stamps);
        foreach(self::days($from,$to) as $day){
            $known=$previous!==null;$sum=0.;$flags=$known?0:8;$n=0;
            while($cursor<$count && $stamps[$cursor]<$day['end']){
                $value=$events[$stamps[$cursor++]];$n++;
                if($value<0)throw new RuntimeException('Negative water counter');
                if($previous!==null){$delta=$value-$previous;if($delta<0)$flags|=1;elseif(!($flags&1))$sum+=$delta*$source['scaleM3'];}
                else $flags|=8;
                $previous=$value;
            }
            $rows[]=$day+['observedM3'=>$known&&!($flags&1)?$sum:null,'knownObservedM3'=>($known||$n>1)?$sum:null,'qualityFlags'=>$flags,'recordedEvents'=>$n];
        }return $rows;
    }
    public static function charges(array $points,array $tariffs,string $stream): array
    {
        $z=new DateTimeZone('Europe/Berlin');$rates=array_values(array_filter($tariffs,fn($r)=>$r['stream']===$stream));$cursor=0;
        foreach($points as &$p){
            $p['knownCostEuro']=null;$p['costEuro']=null;$p['variableCostEuro']=null;$p['fixedCostEuro']=null;
            if($stream==='well'){$p['knownCostEuro']=$p['costEuro']=$p['variableCostEuro']=$p['fixedCostEuro']=0.;continue;}
            while(isset($rates[$cursor]) && $rates[$cursor]['end']<=$p['start'])$cursor++;
            $r=$rates[$cursor]??null;
            if(!$r || $r['start']>$p['start'] || $r['end']<$p['end']){$p['qualityFlags']|=2048;continue;}
            $d=(new DateTimeImmutable('@'.$p['start']))->setTimezone($z)->setTime(0,0);
            $fraction=($p['end']-$p['start'])/($d->modify('+1 day')->getTimestamp()-$d->getTimestamp());
            $p['tariffEstimated']=$r['estimated']??false;if($p['tariffEstimated'])$p['qualityFlags']|=4096;
            $p['drinkingGrossEuroM3']=$r['drinkingNetEuroM3']*(1+$r['vatPercent']/100);
            $p['sewageGrossEuroM3']=$r['sewageGrossEuroM3'];
            $rain=(($r['rainExcludeLeapDay']??false) && $d->format('m-d')==='02-29')?0.:$r['rainAreaPerDay']*$r['rainGrossRate'];
            $p['fixedCostEuro']=($r['drinkingBasicNetEuroDay']*(1+$r['vatPercent']/100)+$r['sewageBasicGrossEuroDay']+$rain)*$fraction;
            if($p['observedM3']!==null)$p['variableCostEuro']=$p['observedM3']*($p['drinkingGrossEuroM3']+$r['sewageGrossEuroM3']);
            $p['knownCostEuro']=$p['fixedCostEuro']+($p['variableCostEuro']??0.);
            if($p['observedM3']!==null && (($p['confirmedNoConsumption']??false) || !($p['qualityFlags']&(1|8))))$p['costEuro']=$p['knownCostEuro'];
        }unset($p);return $points;
    }
    public static function separateHouse(array $house,array $garden): array
    {
        $by=[];foreach($garden as $p)$by[$p['start']]=$p;
        foreach($house as &$h){$h['mainMeterM3']=$h['observedM3'];$h['mainMeterQualityFlags']=$h['qualityFlags'];$g=$by[$h['start']]??null;
            if(!$g || $g['end']!==$h['end'] || $g['observedM3']===null || $h['observedM3']===null || (!($g['confirmedNoConsumption']??false)) && ($g['qualityFlags']&(1|8))){$h['observedM3']=null;$h['knownObservedM3']=null;$h['qualityFlags']|=256;continue;}
            $h['qualityFlags']|=$g['qualityFlags']&(1|8|16384);
            $difference=$h['observedM3']-$g['observedM3'];
            if($difference < -1e-8){$h['observedM3']=null;$h['knownObservedM3']=null;$h['qualityFlags']|=16384;}
            else $h['observedM3']=$h['knownObservedM3']=max(0.,$difference);
        }unset($h);return $house;
    }
    public static function report(array $streams,int $from,int $to,string $resolution,array $errors,array $tariffs,bool $includesGarden): array
    {
        $result=[];$buckets=[];$zone=new DateTimeZone('Europe/Berlin');
        foreach(self::KEYS as $key){$points=$streams[$key]??[];$total=['knownM3'=>0.,'knownCostEuro'=>0.,'knownVariableCostEuro'=>0.,'knownFixedCostEuro'=>0.,'missingVolumeDays'=>0,'incompleteCostDays'=>0,'volumeParts'=>0,'costParts'=>0,'knownMainMeterM3'=>0.,'mainMeterParts'=>0,'estimatedTariffDays'=>0];
            foreach($points as $p){if($p['tariffEstimated']??false)$total['estimatedTariffDays']++;if($key==='house' && is_numeric($p['mainMeterM3']??null)){$total['knownMainMeterM3']+=$p['mainMeterM3'];$total['mainMeterParts']++;}if(is_numeric($p['observedM3']??$p['knownObservedM3']??null))$total['volumeParts']++;if(is_numeric($p['knownCostEuro']??null))$total['costParts']++;$total['knownM3']+=$p['observedM3']??$p['knownObservedM3']??0.;if($p['observedM3']===null || (!($p['confirmedNoConsumption']??false) && ($p['qualityFlags']&(1|8|256|16384))))$total['missingVolumeDays']++;
                foreach(['knownCostEuro'=>'knownCostEuro','variableCostEuro'=>'knownVariableCostEuro','fixedCostEuro'=>'knownFixedCostEuro'] as $input=>$output)$total[$output]+=$p[$input]??0.;
                if(($p['costEuro']??null)===null)$total['incompleteCostDays']++;
                $date=(new DateTimeImmutable('@'.$p['start']))->setTimezone($zone);$bucket=match($resolution){'selected'=>'selected','monthly'=>$date->format('Y-m'),'yearly'=>$date->format('Y'),default=>$date->format('Y-m-d')};
                if(!isset($buckets[$bucket]))$buckets[$bucket]=['start'=>$p['start'],'end'=>$p['end'],'streams'=>[]];$b=&$buckets[$bucket];$b['end']=max($b['end'],$p['end']);
                if(!isset($b['streams'][$key]))$b['streams'][$key]=['knownM3'=>0.,'knownCostEuro'=>0.,'missingVolumeDays'=>0,'incompleteCostDays'=>0,'provisionalDays'=>0,'qualityFlags'=>0,'volumeParts'=>0,'costParts'=>0];$q=&$b['streams'][$key];
                if(is_numeric($p['observedM3']??$p['knownObservedM3']??null))$q['volumeParts']++;if(is_numeric($p['knownCostEuro']??null))$q['costParts']++;
                if($p['tariffEstimated']??false)$q['provisionalDays']++;
                $q['knownM3']+=$p['observedM3']??$p['knownObservedM3']??0.;$q['knownCostEuro']+=$p['knownCostEuro']??0.;$q['qualityFlags']|=$p['qualityFlags'];
                if($p['observedM3']===null || (!($p['confirmedNoConsumption']??false) && ($p['qualityFlags']&(1|8|256|16384))))$q['missingVolumeDays']++;
                if(($p['costEuro']??null)===null)$q['incompleteCostDays']++;unset($q,$b);
            }
            $total['completeVolume']=!isset($errors[$key]) && $points && $total['missingVolumeDays']===0;
            $total['completeCost']=($key==='well') || (!isset($errors[$key]) && $points && $total['incompleteCostDays']===0);
            if(!$total['mainMeterParts'])$total['knownMainMeterM3']=null;if(!$total['volumeParts'])$total['knownM3']=null;if(!$total['costParts'])$total['knownCostEuro']=null;
            $result[$key]=['totals'=>$total,'points'=>$points,'error'=>$errors[$key]??''];
        }
        foreach($buckets as &$bucket)foreach($bucket['streams'] as &$stream){if(!$stream['volumeParts'])$stream['knownM3']=null;if(!$stream['costParts'])$stream['knownCostEuro']=null;}unset($bucket,$stream);
        ksort($buckets);return ['from'=>$from,'to'=>$to,'resolution'=>$resolution,'streams'=>$result,'points'=>array_values($buckets),'tariffs'=>$tariffs,'houseIncludesGarden'=>$includesGarden];
    }
}

trait LichtBlickWaterHistoryAccess
{
    private function waterSettings(): array
    {
        return ['waterHistoryEnabled'=>$this->ReadPropertyBoolean('WaterHistoryEnabled'),'waterHouseIncludesGarden'=>$this->ReadPropertyBoolean('WaterHistoryHouseIncludesGarden')];
    }
    public function GetWaterHistory(string $from,string $through,string $resolution='auto'): string
    {
        return json_encode($this->waterHistoryChart($from,$through,$resolution),JSON_THROW_ON_ERROR);
    }
    private function waterHistoryChart(string $from,string $through,string $resolution): array
    {
        if(!in_array($resolution,['auto','selected','daily','monthly','yearly'],true))throw new InvalidArgumentException('Select a valid water resolution');
        [$a,$b]=LichtBlickPriceStore::dates($from,$through,2192);$end=min($b,time()+1);if($end<=$a)throw new InvalidArgumentException('Water period must start before now');
        if($resolution==='auto')$resolution=$b-$a>100*86400?'monthly':'daily';
        $sources=LichtBlickWaterHistory::sources($this->ReadPropertyString('WaterHistorySources'));$tariffs=LichtBlickWaterHistory::tariffs($this->ReadPropertyString('WaterHistoryTariffs'));
        $zeros=LichtBlickNoConsumption::periods($this->ReadPropertyString('ConsumptionZeroPeriods'));$streams=[];$errors=[];
        foreach(LichtBlickWaterHistory::KEYS as $key){try{
            if(!$this->ReadPropertyBoolean('WaterHistoryEnabled') || !isset($sources[$key]) || !$sources[$key]['counterID'])throw new RuntimeException('Water meter is not connected');
            $points=LichtBlickWaterHistory::meter($this->ReadPropertyInteger('ArchiveID'),$sources[$key],$a,$end);
            $streams[$key]=$points;
        }catch(RuntimeException $e){$errors[$key]=$key==='garden'?'Manual garden readings; no electronic meter':$e->getMessage();$streams[$key]=array_map(fn($d)=>$d+['observedM3'=>null,'knownObservedM3'=>null,'qualityFlags'=>8],LichtBlickWaterHistory::days($a,$end));}}
        foreach($streams as $key=>$points)$streams[$key]=LichtBlickNoConsumption::apply($points,$zeros,'water');
        $includes=$this->ReadPropertyBoolean('WaterHistoryHouseIncludesGarden');if($includes)$streams['house']=LichtBlickWaterHistory::separateHouse($streams['house'],$streams['garden']);
        foreach($streams as $key=>$points)$streams[$key]=LichtBlickWaterHistory::charges($points,$tariffs,$key);
        $report=LichtBlickWaterHistory::report($streams,$a,$end,$resolution,$errors,$tariffs,$includes);
        $periods=LichtBlickManualWater::periods($this->ReadPropertyString('WaterHistoryGardenPeriods'));
        $refs=$includes?LichtBlickManualWater::references($periods,$streams['house'],$tariffs,$a,$end,$streams['garden']):[];
        $report=LichtBlickManualWater::merge($report,$refs,false);
        $report['invoices']=[];foreach(LichtBlickWaterHistory::invoices($this->ReadPropertyString('WaterHistoryInvoicePeriods')) as $invoice)if($invoice['start']<$end && $invoice['end']>$a){$invoice['wholeInvoiceSelected']=$invoice['start']>=$a&&$invoice['end']<=$end;$report['invoices'][]=$invoice;}
        return $report;
    }
}
