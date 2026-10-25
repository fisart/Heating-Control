<?php
declare(strict_types=1);

/** Retrospective source-energy accounting. No COP surface or control decisions. */
final class HeatPumpCostAnalysis
{
    private const CHANNELS=['counter','hpOn','heating','gasOn','fanOn','fanSpeed','incoming','outgoing'];

    public static function dates(string $from,string $through,int $now): array
    {
        $zone=new DateTimeZone('Europe/Berlin');$dates=[];
        foreach([$from,$through] as $text) {
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$text,$zone);
            if(!$d||$d->format('Y-m-d')!==$text)throw new InvalidArgumentException('Select valid start and end dates');
            $dates[]=$d;
        }
        [$a,$last]=$dates;$end=$last->modify('+1 day');
        if($last<$a||$a->diff($end)->days>31)throw new InvalidArgumentException('Select a period of 1 to 31 calendar days');
        if($a->getTimestamp()>=$now||$last->format('Y-m-d')>(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d'))
            throw new InvalidArgumentException('Select today or a completed past period');
        return [$a->getTimestamp(),min($end->getTimestamp(),$now)];
    }

    public static function emptyTotals(): array
    {
        return ['seconds'=>0,'energySeconds'=>0,'costSeconds'=>0,'heatSeconds'=>0,'matchedSeconds'=>0,
            'knownElectricityKWh'=>0.0,'knownHeatPumpCostEuro'=>0.0,'knownNetHeatKWh'=>0.0,
            'matchedElectricityKWh'=>0.0,'matchedHeatPumpCostEuro'=>0.0,'matchedNetHeatKWh'=>0.0,
            'matchedHeatingHeatKWh'=>0.0,'matchedRunOnHeatKWh'=>0.0,'matchedCoolingHeatKWh'=>0.0,
            'matchedEnabledCostEuro'=>0.0,'matchedOffCostEuro'=>0.0,'matchedUnknownStateCostEuro'=>0.0,
            'matchedGasCostEuro'=>0.0,'unverifiedPriceSeconds'=>0,'assumedGasPriceSeconds'=>0];
    }

    public static function totals(array $t): array
    {
        $paired=$t['matchedSeconds']>0;$nonnegative=$t['matchedNetHeatKWh']>=-1e-9;
        $gas=$paired&&$nonnegative?max(0.,$t['matchedGasCostEuro']):null;
        $hp=$paired?$t['matchedHeatPumpCostEuro']:null;
        $saving=$gas!==null?$gas-$hp:null;
        return $t+['electricityKWh'=>$t['energySeconds']===$t['seconds']?$t['knownElectricityKWh']:null,
            'heatPumpCostEuro'=>$t['costSeconds']===$t['seconds']?$t['knownHeatPumpCostEuro']:null,
            'netHeatKWh'=>$t['heatSeconds']===$t['seconds']?$t['knownNetHeatKWh']:null,
            'comparisonHeatPumpCostEuro'=>$hp,'equivalentGasCostEuro'=>$gas,'savingEuro'=>$saving,
            'savingPercent'=>$gas!==null&&$gas>0?$saving/$gas*100:null,
            'achievedCOP'=>$paired&&$t['matchedElectricityKWh']>0?$t['matchedNetHeatKWh']/$t['matchedElectricityKWh']:null,
            'comparisonCoveragePercent'=>$t['seconds']>0?$t['matchedSeconds']/$t['seconds']*100:0.,
            'completeComparison'=>$t['seconds']>0&&$t['matchedSeconds']===$t['seconds']&&$nonnegative,
            'provisional'=>$t['unverifiedPriceSeconds']>0||$t['assumedGasPriceSeconds']>0];
    }

    /** All maps use real archived timestamps. Temperature/state values are held
     * until their next recorded change; counters are interpolated only over
     * short, monotonic measured brackets. The shutdown gap is never filled. */
    public static function day(int $from,int $to,array $series,array $prices,array $gasRates,array $settings): array
    {
        $start=max(1,$from-7200);$events=[$start=>true,$from=>true,$to=>true];$times=[];$values=[];$cursor=[];
        foreach(self::CHANNELS as $key) {
            $map=$series[$key]??[];ksort($map,SORT_NUMERIC);
            $times[$key]=array_keys($map);$values[$key]=array_values($map);$cursor[$key]=-1;
            foreach($map as $at=>$v)if($at>$start&&$at<$to)$events[$at]=true;
            if(in_array($key,['incoming','outgoing'],true)&&$settings['temperatureMaxAge']>0)
                foreach($map as $at=>$v){$expiry=$at+$settings['temperatureMaxAge'];if($expiry>$from&&$expiry<$to)$events[$expiry]=true;}
        }
        if(count($events)>150000)throw new RuntimeException('Too many raw archive events for one analysis day');
        foreach($prices as $p)foreach(['start','end'] as $key)if($p[$key]>$from&&$p[$key]<$to)$events[$p[$key]]=true;
        foreach($gasRates as $p)foreach(['start','end'] as $key)if($p[$key]>$from&&$p[$key]<$to)$events[$p[$key]]=true;
        ksort($events,SORT_NUMERIC);$bounds=array_keys($events);$owner='unknown';$totals=self::emptyTotals();$issues=[];$priceCursor=0;$gasCursor=0;
        $issue=static function(string $why,int $seconds) use (&$issues): void {$issues[$why]=($issues[$why]??0)+$seconds;};
        foreach($bounds as $i=>$at) {
            if(!isset($bounds[$i+1]))break;$end=$bounds[$i+1];$state=[];$stamps=[];
            foreach(self::CHANNELS as $key) {
                while(isset($times[$key][$cursor[$key]+1])&&$times[$key][$cursor[$key]+1]<=$at)$cursor[$key]++;
                $n=$cursor[$key];$state[$key]=$n>=0?$values[$key][$n]:null;$stamps[$key]=$n>=0?$times[$key][$n]:0;
            }
            if($state['hpOn']===true&&$state['gasOn']===true)$owner='unknown';
            elseif($state['gasOn']===true)$owner='gas';
            elseif($state['hpOn']===true)$owner=$state['heating']===true?'hp':'other';
            elseif($state['hpOn']===null||$state['gasOn']===null)$owner='unknown';
            elseif($state['fanOn']===false)$owner='none';
            // A shared fan is not evidence that the heat pump is still
            // delivering heat. Retain only its positive residual heat after
            // shutdown, and end that ownership when the air rise reaches zero.
            // Do this during context replay too. Negative heat while the pump
            // is enabled in heating mode remains in the defrost/cycle balance.
            if($owner==='hp'&&$state['hpOn']===false&&$state['gasOn']===false
                &&$state['incoming']!==null&&$state['outgoing']!==null&&$state['outgoing']<=$state['incoming'])$owner='none';
            if($at<$from)continue;
            $seconds=$end-$at;$totals['seconds']+=$seconds;
            $energy=null;$j=$cursor['counter'];
            if($j>=0&&isset($times['counter'][$j+1])) {
                $a=$times['counter'][$j];$b=$times['counter'][$j+1];
                $v=$values['counter'][$j];$w=$values['counter'][$j+1];$delta=($w-$v)*$settings['counterScale'];
                if($v<0||$delta<0)$issue('Meter counter reset or invalid reading',$seconds);
                elseif($b-$a>180&&$delta>0)$issue('Meter logging gap exceeds 180 seconds',$seconds);
                elseif($delta>60*($b-$a)/3600+.05)$issue('Implausible meter increment',$seconds);
                else $energy=$delta*$seconds/($b-$a);
            } else $issue('Meter boundary unavailable',$seconds);
            if($energy!==null){$totals['energySeconds']+=$seconds;$totals['knownElectricityKWh']+=$energy;}
            while(isset($prices[$priceCursor])&&$prices[$priceCursor]['end']<=$at)$priceCursor++;
            $price=$prices[$priceCursor]??null;
            $validPrice=$price&&$price['start']<=$at&&$price['end']>=$end&&is_numeric($price['price']??null)&&is_finite((float)$price['price']);
            $cost=$energy===0.?0.:($energy!==null&&$validPrice?$energy*(float)$price['price']/100:null);
            if($energy!==null&&!$validPrice&&$energy>0)$issue('Historical electricity price missing',$seconds);
            if($cost!==null){$totals['costSeconds']+=$seconds;$totals['knownHeatPumpCostEuro']+=$cost;}
            if($validPrice&&$energy!==null&&$energy>0&&!($price['verified']??false))$totals['unverifiedPriceSeconds']+=$seconds;

            $heat=null;
            if($state['fanOn']===false)$heat=0.;
            elseif($owner==='other')$issue('Heat pump recorded outside heating mode',$seconds);
            elseif($state['fanOn']===true&&in_array($owner,['gas','none'],true))$heat=0.;
            elseif($state['fanOn']===true&&$owner==='hp') {
                if($state['fanSpeed']===null||abs($state['fanSpeed']-$settings['fanCommand'])>1e-6)$issue('Airflow unknown at this fan setting',$seconds);
                elseif($state['incoming']===null||$state['outgoing']===null)$issue('Inlet/outlet temperature history missing',$seconds);
                elseif($settings['temperatureMaxAge']>0&&($end-$stamps['incoming']>$settings['temperatureMaxAge']||$end-$stamps['outgoing']>$settings['temperatureMaxAge']))$issue('Temperature history exceeds configured age limit',$seconds);
                else $heat=.000335*$settings['airflowM3h']*($state['outgoing']-$state['incoming'])*$seconds/3600;
            } else $issue('Historical fan/source state or recovery attribution missing',$seconds);
            if($heat!==null){$totals['heatSeconds']+=$seconds;$totals['knownNetHeatKWh']+=$heat;}
            while(isset($gasRates[$gasCursor])&&$gasRates[$gasCursor]['end']<=$at)$gasCursor++;
            $rate=$gasRates[$gasCursor]??null;
            $gasPrice=$rate&&$rate['start']<=$at&&$rate['end']>=$end?$rate['unitGrossCt']:null;
            $assumed=false;
            if($gasPrice===null&&$settings['fallbackGasPriceCt']!==null){$gasPrice=$settings['fallbackGasPriceCt'];$assumed=true;}
            if($cost!==null&&$heat!==null&&($gasPrice!==null||$heat===0.)) {
                $totals['matchedSeconds']+=$seconds;$totals['matchedElectricityKWh']+=$energy;
                $totals['matchedHeatPumpCostEuro']+=$cost;$totals['matchedNetHeatKWh']+=$heat;
                if($heat<0)$totals['matchedCoolingHeatKWh']-=$heat;
                elseif($heat>0)$totals[$state['hpOn']===true?'matchedHeatingHeatKWh':'matchedRunOnHeatKWh']+=$heat;
                $totals[$state['hpOn']===true?'matchedEnabledCostEuro':($state['hpOn']===false?'matchedOffCostEuro':'matchedUnknownStateCostEuro')]+=$cost;
                $totals['matchedGasCostEuro']+=$heat===0.?0.:$heat/($settings['gasEfficiencyPercent']/100)*$gasPrice/100;
                if($assumed&&$heat!==0.)$totals['assumedGasPriceSeconds']+=$seconds;
            } elseif($heat!==null&&$heat!==0.&&$gasPrice===null)$issue('Gas tariff unavailable',$seconds);
        }
        if($totals['matchedNetHeatKWh']<0)$issue('Net delivered heat is negative in the matched period',$totals['matchedSeconds']);
        return ['from'=>$from,'to'=>$to,'totals'=>self::totals($totals),'issues'=>$issues];
    }
}

/** Bounded raw reads, independent of live output values or any archive writes. */
final class HeatPumpCostArchive
{
    public function __construct(private int $archive,private int $now) {}

