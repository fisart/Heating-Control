<?php
declare(strict_types=1);
/** Small, per-room hourly means. No full source histories are sent to tiles. */
trait RoomClimateSparklines
{
    private function sparklines(array $query): array
    {
        $keys=$query['rooms']??null;
        if(!is_string($keys)||strlen($keys)>260)throw new InvalidArgumentException('Choose up to four configured rooms.');
        $keys=explode(',',$keys);
        if(count($keys)>4||count(array_unique($keys))!==count($keys))throw new InvalidArgumentException('Choose up to four different rooms.');
        $rooms=[];foreach($this->activeConfiguration() as $room)$rooms[$room['key']]=$room;
        foreach($keys as $key)if(!isset($rooms[$key]))throw new InvalidArgumentException('Room is not configured.');
        $results=[];foreach($keys as $key)$results[$key]=$this->sparkline($rooms[$key]);
        return ['rooms'=>(object)$results,'refresh'=>300];
    }
    private function sparkline(array $room): array
    {
        $active=[];foreach($room['sources'] as $s)if($s['kind']==='temperature'&&$this->reading($s['id'],'temperature')['status']==='ok')$active[]=$s['id'];
        $key=hash('sha256',self::canonical([$room,$active]));$buffer='Spark:'.hash('sha256',$room['key']);$cache=json_decode($this->GetBuffer($buffer),true)??[];
        if(($cache['key']??'')===$key&&time()-($cache['time']??0)<300)return $cache['data'];
        $end=time();$start=$end-86400;$buckets=[];$failed=0;$recorded=0;
        foreach($active as $id){
            try{
                $archives=[];
                foreach(IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}') as $a)if(AC_GetLoggingStatus($a,$id)===true&&AC_GetAggregationType($a,$id)===0)$archives[]=(int)$a;
                if(count($archives)!==1)continue;
                $rows=AC_GetAggregatedValues($archives[0],$id,0,(int)(floor($start/3600)*3600),$end,26);
                if(!is_array($rows))throw new RuntimeException('Archive response invalid.');$seen=[];$valid=false;
                foreach(array_slice($rows,0,26) as $row){
                    $t=$row['TimeStamp']??null;$n=$row['Avg']??null;
                    if(!is_int($t)||$t<$start||$t>$end||$t%3600!==0||!is_numeric($n)||!is_finite((float)$n)||$n< -50||$n>100||isset($seen[$t]))continue;
                    $seen[$t]=true;$buckets[$t][]=(float)$n;$valid=true;
                }if($valid)++$recorded;
            }catch(Throwable $e){++$failed;}
        }
        ksort($buckets,SORT_NUMERIC);$points=[];foreach($buckets as $t=>$values)$points[]=[(int)$t,round(array_sum($values)/count($values),2),count($values)];
        $status=$points?($failed||$recorded<count($active)?'partial':'ok'):($failed?'unavailable':'empty');
        $data=['from'=>$start,'to'=>$end,'points'=>$points,'status'=>$status];
        $this->SetBuffer($buffer,json_encode(['key'=>$key,'time'=>time(),'data'=>$data],JSON_THROW_ON_ERROR));return $data;
    }
}
