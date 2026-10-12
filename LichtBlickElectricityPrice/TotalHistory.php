<?php
declare(strict_types=1);

/** Sums like units only; invoice adjustments remain outside dated supply costs. */
final class LichtBlickTotalHistory
{
    public const STREAMS=['electricity'=>'Strom','gas'=>'Gas','house'=>'Hauswasser','garden'=>'Gartenwasser','well'=>'Brunnenwasser'];
    public static function report(array $daily,int $from,int $to,string $resolution,array $errors): array
    {
        $z=new DateTimeZone('Europe/Berlin');$buckets=[];$totals=[];
        foreach(self::STREAMS as $key=>$label)$totals[$key]=['label'=>$label,'unit'=>in_array($key,['electricity','gas'],true)?'kWh':'m³',
            'knownVolume'=>0.,'knownCostEuro'=>0.,'volumeParts'=>0,'costParts'=>0,'incompleteVolumeDays'=>0,'incompleteCostDays'=>0,'provisionalDays'=>0,'error'=>$errors[$key]??''];
        foreach($daily as $row){$d=(new DateTimeImmutable('@'.$row['start']))->setTimezone($z);$key=match($resolution){'selected'=>'selected','monthly'=>$d->format('Y-m'),'yearly'=>$d->format('Y'),default=>$d->format('Y-m-d')};
            if(!isset($buckets[$key]))$buckets[$key]=['start'=>$row['start'],'end'=>$row['end'],'streams'=>[]];$b=&$buckets[$key];$b['end']=$row['end'];
            foreach(self::STREAMS as $stream=>$label){$s=$row['streams'][$stream];
                if(!isset($b['streams'][$stream]))$b['streams'][$stream]=['knownVolume'=>0.,'knownCostEuro'=>0.,'volumeParts'=>0,'costParts'=>0,'incompleteVolumeDays'=>0,'incompleteCostDays'=>0,'provisionalDays'=>0];
                if($s['provisional']??false){$b['streams'][$stream]['provisionalDays']++;$totals[$stream]['provisionalDays']++;}
                foreach(['volume'=>'knownVolume','cost'=>'knownCostEuro'] as $input=>$output)if(is_numeric($s[$input])){$b['streams'][$stream][$output]+=$s[$input];$totals[$stream][$output]+=$s[$input];$part=$input.'Parts';$b['streams'][$stream][$part]++;$totals[$stream][$part]++;}
                foreach(['volumeComplete'=>'incompleteVolumeDays','costComplete'=>'incompleteCostDays'] as $input=>$output)if(!$s[$input]){$b['streams'][$stream][$output]++;$totals[$stream][$output]++;}
            }unset($b);
        }
        foreach($buckets as &$b){$b['knownTotalCostEuro']=0.;$b['completeCost']=true;$b['knownEnergyKWh']=0.;$b['knownWaterM3']=0.;
            foreach($b['streams'] as $key=>&$s){foreach(['volumeParts'=>'knownVolume','costParts'=>'knownCostEuro'] as $parts=>$value)if(!$s[$parts])$s[$value]=null;
                $b['knownTotalCostEuro']+=$s['knownCostEuro']??0.;$b['completeCost']=$b['completeCost']&&$s['incompleteCostDays']===0;
                if(in_array($key,['electricity','gas'],true))$b['knownEnergyKWh']+=$s['knownVolume']??0.;else $b['knownWaterM3']+=$s['knownVolume']??0.;
            }unset($s);
        }unset($b);
        $cost=0.;$energy=0.;$water=0.;$complete=true;
        foreach($totals as $key=>&$t){foreach(['volumeParts'=>'knownVolume','costParts'=>'knownCostEuro'] as $parts=>$value)if(!$t[$parts])$t[$value]=null;
            $cost+=$t['knownCostEuro']??0.;$complete=$complete&&$t['incompleteCostDays']===0;
            if(in_array($key,['electricity','gas'],true))$energy+=$t['knownVolume']??0.;else $water+=$t['knownVolume']??0.;
        }unset($t);
        return ['from'=>$from,'to'=>$to,'resolution'=>$resolution,'streams'=>$totals,'points'=>array_values($buckets),
            'totals'=>['knownCostEuro'=>$cost,'completeCost'=>$complete,'knownEnergyKWh'=>$energy,'knownWaterM3'=>$water],
            'note'=>'Known dated supply costs. Invoice adjustments and assistance are separate. Energy kWh and water m3 are never added together.'];
    }
}
trait LichtBlickTotalHistoryAccess
{
    public function GetTotalConsumption(string $from,string $through,string $resolution='auto'): string
    {
        return json_encode($this->totalHistoryChart($from,$through,$resolution),JSON_THROW_ON_ERROR);
    }
    private function totalHistoryChart(string $from,string $through,string $resolution): array
    {
        if(!in_array($resolution,['auto','selected','daily','monthly','yearly'],true))throw new InvalidArgumentException('Select a valid total-consumption resolution');
        [$a,$b]=LichtBlickPriceStore::dates($from,$through,2192);$end=min($b,time()+1);if($end<=$a)throw new InvalidArgumentException('Period must start before now');
        if($resolution==='auto')$resolution=$b-$a>100*86400?'monthly':'daily';
        $source=[];$errors=[];
        foreach(['electricity','gas'] as $key)try{
            $settings=$key==='electricity'?$this->consumptionSettings():$this->gasHistorySettings();
            $start=$settings[$key==='electricity'?'consumptionHistoryFrom':'gasHistoryFrom']??'';
            $readFrom=$start!=='' && $from<$start?$start:$from;
            if($readFrom>$through)throw new RuntimeException('Selected period predates the reviewed history');
            $h=$key==='electricity'?$this->consumptionChart($readFrom,$through,'daily'):$this->gasHistoryChart($readFrom,$through,'daily');
            foreach($h['points'] as $p)$source[$key][(new DateTimeImmutable('@'.$p['start']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d')]=$p;
        }catch(RuntimeException|InvalidArgumentException $e){$errors[$key]=$e->getMessage();}
        $water=$this->waterHistoryChart($from,$through,'daily');
        foreach(LichtBlickWaterHistory::KEYS as $key){if($water['streams'][$key]['error']!=='')$errors[$key]=$water['streams'][$key]['error'];
            foreach($water['streams'][$key]['points'] as $p)$source[$key][(new DateTimeImmutable('@'.$p['start']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d')]=$p;
        }
        $rows=[];$z=new DateTimeZone('Europe/Berlin');
        foreach(LichtBlickWaterHistory::days($a,$end) as $day){$date=(new DateTimeImmutable('@'.$day['start']))->setTimezone($z)->format('Y-m-d');$row=$day+['streams'=>[]];
            foreach(LichtBlickTotalHistory::STREAMS as $key=>$label){$p=$source[$key][$date]??null;$s=['volume'=>null,'cost'=>null,'volumeComplete'=>false,'costComplete'=>false];
                if($p){$coverage=$p['start']<=$day['start'] && $p['end']>=$day['end'];
                    if($key==='electricity'){
                        $s['volume']=$p['knownReferenceKWh']??$p['referenceKWh'];
                        $s['cost']=($p['knownPriceIntervals']??0)>0?$p['knownTariffCostEuro']:($p['tariffCostEuro']??null);
                        $s['volumeComplete']=$coverage && !$p['missingEnergyIntervals'];$s['costComplete']=$s['volumeComplete'] && !$p['missingPriceIntervals'];
                    }elseif($key==='gas'){
                        $s['provisional']=($p['estimatedConversionDays']??0)>0;
                        $s['volume']=($p['missingTariffDays']??1)>0?null:$p['knownConvertedKWh'];
                        $s['cost']=($p['missingTariffDays']??1)>0?null:$p['knownCalculatedCostEuro'];
                        $s['volumeComplete']=$coverage && !$p['missingObservationDays'] && !$p['missingTariffDays'] && !$p['incompleteCostDays'];$s['costComplete']=$coverage && !$p['incompleteCostDays'];
                    }else{
                        $s['provisional']=$p['tariffEstimated']??false;
                        $s['volume']=$p['observedM3']??(($p['qualityFlags']&256)?null:($p['knownObservedM3']??null));$s['cost']=$p['knownCostEuro'];
                        $s['volumeComplete']=$coverage && $p['observedM3']!==null && (($p['confirmedNoConsumption']??false) || !($p['qualityFlags']&(1|8|256|16384)));$s['costComplete']=$coverage && $p['costEuro']!==null;
                    }
                }
                if($key==='well'){$s['cost']=0.;$s['costComplete']=true;}
                $row['streams'][$key]=$s;
            }$rows[]=$row;
        }
        $report=LichtBlickTotalHistory::report($rows,$a,$end,$resolution,$errors);
        $report['waterHouseIncludesGarden']=$water['houseIncludesGarden'];
        $report=LichtBlickManualWater::merge($report,$water['manualGardenPeriods']??[],true);
        $main=$water['streams']['house']['totals']['knownMainMeterM3']??null;
        $report['totals']['knownMainMeterM3']=$main;$report['totals']['knownSplitWaterM3']=$report['totals']['knownWaterM3'];
        if($water['houseIncludesGarden'] && $main!==null)$report['totals']['knownWaterM3']=$main+($report['streams']['well']['knownVolume']??0.);
        return $report;
    }
}
