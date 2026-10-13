<?php
declare(strict_types=1);

/** Reads verified daily water archives; sparse invoices never become daily readings. */
final class LichtBlickReviewedWater
{
    public static function metadata(int $id,int $archive,array $sources): array
    {
        if($id<=0||!IPS_VariableExists($id))throw new RuntimeException('Reviewed water metadata is not connected');
        $json=GetValue($id);if(!is_string($json)||strlen($json)>262144)throw new RuntimeException('Invalid reviewed water metadata');
        $d=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(($d['schema']??'')!=='Symcon.PreparedWaterHistory.v1'||($d['import_status']??'')!=='verified'||($d['archive_id']??0)!==$archive
            ||($d['target_variable_ids']['Metadata']??0)!==$id||!is_int($d['snapshot_to']??null)||($d['archive_read_end_epoch']??0)!==$d['snapshot_to']
            ||($d['policy']['reference_daily_interpolation_allowed']??true)!==false)throw new RuntimeException('Reviewed water identity, status or reference policy differs');
        foreach(['house'=>'Main','well'=>'Well'] as $key=>$prefix){
            if(($d['source_ids'][$key]??0)!==($sources[$key]['counterID']??0)||!isset($d['coverage'][$key]['from'],$d['coverage'][$key]['through']))throw new RuntimeException('Reviewed water source mapping differs');
            foreach(['CleanedCounterM3','ObservedDailyM3','QualityFlags'] as $suffix){$target=$d['target_variable_ids'][$prefix.$suffix]??0;if(!is_int($target)||$target<=0||!IPS_VariableExists($target)||!AC_GetLoggingStatus($archive,$target))throw new RuntimeException('Reviewed water archive is unavailable');}
        }
        $ids=$d['target_variable_ids'];if(count($ids)!==8||count(array_unique(array_values($ids)))!==8||array_intersect(array_values($d['source_ids']),array_values($ids)))throw new RuntimeException('Reviewed water IDs overlap or are incomplete');
        if(!is_array($d['invoice_comparisons']??null)||!$d['invoice_comparisons'])throw new RuntimeException('Reviewed water invoice references are missing');
        return $d;
    }
    public static function references(int $archive,array $data): void
    {
        $invoices=$data['invoice_comparisons'];[$first]=LichtBlickPriceStore::dates($invoices[0]['from'],$invoices[0]['from']);
        $adapter=new LichtBlickConsumptionHistory($archive,[]);$rows=$adapter->rows($data['target_variable_ids']['ReferenceMainCounterM3'],$first,$data['snapshot_to']);
        if(count($rows)!==count($invoices)+1||!isset($rows[$first])||abs($rows[$first])>1e-7)throw new RuntimeException('Water invoice-reference boundaries are missing');
        $previous=$first;$sum=0.;foreach($invoices as $r){[$a,$b]=LichtBlickPriceStore::dates($r['from'],$r['through'],2192);
            if($a!==$previous||!is_numeric($r['supplier_main_m3']??null)||$r['supplier_main_m3']<0)throw new RuntimeException('Water invoice-reference periods differ');
            $sum+=$r['supplier_main_m3'];if(!isset($rows[$b])||abs($rows[$b]-$sum)>1e-6)throw new RuntimeException('Water invoice-reference volumes differ');$previous=$b;
        }
    }
    public static function prepared(int $archive,array $data,string $key,int $from,int $to): array
    {
        $z=new DateTimeZone('Europe/Berlin');$prefix=$key==='house'?'Main':'Well';$ids=$data['target_variable_ids'];
        $first=(new DateTimeImmutable($data['coverage'][$key]['from'],$z))->getTimestamp();$cutoff=$data['snapshot_to'];
        $a=max($first,$from);$b=min($to,$cutoff+1);if($a>=$b)return [];
        $day=(new DateTimeImmutable('@'.$a))->setTimezone($z)->setTime(0,0);$start=$day->getTimestamp();
        $reader=new LichtBlickConsumptionHistory($archive,[]);$daily=$reader->rows($ids[$prefix.'ObservedDailyM3'],$start,min($b-1,$cutoff));
        $quality=$reader->rows($ids[$prefix.'QualityFlags'],$start,min($b-1,$cutoff));$counter=$reader->rows($ids[$prefix.'CleanedCounterM3'],$start,min($to,$cutoff));
        if(!isset($counter[$start]))throw new RuntimeException('Reviewed water cumulative baseline is missing');$previous=$counter[$start];$points=[];
        while($day->getTimestamp()<$b){$next=$day->modify('+1 day');$t=$day->getTimestamp();$end=min($next->getTimestamp(),$cutoff+1);$counterAt=min($end,$cutoff);
            $value=$daily[$t]??null;$flag=$quality[$t]??null;
            if($value===null||$value<0||$flag===null||$flag<0||$flag!==floor($flag)||!isset($counter[$counterAt])||abs($counter[$counterAt]-$previous-$value)>1e-6)throw new RuntimeException('Reviewed water daily/cumulative readback differs');
            $previous=$counter[$counterAt];$flags=(int)$flag;
            $points[]=['start'=>$t,'end'=>$end,'observedM3'=>($flags&(1|8|512|16384))?null:$value,'knownObservedM3'=>$value,'qualityFlags'=>$flags,'source'=>'reviewed-archive','reviewed'=>true];$day=$next;
        }return $points;
    }
    /** Later held counters mean zero changes, including normal winter well inactivity. */
    public static function later(int $archive,array $source,string $key,int $from,int $to): array
    {
        if($from>=$to)return [];$id=$source['counterID'];$reader=new LichtBlickConsumptionHistory($archive,[]);
        $seed=AC_GetLoggedValues($archive,$id,0,$from-1,1);if(!is_array($seed)||!isset($seed[0]['Value'])||!is_numeric($seed[0]['Value'])||!is_finite((float)$seed[0]['Value'])||$seed[0]['Value']<0)throw new RuntimeException('Later water baseline is missing');
        $previous=$high=(float)$seed[0]['Value'];$pending=null;$rows=[];$events=$reader->events($id,$from,$to-1);
        foreach(LichtBlickWaterHistory::days($from,$to) as $d){$sum=0.;$flags=1024;$n=0;
            while($events->valid()&&$events->key()<$d['end']){$t=$events->key();$v=$events->current();$n++;if($v<0)throw new RuntimeException('Invalid later water counter');
                if($key==='well'){
                    if($pending!==null){if($t-$pending['time']>10||abs($v-$pending['value'])>20)throw new RuntimeException('Unresolved later well zero/reset');$flags|=16;$pending=null;}
                    if($v===0.&&$previous>20){$pending=['time'=>$t,'value'=>$previous];$flags|=16;$events->next();continue;}
                    if($v<$high){if(($high-$v)*$source['scaleM3']>.020000001)throw new RuntimeException('Unresolved later well backward change');$flags|=32;}
                    else{$sum+=($v-$high)*$source['scaleM3'];$high=$v;}
                }else{if($v<$previous)$flags|=1;else $sum+=($v-$previous)*$source['scaleM3'];}
                $previous=$v;$events->next();
            }
            if($pending!==null)$flags|=8;
            $rows[]=$d+['observedM3'=>($flags&(1|8))?null:$sum,'knownObservedM3'=>$sum,'qualityFlags'=>$flags,'recordedEvents'=>$n,'source'=>'later-meter-archive'];
        }return $rows;
    }
    public static function joinDays(array $points): array
    {
        $z=new DateTimeZone('Europe/Berlin');$days=[];foreach($points as $p){$key=(new DateTimeImmutable('@'.$p['start']))->setTimezone($z)->format('Y-m-d');
            if(!isset($days[$key])){$days[$key]=$p;continue;}$d=&$days[$key];$d['end']=max($d['end'],$p['end']);$d['knownObservedM3']=($d['knownObservedM3']??0)+($p['knownObservedM3']??0);
            $d['observedM3']=$d['observedM3']===null||$p['observedM3']===null?null:$d['observedM3']+$p['observedM3'];$d['qualityFlags']|=$p['qualityFlags'];unset($d);
        }ksort($days);return array_values($days);
    }
}