    public function read(int $id,string $kind,int $from,int $to): array
    {
        if($id<=1||!IPS_VariableExists($id)||!AC_GetLoggingStatus($this->archive,$id))throw new RuntimeException('Archive logging unavailable for '.$kind.' (#'.$id.')');
        $type=IPS_GetVariable($id)['VariableType'];
        if(($kind==='state'&&$type!==0)||($kind!=='state'&&!in_array($type,[1,2],true)))throw new RuntimeException('Invalid variable type for '.$kind.' (#'.$id.')');
        $out=[];$count=0;
        $add=static function(array $rows,int $a,int $b) use (&$out,&$count,$kind): void {
            foreach($rows as $row) {
                if(++$count>100000)throw new RuntimeException('Daily archive read limit exceeded');
                $t=$row['TimeStamp']??null;$v=$row['Value']??null;
                if(!is_int($t)||$t<$a||$t>$b||(!is_bool($v)&&(!is_numeric($v)||!is_finite((float)$v))))throw new RuntimeException('Invalid archived '.$kind.' reading');
                if($kind==='state') {
                    if(!in_array($v,[true,false,0,1,0.,1.],true))throw new RuntimeException('Invalid archived on/off state');
                    $v=(bool)$v;
                } else {
                    $v=(float)$v;
                    if(($kind==='temperature'&&($v< -40||$v>100))||($kind==='counter'&&$v<0)||($kind==='fan'&&($v<0||$v>100)))throw new RuntimeException('Implausible archived '.$kind.' reading');
                }
                if(array_key_exists($t,$out)&&$out[$t]!==$v)throw new RuntimeException('Conflicting archived '.$kind.' values at one timestamp');
                $out[$t]=$v;
            }
        };
        $seed=AC_GetLoggedValues($this->archive,$id,0,$from,1);
        if(!is_array($seed))throw new RuntimeException('Archive seed read failed');
        if($seed) {
            $stamp=$seed[0]['TimeStamp']??null;
            if(!is_int($stamp)||$stamp<0||$stamp>$from)throw new RuntimeException('Invalid archive seed');
            $same=AC_GetLoggedValues($this->archive,$id,$stamp,$stamp,10000);
            if(!is_array($same)||!$same||count($same)>=10000)throw new RuntimeException('Archive seed is ambiguous');
            $add($same,$stamp,$stamp);
        }
        $read=function(int $a,int $b) use (&$read,$id,$add): void {
            if($a>$b)return;
            $rows=AC_GetLoggedValues($this->archive,$id,$a,$b,10000);
            if(!is_array($rows))throw new RuntimeException('Archive read failed');
            if(count($rows)>=10000) {
                if($a===$b)throw new RuntimeException('Too many same-second archive readings');
                $mid=$a+intdiv($b-$a,2);$read($a,$mid);$read($mid+1,$b);
            } else $add($rows,$a,$b);
        };
        for($a=$from,$end=min($to,$this->now);$a<=$end;$a=$b+1){$b=min($end,$a+21600-1);$read($a,$b);}
        ksort($out,SORT_NUMERIC);return $out;
    }
}
