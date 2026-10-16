<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpMatrix.php';

/** Temperature-pattern quarantine, not a confirmed hardware defrost signal. */
final class HeatPumpTransient
{
    public static function policy(): array
    {
        return ['version'=>1,'preSeconds'=>60,'postSeconds'=>120,'recoveryHoldSeconds'=>120,
            'maximumRecoverySeconds'=>1200,'maximumStepExtensionSeconds'=>1800,'maximumEventsPerStep'=>3,
            'minimumAirRiseDropC'=>8.0,'minimumOutgoingDropC'=>6.0,'minimumCoilDropC'=>8.0,
            'minimumRelativeRiseDrop'=>0.4,'recoveryRiseFraction'=>0.8,'recoveryCoilLiftFraction'=>0.6,
            'recoveryRiseRangeC'=>2.0,'recoveryCoilRangeC'=>3.0];
    }
    public static function observe(array &$guard,array $samples,int $start,int $now): array
    {
        $p=self::policy();$guard['events']??=[];$messages=[];
        $current=end($samples);$rise=$current['outgoing']-$current['incoming'];$lift=$current['coil']-$current['incoming'];
        $i=count($guard['events'])-1;
        if($i>=0&&$guard['events'][$i]['endedAt']===null) {
            $event=&$guard['events'][$i];
            if($event['recoveredAt']===null&&$now-$event['detectedAt']>$p['maximumRecoverySeconds'])throw new RuntimeException('Temperature-transient recovery timed out after 20 minutes');
            $good=$rise>=max(5.0,$p['recoveryRiseFraction']*$event['referenceAirRise'])
                &&$lift>=max(5.0,$p['recoveryCoilLiftFraction']*$event['referenceCoilLift']);
            if(!$good) {
                $event['recoverySince']=null;$event['recoveredAt']=null;$event['releaseAt']=null;
            } else {
                $event['recoverySince']??=$now;
                $recent=array_values(array_filter($samples,static fn($r)=>$r['timestamp']>=max($event['recoverySince'],$now-$p['recoveryHoldSeconds'])));
                $rises=array_map(static fn($r)=>$r['outgoing']-$r['incoming'],$recent);$coils=array_column($recent,'coil');
                if(max($rises)-min($rises)>$p['recoveryRiseRangeC']||max($coils)-min($coils)>$p['recoveryCoilRangeC']) {
                    $event['recoverySince']=$now;$event['recoveredAt']=null;$event['releaseAt']=null;
                } elseif($event['recoveredAt']===null&&$now-$event['recoverySince']>=$p['recoveryHoldSeconds']) {
                    $event['recoveredAt']=$now;$event['releaseAt']=$now+$p['postSeconds'];
                    $messages[]=['kind'=>'transient_recovered','timestamp'=>$now,'event'=>$event];
                }
                if($event['releaseAt']!==null&&$now>=$event['releaseAt']) {
                    $event['endedAt']=$event['releaseAt'];$event['excludedSeconds']=$event['endedAt']-$event['startedAt'];
                    $messages[]=['kind'=>'transient_exclusion_closed','timestamp'=>$now,'event'=>$event];
                }
            }
            return $messages;
        }
        $after=$i>=0?$guard['events'][$i]['endedAt']:$start;
        $history=array_values(array_filter($samples,static fn($r)=>$r['timestamp']>=max($after,$now-300)&&$r['timestamp']<=$now-30));
        if(count($history)<6||end($history)['timestamp']-$history[0]['timestamp']<50) {
            // A second drop immediately after release must not wait for another
            // minute of warm samples before the already-known pattern is caught.
            if($i<0||$now-$after>300)return [];
            $previous=$guard['events'][$i];$incoming=$previous['referenceOutgoing']-$previous['referenceAirRise'];
            $reference=['timestamp'=>$after,'incoming'=>$incoming,'outgoing'=>$previous['referenceOutgoing'],'coil'=>$previous['referenceCoil']];
        } else {
            $ordered=$history;usort($ordered,static fn($a,$b)=>($a['outgoing']-$a['incoming'])<=>($b['outgoing']-$b['incoming']));
            $reference=$ordered[(int)floor(.8*(count($ordered)-1))];
        }
        $referenceRise=$reference['outgoing']-$reference['incoming'];$referenceLift=$reference['coil']-$reference['incoming'];
        if($referenceRise<5||$referenceLift<10||$referenceRise-$rise<max($p['minimumAirRiseDropC'],$p['minimumRelativeRiseDrop']*$referenceRise)
            ||$reference['outgoing']-$current['outgoing']<$p['minimumOutgoingDropC']||$reference['coil']-$current['coil']<$p['minimumCoilDropC'])return [];
        if(count($guard['events'])>=$p['maximumEventsPerStep'])throw new RuntimeException('More than three temperature transients in one power step');
        $lastHealthy=$reference['timestamp'];
        foreach($history as $row)if(abs(($row['outgoing']-$row['incoming'])-$referenceRise)<=max(2.0,.1*$referenceRise)
            &&abs($row['coil']-$reference['coil'])<=max(3.0,.1*$referenceLift))$lastHealthy=max($lastHealthy,$row['timestamp']);
        $event=['classification'=>'suspected-defrost-like-temperature-transient','confirmedDefrost'=>false,
            'startedAt'=>max($start,$lastHealthy-$p['preSeconds']),'onsetReferenceAt'=>$lastHealthy,'detectedAt'=>$now,
            'referenceAirRise'=>$referenceRise,'referenceCoilLift'=>$referenceLift,'referenceOutgoing'=>$reference['outgoing'],'referenceCoil'=>$reference['coil'],
            'detectedAirRise'=>$rise,'detectedOutgoing'=>$current['outgoing'],'detectedCoil'=>$current['coil'],
            'recoverySince'=>null,'recoveredAt'=>null,'releaseAt'=>null,'endedAt'=>null];
        $guard['events'][]=$event;
        return [['kind'=>'transient_exclusion_started','timestamp'=>$now,'event'=>$event]];
    }
    public static function active(array $guard): bool
    {
        return !empty($guard['events'])&&end($guard['events'])['endedAt']===null;
    }
    /** Complement of the union: overlapping safety brackets never double-count. */
    public static function intervals(array $guard,int $start,int $end): array
    {
        $excluded=[];
        foreach($guard['events']??[] as $e) {
            $a=max($start,$e['startedAt']);$b=min($end,$e['endedAt']??$end);
            if($b>$a)$excluded[]=[$a,$b];
        }
        usort($excluded,static fn($a,$b)=>$a[0]<=>$b[0]);$intervals=[];$cursor=$start;
        foreach($excluded as [$a,$b]){if($a>$cursor)$intervals[]=['startedAt'=>$cursor,'endedAt'=>$a];$cursor=max($cursor,$b);}
        if($cursor<$end)$intervals[]=['startedAt'=>$cursor,'endedAt'=>$end];
        return $intervals;
    }
    public static function measurementIntervals(array $point): array
    {
        $start=$point['stepStartedAt'];$end=$point['stepEndedAt'];
        if(!isset($point['measurementIntervals']))return [['startedAt'=>$start,'endedAt'=>$end]];
        $intervals=$point['measurementIntervals'];$previous=$start;
        if(!is_array($intervals)||!array_is_list($intervals)||!$intervals||count($intervals)>4)throw new RuntimeException('Invalid clean measurement intervals');
        foreach($intervals as $r) {
            if(!is_int($r['startedAt']??null)||!is_int($r['endedAt']??null)||$r['startedAt']<$previous||$r['endedAt']<=$r['startedAt']||$r['endedAt']>$end)
                throw new RuntimeException('Overlapping or invalid clean measurement interval');
            $previous=$r['endedAt'];
        }
        return $intervals;
    }
    public static function seconds(array $intervals): int
    {
        return array_sum(array_map(static fn($r)=>$r['endedAt']-$r['startedAt'],$intervals));
    }
    public static function mean(array $samples,string $key,array $intervals): float
    {
        $seconds=self::seconds($intervals);if($seconds<=0)throw new RuntimeException('No retained measurement time');$sum=0.0;
        foreach($intervals as $r)$sum+=HeatPumpMatrix::mean($samples,$key,$r['startedAt'],$r['endedAt'])*($r['endedAt']-$r['startedAt']);
        return $sum/$seconds;
    }
    public static function energy(array $records,array $intervals,float $scale): float
    {
        $sum=0.0;foreach($intervals as $r)$sum+=(HeatPumpMatrix::counterAt($records,$r['endedAt'])-HeatPumpMatrix::counterAt($records,$r['startedAt']))*$scale;
        return $sum;
    }
    public static function fullStep(array $point,float $gross,float $net,float $export,float $airflow): array
    {
        $seconds=$point['stepEndedAt']-$point['stepStartedAt'];
        $rise=$point['fullStepTemperatures']['outgoing']-$point['fullStepTemperatures']['incoming'];
        $heat=0.000335*$airflow*$rise*$seconds/3600;
        return ['measurementSeconds'=>$seconds,'grossElectricityKWh'=>$gross,'netElectricityKWh'=>$net,'exportElectricityKWh'=>$export,
            'estimatedHeatKWh'=>$heat,'estimatedSystemCOP'=>$net>0?$heat/$net:null,'airflowIsMeasured'=>false,
            'airflowM3h'=>$airflow,'includesExcludedPeriods'=>true]+$point['fullStepTemperatures'];
    }
}
