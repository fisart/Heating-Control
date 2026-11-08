<?php
declare(strict_types=1);
trait RoomClimateHistory
{
    private function history(array $query): array
    {
        $key=$query['room']??'';$range=$query['range']??'24h';
        if(!is_string($key)||!is_string($range)||!in_array($range,['1h','6h','24h','7d','30d'],true))throw new InvalidArgumentException('Choose a room and supported period.');
        $matches=array_values(array_filter($this->activeConfiguration(),static fn($r)=>$r['key']===$key));
        if(count($matches)!==1)throw new InvalidArgumentException('Room is not configured.');$room=$matches[0];
        $cache=json_decode($this->GetBuffer('History'),true)??[];$cacheKey=hash('sha256',self::canonical([$room,$range]));
        if(($cache['key']??'')===$cacheKey&&time()-($cache['time']??0)<30)return $cache['data'];
        $end=time();$start=$end-['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000][$range];$raw=in_array($range,['1h','6h','24h'],true);$series=[];
        foreach($room['sources'] as $s){
            $entry=$s+['points'=>[],'status'=>'not recorded','truncated'=>false,'resolution'=>$raw?'recorded':'hourly'];
            try{
                $id=$s['id'];$raw=$s['kind']==='target'||in_array($range,['1h','6h','24h'],true);$entry['resolution']=$raw?'recorded':'hourly';$archives=[];
                if(!IPS_VariableExists($id)||!in_array(IPS_GetVariable($id)['VariableType'],[1,2],true))throw new RuntimeException('Variable missing or nonnumeric.');
                foreach(IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}') as $a)if(AC_GetLoggingStatus($a,$id)&&AC_GetAggregationType($a,$id)===0)$archives[]=(int)$a;
                if(count($archives)!==1)throw new RuntimeException(count($archives)?'Multiple archives: select a unique archive configuration.':'Logging not enabled.');
                $rows=$raw?AC_GetLoggedValues($archives[0],$id,$start,$end,1001):AC_GetAggregatedValues($archives[0],$id,0,$start,$end,1001);
                if(!is_array($rows))throw new RuntimeException('Archive read failed.');$entry['truncated']=count($rows)>1000;$seen=[];
                foreach(array_slice($rows,0,1000) as $row){$t=$row['TimeStamp']??null;$n=$row[$raw?'Value':'Avg']??null;
                    if(!is_int($t)||$t<$start||$t>$end||isset($seen[$t])||!is_numeric($n)||!is_finite((float)$n))continue;$seen[$t]=true;
                    if($s['kind']==='humidity'?($n<0||$n>100):($n< -50||$n>100))continue;
                    $entry['points'][]=[$t,(float)$n];
                }
                if($s['kind']==='target'&&!$entry['truncated']){
                    $previous=AC_GetLoggedValues($archives[0],$id,0,$start-1,1);$p=$previous[0]??null;
                    if(is_array($p)&&is_int($p['TimeStamp']??null)&&$p['TimeStamp']<$start&&is_numeric($p['Value']??null)&&is_finite((float)$p['Value'])&&$p['Value']>=-50&&$p['Value']<=100)$entry['points'][]=[$start,(float)$p['Value'],$p['TimeStamp']];
                }
                usort($entry['points'],static fn($a,$b)=>$a[0]<=>$b[0]);$entry['status']=$entry['points']?'ok':'No recorded values in this period.';
            }catch(Throwable $e){$entry['status']=$e instanceof Error?'Archive API unavailable.':$e->getMessage();}
            $series[]=$entry;
        }
        $data=['room'=>$key,'name'=>$room['name'],'from'=>$start,'to'=>$end,'series'=>$series];$this->SetBuffer('History',json_encode(['key'=>$cacheKey,'time'=>time(),'data'=>$data],JSON_THROW_ON_ERROR));
        $this->debug('History '.$key.' '.$range.': '.count($series).' series.');return $data;
    }
}
