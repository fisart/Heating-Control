<?php
declare(strict_types=1);

const IPS_KERNELMESSAGE = 10100;
function IPS_GetKernelRunlevel() {return $GLOBALS['runlevel']??10103;}

class IPSModule
{
    public int $InstanceID = 1;
    public array $properties = [], $attributes = [], $values = [], $buffers = [], $updates = [];
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyBoolean($n,$v) {$this->properties[$n]=$v;}
    public function RegisterPropertyFloat($n,$v) {$this->properties[$n]=$v;}
    public function RegisterPropertyInteger($n,$v) {$this->properties[$n]=$v;}
    public function RegisterPropertyString($n,$v) {$this->properties[$n]=$v;}
    public function RegisterAttributeString($n,$v) {$this->attributes[$n]=$v;}
    public function RegisterVariableBoolean($n,...$a) {$this->values[$n]=false;}
    public function RegisterVariableFloat($n,...$a) {$this->values[$n]=0.0;}
    public function RegisterVariableInteger($n,...$a) {$this->values[$n]=0;}
    public function RegisterVariableString($n,...$a) {$this->values[$n]='';}
    public function ReadPropertyBoolean($n) {return $this->properties[$n];}
    public function ReadPropertyFloat($n) {return (float)$this->properties[$n];}
    public function ReadPropertyInteger($n) {return $this->properties[$n];}
    public function ReadPropertyString($n) {return $this->properties[$n];}
    public function ReadAttributeString($n) {return $this->attributes[$n];}
    public function WriteAttributeString($n,$v) {$this->attributes[$n]=$v;}
    public function SetValue($n,$v) {$this->values[$n]=$v;}
    public function GetValue($n) {return $this->values[$n];}
    public function GetBuffer($n) {return $this->buffers[$n]??'';}
    public function SetBuffer($n,$v) {$this->buffers[$n]=$v;}
    public function RegisterMessage(...$a) {}
    public function RegisterTimer(...$a) {}
    public function SetTimerInterval($n,$v) {$this->updates['timer']=$v; $this->updates['timers'][$n]=$v;}
    public function SetStatus($v) {$this->updates['status']=$v;}
    public function UpdateFormField($n,$f,$v) {$this->updates[$n][$f]=$v;}
    public function ReloadForm() {}
}
function IPS_VariableProfileExists($name) {return true;}
function IPS_SemaphoreEnter(...$args) {return true;}
function IPS_SemaphoreLeave(...$args) {}
function IPS_SetProperty($id,$name,$value) {if ($GLOBALS['saveFailure']??false) return false; if($id===5){$GLOBALS['instanceProperties'][5][$name]=$value;return;} $GLOBALS['instance']->properties[$name]=$value;}
function IPS_SetConfiguration($id,$json) {$GLOBALS['instance']->properties=json_decode($json,true,32,JSON_THROW_ON_ERROR);}
function IPS_ApplyChanges($id) {if($id===5){$GLOBALS['hookApplies']=($GLOBALS['hookApplies']??0)+1;return;} $GLOBALS['instance']->ApplyChanges();}
function IPS_VariableExists($id) {return isset($GLOBALS['history'][$id]) || isset($GLOBALS['liveVariables'][$id]);}
function IPS_GetVariable($id) {return $GLOBALS['liveVariables'][$id];}
function GetValue($id) {return $GLOBALS['liveVariables'][$id]['value'];}
function AC_GetLoggingStatus($archive,$id) {return !isset($GLOBALS['unlogged'][$id]);}
function AC_GetLoggedValues($archive,$id,$a,$b,$limit) {
    if(isset($GLOBALS['archiveReader']))return ($GLOBALS['archiveReader'])($archive,$id,$a,$b,$limit);
    if (($GLOBALS['saturated']??false) && $limit===10000) return array_fill(0,10000,['TimeStamp'=>$a,'Value'=>0]);
    $rows=array_filter($GLOBALS['history'][$id]??[],fn($r)=>$r['TimeStamp'] >= $a && $r['TimeStamp'] <= $b);
    usort($rows,fn($x,$y)=>$y['TimeStamp'] <=> $x['TimeStamp']);
    return array_slice($rows,0,$limit);
}
require __DIR__.'/../LichtBlickElectricityPrice/module.php';
class FixturePrices extends LichtBlickElectricityPrice
{
    public string $response='';
    public bool $fail=false;
    protected function priceHistoryDirectory(): string {
        $base=$GLOBALS['priceFixtureDirectory']??=sys_get_temp_dir().'/lbep-fixture-'.bin2hex(random_bytes(6));
        return $base.'/'.spl_object_id($this);
    }
    protected function download(string $postcode): string {
        if ($this->fail) throw new RuntimeException('Fixture network outage');
        return $this->response;
    }
}
function check($condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function throws(callable $fn,string $message): void {
    try {$fn();} catch (Throwable $e) {return;}
    throw new RuntimeException($message);
}
function quote(float $price,int $start): string {
    return json_encode(['status'=>'ok','dataPoints'=>[['time'=>gmdate('Y-m-d\TH:i:s\Z',$start),'valueInCents'=>$price]]]);
}
function newModule(): FixturePrices {
    $m=new FixturePrices();$m->Create();$GLOBALS['instance']=$m;
    $m->properties['PostalCode']='10115';$m->properties['ThresholdCt']=30.0;
    $m->ApplyChanges();return $m;
}
register_shutdown_function(static function () {
    $base=$GLOBALS['priceFixtureDirectory']??null;if(!$base||!is_dir($base))return;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($base);
});

