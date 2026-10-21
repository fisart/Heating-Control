<?php
declare(strict_types=1);

/** Time-weighted archived control states. No current values, commands or archive writes. */
final class LichtBlickDailyHeatingUsage
{
    private const KEYS=['heatPumpOn','heatPumpHeating','gasAirPump'];
    private const LABELS=['heatPumpOn'=>'heat pump on/off','heatPumpHeating'=>'heat-pump heating mode','gasAirPump'=>'gas air-heating pump'];

    public static function analyse(int $from,int $to,array $series): array
    {
        if ($to<=$from) throw new InvalidArgumentException('Invalid heating-usage day');
        $changes=[$from=>[],$to=>[]];$state=[];
        foreach(self::KEYS as $key) {
            if (!isset($series[$key])||!is_array($series[$key])) throw new RuntimeException('A heating state history is missing');
            $seed=null;$values=$series[$key];ksort($values,SORT_NUMERIC);
            foreach($values as $at=>$value) {
                if(!is_int($at)||!is_bool($value)) throw new RuntimeException('Invalid archived heating state');
                if($at<=$from)$seed=$value;
                if($at>$from&&$at<$to)$changes[$at][$key]=$value;
            }
            if($seed===null)throw new RuntimeException('Historical heating state at the start of the day is unavailable');
            $state[$key]=$seed;
        }
        ksort($changes,SORT_NUMERIC);$last=$from;$hp=0;$gas=0;$overlap=0;
        foreach($changes as $at=>$updates) {
            $seconds=$at-$last;$heatPump=$state['heatPumpOn']&&$state['heatPumpHeating'];$gasPump=$state['gasAirPump'];
            if($heatPump&&$gasPump)$overlap+=$seconds;
            elseif($heatPump)$hp+=$seconds;
            elseif($gasPump)$gas+=$seconds;
            foreach($updates as $key=>$value)$state[$key]=$value;
            $last=$at;
        }
        $total=$hp+$gas;$day=$to-$from;$idle=$day-$total-$overlap;
        $percent=['heatPump'=>null,'gas'=>null,'idle'=>null];
        if($overlap===0) {
            // Allocate tenths of a percent so the displayed three-way split totals exactly 100.0%.
            $units=[];$remainders=[];
            foreach(['heatPump'=>$hp,'gas'=>$gas,'idle'=>$idle] as $key=>$seconds) {
                $exact=1000.*$seconds/$day;$units[$key]=(int)floor($exact);$remainders[$key]=$exact-$units[$key];
            }
            arsort($remainders,SORT_NUMERIC);$keys=array_keys($remainders);
            for($i=0,$left=1000-array_sum($units);$i<$left;$i++)$units[$keys[$i]]++;
            foreach($units as $key=>$value)$percent[$key]=$value/10.;
        }
        return ['available'=>$overlap===0,'daySeconds'=>$day,'heatPumpSeconds'=>$hp,'gasSeconds'=>$gas,'totalHeatingSeconds'=>$total,
            'idleSeconds'=>$idle,'overlapSeconds'=>$overlap,
            'heatPumpPercent'=>$percent['heatPump'],'gasPercent'=>$percent['gas'],'idlePercent'=>$percent['idle'],
            'reason'=>$overlap>0?'Overlapping heat-pump and gas on-states; a reliable percentage cannot be calculated.':($total===0?'No recorded air-heating operation on this day.':'')];
    }

    public static function read(int $archive,array $ids,int $from,int $to): array
    {
        $series=[];
        foreach(self::KEYS as $key) {
            $id=$ids[$key]??0;
            if(!is_int($id)||$id<=1||!IPS_VariableExists($id)||IPS_GetVariable($id)['VariableType']!==0||!AC_GetLoggingStatus($archive,$id))
                throw new RuntimeException('Archived on/off state or logging unavailable for '.self::LABELS[$key].' (#'.$id.').');
            $seed=AC_GetLoggedValues($archive,$id,0,$from,1);
            if(!is_array($seed)||!isset($seed[0]['TimeStamp'])||!is_int($seed[0]['TimeStamp'])||$seed[0]['TimeStamp']<0||$seed[0]['TimeStamp']>$from)
                throw new RuntimeException('No historical state at the start of the day for '.self::LABELS[$key].' (#'.$id.').');
            $start=$seed[0]['TimeStamp'];$values=[];$count=0;
            $normalise=static function(array $rows,int $a,int $b) use (&$values,&$count):void {
                foreach($rows as $row) {
                    if(++$count>100000)throw new RuntimeException('Heating archive exceeds the daily read limit');
                    $at=$row['TimeStamp']??null;$v=$row['Value']??null;
                    if(!is_int($at)||$at<$a||$at>$b||(!is_bool($v)&&(!is_numeric($v)||!is_finite((float)$v)||!in_array((float)$v,[0.,1.],true))))
                        throw new RuntimeException('Invalid archived heating state');
                    $v=is_bool($v)?$v:(float)$v===1.;
                    if(array_key_exists($at,$values)&&$values[$at]!==$v)throw new RuntimeException('Conflicting heating states at the same timestamp');
                    $values[$at]=$v;
                }
            };
            // Check the full boundary second too; a one-row seed alone could hide a conflict.
            $boundary=AC_GetLoggedValues($archive,$id,$start,$start,10000);
            if(!is_array($boundary)||!$boundary||count($boundary)>=10000)throw new RuntimeException('Heating boundary state is unavailable or ambiguous');
            $normalise($boundary,$start,$start);
            $read=function(int $a,int $b) use (&$read,$archive,$id,$normalise):void {
                if($a>$b)return;
                $rows=AC_GetLoggedValues($archive,$id,$a,$b,10000);
                if(!is_array($rows))throw new RuntimeException('Heating archive could not be read');
                if(count($rows)>=10000) {
                    if($a===$b)throw new RuntimeException('Heating archive contains too many same-second records');
                    unset($rows);$mid=$a+intdiv($b-$a,2);$read($a,$mid);$read($mid+1,$b);return;
                }
                $normalise($rows,$a,$b);
            };
            $read($from,$to-1);$series[$key]=$values;
        }
        return self::analyse($from,$to,$series);
    }
}
