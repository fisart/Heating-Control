<?php
declare(strict_types=1);

/** Review recorded choices, never simulate a past choice with today's model. */
final class HeatPumpDecisionAudit
{
    public static function review(int $from,int $to,array $series,array $exclusions,int $hpMode,int $gasMode): array
    {
        $held=static function(string $key,int $at) use ($series) {
            $value=null;foreach($series[$key]??[] as $stamp=>$v){if($stamp>$at)break;$value=$v;}return $value;
        };
        $events=[$from=>'Period start'];foreach($series['selectedMode']??[] as $stamp=>$value)if($stamp>$from&&$stamp<$to)$events[$stamp]='Selection change';
        foreach($series['fanOn']??[] as $stamp=>$value)if($value===true&&$stamp>$from&&$stamp<$to)$events[$stamp]='Fan-on start';
        ksort($events,SORT_NUMERIC);$rows=[];$previousMode=null;
        foreach($events as $at=>$rowType) {
            $mode=$held('selectedMode',$at);if($mode===$previousMode&&$rowType==='Selection change')continue;$previousMode=$mode;
            if($held('fanOn',$at)!==true)continue;
            $excluded=$held('testActive',$at)===true||$held('flapActive',$at)===true;
            foreach($exclusions as $p)if($p['from']<=$at&&$p['to']>$at)$excluded=true;
            if($excluded)continue;
            $actual=$mode===$hpMode*1.0||$mode===$hpMode?1:($mode===$gasMode*1.0||$mode===$gasMode?2:null);
            $r=['at'=>$at,'boundary'=>$at===$from,'rowType'=>$rowType,'selectedSource'=>$actual,'expectedSource'=>null,'priceCt'=>null,
                'entryCt'=>null,'exitCt'=>null,'expectedCOP'=>null,'powerPercent'=>null,'status'=>'Unverifiable','reason'=>'Archived decision or automatic-mode history unavailable'];
            if($held('automatic',$at)===false){$r['status']='Manual';$r['reason']='Manual selection; not judged as an automatic switching decision';}
            elseif($held('automatic',$at)===true) {
                $raw=$held('decision',$at);
                try{$d=is_string($raw)?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;}catch(Throwable $e){$d=null;}
                if(is_array($d)&&($d['schema']??0)===1&&is_int($d['updatedAt']??null)&&$d['updatedAt']<=$at) {
                    $r['reason']=(string)($d['reason']??'Recorded decision');
                    if(($d['valid']??false)===true&&is_int($d['validUntil']??null)&&$d['validUntil']>$at&&$d['validUntil']-$d['updatedAt']<=1800) {
                        foreach(['priceCt'=>'currentPriceCt','entryCt'=>'entryCt','exitCt'=>'exitCt'] as $dest=>$src)
                            if(is_numeric($d[$src]??null)&&is_finite((float)$d[$src]))$r[$dest]=(float)$d[$src];
                        $r['expectedCOP']=$d['point']['estimatedSystemCOP']??null;$r['powerPercent']=$d['powerPercent']??null;
                        if($r['priceCt']!==null&&$r['entryCt']!==null&&$r['exitCt']!==null) {
                            $prev=$d['previousEconomicSource']??null;
                            if(in_array($prev,[1,2],true))$r['expectedSource']=$r['priceCt']<($prev===1?$r['exitCt']:$r['entryCt'])?1:2;
                            elseif($r['priceCt']<$r['entryCt'])$r['expectedSource']=1;
                            elseif($r['priceCt']>=$r['exitCt'])$r['expectedSource']=2;
                            else $r['reason']='Price in hysteresis band; previous economic source was not archived';
                            if($r['expectedSource']!==null&&$actual!==null)$r['status']=$r['expectedSource']===$actual?'Matches recorded thresholds':'Selection differs from recorded thresholds';
                        }
                    }elseif(($d['valid']??null)===false&&($d['source']??null)===2&&$at-$d['updatedAt']<=180) {
                        $r['expectedSource']=2;
                        if($actual!==null)$r['status']=$actual===2?'Recorded gas fallback':'Selection differs from recorded fallback';
                    }else $r['reason']='Archived decision expired or incomplete';
                }
            }
            $rows[]=$r;if(count($rows)>=2000)break;
        }
        return $rows;
    }
}
