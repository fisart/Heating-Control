<?php
declare(strict_types=1);
const KR_READY=10103;const IPS_KERNELMESSAGE=10100;
class IPSModule
{
    public int $InstanceID=700;public array $properties=[],$attributes=[],$values=[],$timers=[];
    public function Create(){}public function ApplyChanges(){}
    public function RegisterPropertyBoolean($k,$v){$this->properties[$k]=$v;}public function RegisterPropertyInteger($k,$v){$this->properties[$k]=$v;}public function RegisterPropertyFloat($k,$v){$this->properties[$k]=$v;}public function RegisterPropertyString($k,$v){$this->properties[$k]=$v;}
    public function RegisterAttributeString($k,$v){$this->attributes[$k]=$v;}public function ReadAttributeString($k){return $this->attributes[$k];}public function WriteAttributeString($k,$v){$this->attributes[$k]=$v;}
    public function RegisterVariableString($k,...$args){$this->values[$k]='';}public function RegisterVariableBoolean($k,...$args){$this->values[$k]=false;}
    public function RegisterTimer($k,$v,...$args){$this->timers[$k]=$v;}public function SetTimerInterval($k,$v){$this->timers[$k]=$v;}public function RegisterMessage(...$args){}public function SetStatus($s){}public function UpdateFormField(...$args){}
    public function ReadPropertyString($k){return $this->properties[$k];}public function ReadPropertyBoolean($k){return $this->properties[$k];}public function ReadPropertyFloat($k){return $this->properties[$k];}public function ReadPropertyInteger($k){return $this->properties[$k];}
    public function GetValue($k){return $this->values[$k];}public function SetValue($k,$v){$this->values[$k]=$v;}
}
function IPS_GetKernelRunlevel(){return KR_READY;}function IPS_GetKernelStartTime(){return $GLOBALS['boot']??1234567;}
function IPS_GetConfiguration($id){return json_encode($id===700?$GLOBALS['vmc']->properties:$GLOBALS['instances'][$id]['p'],JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
function IPS_SetConfiguration($id,$json){$p=json_decode($json,true,64,JSON_THROW_ON_ERROR);if($id===700)$GLOBALS['vmc']->properties=$p;else $GLOBALS['instances'][$id]['p']=$p;}
function IPS_SetProperty($id,$key,$value){if($id===700)$GLOBALS['vmc']->properties[$key]=$value;else $GLOBALS['instances'][$id]['p'][$key]=$value;}
function IPS_GetProperty($id,$key){return $id===700?$GLOBALS['vmc']->properties[$key]:$GLOBALS['instances'][$id]['p'][$key];}
function IPS_ApplyChanges($id){if($id===700)$GLOBALS['vmc']->ApplyChanges();}
function IPS_InstanceExists($id){return $id===700||isset($GLOBALS['instances'][$id]);}
function IPS_GetInstance($id){return ['ModuleInfo'=>['ModuleID'=>$GLOBALS['instances'][$id]['module']??VentilationControl::MODULE]];}
function IPS_GetInstanceListByModuleID($id){return array_keys(array_filter($GLOBALS['instances']??[],fn($v)=>$v['module']===$id));}
function IPS_HasChanges($id){return $GLOBALS['pending'][$id]??false;}
function IPS_VariableExists($id){return isset($GLOBALS['vars'][$id]);}function IPS_GetVariable($id){return $GLOBALS['vars'][$id];}function GetValue($id){return $GLOBALS['vars'][$id]['value'];}
function IPS_GetObjectIDByIdent($ident,$id){return $GLOBALS['idents'][$id][$ident]??false;}
function IPS_SemaphoreEnter($name,$wait){if($GLOBALS['locks'][$name]??false)return false;$GLOBALS['locks'][$name]=true;return true;}
function IPS_SemaphoreLeave($name){$GLOBALS['locks'][$name]=false;}
function RequestAction($id,$value){$GLOBALS['commands'][]=[$id,$value];if(($GLOBALS['failID']??0)===$id)throw new RuntimeException('simulated device failure');if(!in_array($id,$GLOBALS['noAck']??[],true)){$GLOBALS['vars'][$id]['value']=$value;if($id===104)$GLOBALS['vars'][105]['value']=$value;}return true;}
function HC_ProcessHeating($id){}
function HC_SetModuleEnabled($id,$enabled){if(!IPS_SemaphoreEnter('HeatingControl.'.$id,1000))throw new RuntimeException('nested heating lock');try{$GLOBALS['calls'][]=$enabled;IPS_SetProperty($id,'Enabled',$enabled);if(!$enabled&&isset($GLOBALS['onPause']))($GLOBALS['onPause'])();}finally{IPS_SemaphoreLeave('HeatingControl.'.$id);}}
function SECB_GetNativePortalLoginURL($id,$path,$json){$s=json_decode($json,true);if(($s['HTTP_HOST']??'')!=='native.example'||($s['HTTPS']??'')!=='on')throw new RuntimeException('HTTPS origin rejected');return 'https://native.example/hook/secrets_frontend_901#return='.rawurlencode($path);}
function SECB_IsNativePortalAuthenticated($id){return $GLOBALS['authenticated']??false;}
require __DIR__.'/../VentilationControl/module.php';
function privateCall($m,$name,...$args){return(new ReflectionMethod($m,$name))->invoke($m,...$args);}
function v(int $id,$value,bool $action=false,int $updated=0){$GLOBALS['vars'][$id]=['VariableType'=>is_bool($value)?0:(is_int($value)?1:2),'VariableAction'=>$action?999:0,'VariableCustomAction'=>0,'VariableUpdated'=>$updated?:time(),'value'=>$value];}
function fixture(): VentilationControl
{
    $GLOBALS['vars']=[];$GLOBALS['locks']=[];$GLOBALS['pending']=[];$GLOBALS['commands']=[];$GLOBALS['calls']=[];$GLOBALS['noAck']=[];unset($GLOBALS['failID'],$GLOBALS['onPause']);$GLOBALS['boot']=1234567;
    $m=new VentilationControl();$GLOBALS['vmc']=$m;$m->Create();
    $h=['Enabled'=>true,'DryRun'=>false,'Rooms'=>json_encode([['name'=>'Room A','sensors'=>[301],'targetID'=>114,'flapID'=>201,'open'=>true,'closed'=>false],['name'=>'Room B','sensors'=>[303],'targetID'=>115,'flapID'=>202,'open'=>true,'closed'=>false]]),'HysteresisID'=>113,'FanOnID'=>100,'FanSpeedID'=>101,'HeatPumpOnID'=>102,'GasPumpID'=>103,'WinterID'=>108,'MasterDisableID'=>109,'OperatingModeID'=>110,'HolidayID'=>111,'NightDisableID'=>112,'CoolingModeValue'=>0];
    $GLOBALS['instances']=[500=>['module'=>'{8B333AC9-EE67-4D24-8CE7-433BBB391E6C}','p'=>$h],501=>['module'=>'{COOLING}','p'=>['VentilationActiveLink'=>106,'MainFanControlLink'=>100,'MainFanSpeedLink'=>101,'MainACOnOffLink'=>102,'WinterModeID'=>108]],900=>['module'=>'{52165EDD-B2C0-40EC-8A59-42A97319B6E2}','p'=>['Enabled'=>true,'FrontendInstanceID'=>901,'AdminOrigin'=>'https://native.example']],901=>['module'=>'{8D6AE8F7-EA3A-4513-AA14-C3F9613B0E83}','p'=>['Enabled'=>true,'BackendInstanceID'=>900,'AdminOrigin'=>'https://native.example']]];
    $GLOBALS['idents']=[500=>['ResidualHeating'=>601,'HeatPumpTestActive'=>602,'FlapCalibrationActive'=>603]];
    foreach([100=>false,101=>0,102=>false,103=>false,104=>true,105=>true,106=>false,107=>false,108=>true,109=>false,110=>2,111=>true,112=>false,113=>0.5,114=>10.0,115=>10.0,201=>false,202=>false,301=>20.0,302=>75.0,303=>22.0,304=>70.0,401=>5.0,402=>60.0,601=>false,602=>false,603=>false] as $id=>$value)v($id,$value,in_array($id,[100,101,104,106,201,202],true));
    $rooms=[];foreach([['a',301,302,201],['b',303,304,202]] as [$key,$temp,$humidity,$flap])$rooms[]=['key'=>$key,'name'=>'Room '.strtoupper($key),'floor'=>'Floor','sources'=>[['id'=>$temp,'kind'=>'temperature','label'=>'Room sensor'],['id'=>$humidity,'kind'=>'humidity','label'=>'Room sensor']],'temperatureID'=>$temp,'humidityID'=>$humidity,'coldTemperatureID'=>$temp,'surfaceTemperatureID'=>0,'minimumTemperature'=>8.0,'flapID'=>$flap,'enabled'=>true];
    foreach(['HeatingInstanceID'=>500,'CoolingInstanceID'=>501,'OutsideTemperatureID'=>401,'OutsideHumidityID'=>402,'AirSelectorID'=>104,'AirSelectorFeedbackID'=>105,'AirSelectorLockID'=>107,'VentilationActiveID'=>106,'Enabled'=>true,'LiveOutputs'=>true,'AirPathVerified'=>true,'BackendInstanceID'=>900,'Rooms'=>json_encode($rooms,JSON_PRESERVE_ZERO_FRACTION)] as $k=>$value)$m->properties[$k]=$value;
    return $m;
}
function agePhase(VentilationControl $m,int $age=130){$s=json_decode($m->attributes['Lease'],true);$s['phaseSince']=time()-$age;$m->attributes['Lease']=json_encode($s,JSON_PRESERVE_ZERO_FRACTION);}
function startRunning(VentilationControl $m,string $mode='outside'){$m->ManualStart($mode,300);$m->Tick();agePhase($m);$m->Tick();}
function finishStop(VentilationControl $m){$m->Stop();agePhase($m);$m->Tick();}
