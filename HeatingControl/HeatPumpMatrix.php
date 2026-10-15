<?php
declare(strict_types=1);

/** Multi-power measured rows. Electrical and heat energy must cover the SAME interval. */
final class HeatPumpMatrix
{
    public static function seed(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-calibration.json'),true,64,JSON_THROW_ON_ERROR)['points'];
    }
    /** Apply only exact reviewed measurements; never relax future-run stability gates. */
    public static function applyPointReview(array $point): array
    {
        if(($point['electricalMethod']??'')!=='house-counter-minus-pretest-baseline'
            ||!is_bool($point['stable']??null)||!is_bool($point['usable']??null))return $point;
        foreach(self::seed() as $review) {
            if(!isset($review['reviewedSourcePointID'])||$review['reviewedSourcePointID']!==($point['pointID']??''))continue;
            foreach(['powerPercent','outside','incoming','outgoing','coil','airRise','coilLift','systemInputKW','estimatedHeatKW','estimatedSystemCOP','fanSpeed','airflowM3h'] as $key)
                if(!is_numeric($point[$key]??null)||abs((float)$point[$key]-(float)$review[$key])>0.00000001)return $point;
            if(isset($review['reviewedStability']))foreach($review['reviewedStability'] as $key=>$value)
                if(!is_numeric($point['stability'][$key]??null)||abs((float)$point['stability'][$key]-(float)$value)>0.00000001)return $point;
            $point['originalStable']=$point['stable'];$point['originalUsable']=$point['usable'];
            $point['stable']=$review['stable'];$point['usable']=$review['usable'];$point['reviewNote']=$review['reviewNote'];
            if(isset($review['reviewedPartialRunReason']))$point['reviewedPartialRunReason']=$review['reviewedPartialRunReason'];
            return $point;
        }
        return $point;
    }
    public static function validate(array $rows): array
    {
        if(!array_is_list($rows)||count($rows)>512)throw new InvalidArgumentException('Measured matrix needs a list of at most 512 points');
        foreach($rows as $r) {
            foreach(['powerPercent'=>[1,100],'outside'=>[-20,24],'incoming'=>[-10,40],'airRise'=>[0,70],
                'estimatedHeatKW'=>[0.01,80],'systemInputKW'=>[0.05,25],'estimatedSystemCOP'=>[0.5,10],'fanSpeed'=>[20,100],'airflowM3h'=>[100,10000]] as $k=>$limits) {
                $v=$r[$k]??null;
                if(!is_numeric($v)||!is_finite((float)$v)||$v<$limits[0]||$v>$limits[1])throw new InvalidArgumentException('Invalid matrix '.$k);
            }
            if(!is_int($r['powerPercent'])||!is_int($r['fanSpeed']))throw new InvalidArgumentException('Matrix commands must be integer percentages');
            if(!in_array($r['electricalMethod']??'',['house-counter-minus-pretest-baseline','dedicated-heatpump-import-counter'],true)||!is_bool($r['usable']??null)||!is_bool($r['stable']??null))throw new InvalidArgumentException('Matrix requires verified electrical provenance and stability flags');
            if($r['electricalMethod']==='dedicated-heatpump-import-counter') {
                foreach(['counterID','powerMeterID','exportCounterID'] as $key)if(!is_int($r[$key]??null)||$r[$key]<=1)throw new InvalidArgumentException('Missing dedicated-meter provenance: '.$key);
                foreach(['netElectricityKWh','exportElectricityKWh','measurementSeconds','counterScale'] as $key)if(!is_numeric($r[$key]??null)||!is_finite((float)$r[$key])||$r[$key]<0)throw new InvalidArgumentException('Invalid dedicated-meter energy provenance: '.$key);
                if(($r['counterFinalized']??false)!==true||($r['baselineSubtracted']??null)!==false||$r['netElectricityKWh']<=0||$r['measurementSeconds']<=0||$r['counterScale']<=0||$r['counterScale']>1||$r['exportElectricityKWh']>0.001+1e-9
                    ||abs($r['systemInputKW']-$r['netElectricityKWh']*3600/$r['measurementSeconds'])>0.001)throw new InvalidArgumentException('Dedicated-meter energy/provenance does not match input power');
            }
            if(abs($r['estimatedHeatKW']-$r['estimatedSystemCOP']*$r['systemInputKW'])>0.01)throw new InvalidArgumentException('Matrix COP does not match heat and electrical input');
        }
        return $rows;
    }
    public static function choose(array $rows,float $outside,float $incoming,float $target,float $margin,float $minRise,int $fan,float $airflow,float $tolerance,int $excludedFlap=0): ?array
    {
        if($outside < -20||$outside>24)return null;
        $nearest=[];
        foreach(self::validate($rows) as $r) {
            if(!$r['usable']||!$r['stable']||($excludedFlap>0&&isset($r['excludedFlapID'])&&$r['excludedFlapID']!==$excludedFlap)||$r['fanSpeed']!==$fan||abs($r['airflowM3h']-$airflow)>1
                ||abs($r['outside']-$outside)>$tolerance||abs($r['incoming']-$incoming)>$tolerance)continue;
            $r['distance']=abs($r['outside']-$outside)+abs($r['incoming']-$incoming);
            $key=$r['powerPercent'];
            if(!isset($nearest[$key])||$r['distance']<$nearest[$key]['distance']
                ||($r['distance']===$nearest[$key]['distance']&&($r['measuredAt']??0)>($nearest[$key]['measuredAt']??0)))$nearest[$key]=$r;
        }
        $candidates=[];
        foreach($nearest as $r) {
            $r['predictedOutgoing']=$incoming+$r['airRise'];
            if($r['airRise']<$minRise||$r['predictedOutgoing']<$target+$margin)continue;
            $candidates[]=$r;
        }
        // Best estimated COP among points satisfying the supply/demand requirements.
        usort($candidates,static fn($a,$b)=>($b['estimatedSystemCOP']<=>$a['estimatedSystemCOP'])?:($a['powerPercent']<=>$b['powerPercent']));
        return $candidates[0]??null;
    }
    /** Explain a failed match without relaxing any selection criterion. */
    public static function unavailableReason(array $rows,float $outside,float $incoming,float $target,float $margin,float $minRise,int $fan,float $airflow,float $tolerance,int $excludedFlap=0): string
    {
        if($outside < -20||$outside>24)return sprintf('Outside air %.1f°C is outside the supported operating range (−20 to 24°C)',$outside);
        $rows=array_values(array_filter(self::validate($rows),static fn($r)=>$r['usable']&&$r['stable']));
        if(!$rows)return 'No stable, usable electricity-meter calibration points are available';
        $steps=[
            [static fn($r)=>$r['fanSpeed']===$fan,static fn($r)=>sprintf('Requested fan speed %d%% does not match the tested fan speed(s): %s%%',$fan,implode('%, ',array_unique(array_column($r,'fanSpeed'))))],
            [static fn($r)=>abs($r['airflowM3h']-$airflow)<=1,static fn($r)=>sprintf('Configured airflow %.0f m³/h does not match the tested airflow(s): %s m³/h',$airflow,implode(', ',array_unique(array_column($r,'airflowM3h'))))],
            [static fn($r)=>abs($r['outside']-$outside)<=$tolerance,static fn($r)=>sprintf('Outside air %.1f°C has no tested point within ±%.1f°C; tested outside temperatures %.1f–%.1f°C',$outside,$tolerance,min(array_column($r,'outside')),max(array_column($r,'outside')))],
            [static fn($r)=>abs($r['incoming']-$incoming)<=$tolerance,static fn($r)=>sprintf('Intake air %.1f°C has no matching tested point within ±%.1f°C at this outside temperature; tested intake temperatures %.1f–%.1f°C',$incoming,$tolerance,min(array_column($r,'incoming')),max(array_column($r,'incoming')))],
            [static fn($r)=>!($excludedFlap>0&&isset($r['excludedFlapID'])&&$r['excludedFlapID']!==$excludedFlap),static fn($r)=>'The excluded room flap differs from the tested calibration configuration'],
        ];
        foreach($steps as [$accept,$explain]) {
            $matched=array_values(array_filter($rows,$accept));
            if(!$matched)return $explain($rows);
            $rows=$matched;
        }
        // Mirror the nearest-point-per-power rule used by choose().
        $nearest=[];
        foreach($rows as $r){$r['distance']=abs($r['outside']-$outside)+abs($r['incoming']-$incoming);$key=$r['powerPercent'];
            if(!isset($nearest[$key])||$r['distance']<$nearest[$key]['distance']
                ||($r['distance']===$nearest[$key]['distance']&&($r['measuredAt']??0)>($nearest[$key]['measuredAt']??0)))$nearest[$key]=$r;}
        $rise=max(array_column(array_values($nearest),'airRise'));
        return sprintf('Matched test points cannot meet the air-supply requirement: required supply ≥%.1f°C (room target %.1f°C + %.1f°C margin) and air rise ≥%.1f°C; highest predicted supply %.1f°C, largest measured rise %.1f°C',$target+$margin,$target,$margin,$minRise,$incoming+$rise,$rise);
    }
    public static function economics(array $point,float $gasCt,float $gasEfficiency,float $safety,float $band,float $price,int $previous): array
    {
        foreach([$gasCt,$gasEfficiency,$safety,$band,$price] as $v)if(!is_finite($v))throw new InvalidArgumentException('Nonfinite economic input');
        if($gasCt<=0||$gasEfficiency<50||$gasEfficiency>100||$safety<0||$safety>95||$band<0||$band>100)throw new InvalidArgumentException('Invalid economic configuration');
        $breakEven=$gasCt*$point['estimatedSystemCOP']/($gasEfficiency/100);
        $exit=$breakEven*(1-$safety/100);$entry=$exit-$band;
        return ['breakEvenCt'=>$breakEven,'exitCt'=>$exit,'entryCt'=>$entry,'breakEvenEuro'=>$breakEven/100,'exitEuro'=>$exit/100,'entryEuro'=>$entry/100,
            'source'=>$price<($previous===1?$exit:$entry)?1:2,'gasPriceCt'=>$gasCt,'gasEfficiencyPercent'=>$gasEfficiency,
            'safetyMarginPercent'=>$safety,'hysteresisCt'=>$band,'currentPriceCt'=>$price,
            'electricalMethod'=>$point['electricalMethod']??'unknown','meterScope'=>$point['meterScope']??'household increment above baseline',
            'assumptions'=>($point['copMethod']??'')==='surface'
                ?'Provisional modeled COP from frozen house-meter tests; full-step air-rise capacity interpolated from supporting observations; estimated airflow; gas auxiliary electricity receives no credit.'
                :(($point['electricalMethod']??'')==='dedicated-heatpump-import-counter'
                ?'Estimated airflow; dedicated circuit only, no household/standby subtraction; shared fan and other equipment outside this circuit are excluded; gas auxiliary electricity receives no credit.'
                :'Estimated airflow; gas auxiliary electricity receives no credit; changing other house loads affects baseline subtraction.')];
    }
    public static function merge(array $existing,array $new): array
    {
        $rows=[];foreach(array_merge($existing,$new) as $r)$rows[$r['pointID']]=$r;
        return array_slice(array_values($rows),-512);
    }
    /** Integrate held telemetry with its original timestamps; reject uncovered/gapped data. */
    public static function mean(array $samples,string $key,int $start,int $end): float
    {
        usort($samples,static fn($a,$b)=>$a['timestamp']<=>$b['timestamp']);
        $last=null;$at=$start;$sum=0.0;
        foreach($samples as $r) {
            $t=$r['timestamp'];if($t<=$start){$last=$r;continue;}if($t>$end)break;
            if($last===null||$t-$at>30)throw new RuntimeException('Incomplete heat measurement interval');
            $sum+=(float)$last[$key]*($t-$at);$at=$t;$last=$r;
        }
        if($last===null||$end-$at>30)throw new RuntimeException('Incomplete heat measurement boundary');
        return ($sum+(float)$last[$key]*($end-$at))/($end-$start);
    }
    public static function counterAt(array $rows,int $time): float
    {
        usort($rows,static fn($a,$b)=>$a['TimeStamp']<=>$b['TimeStamp']);$previous=null;
        foreach($rows as $r) {
            if($r['TimeStamp']===$time)return (float)$r['Value'];
            if($r['TimeStamp']>$time) {
                if(!$previous||$r['TimeStamp']-$previous['TimeStamp']>90||$r['Value']<$previous['Value'])break;
                return $previous['Value']+($r['Value']-$previous['Value'])*($time-$previous['TimeStamp'])/($r['TimeStamp']-$previous['TimeStamp']);
            }
            $previous=$r;
        }
        throw new RuntimeException('Electricity counter boundary missing, reset or gap exceeds 90 seconds');
    }
}
