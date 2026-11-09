<?php
declare(strict_types=1);
trait VentilationConfiguration
{
    private const BOOLS=['Enabled'=>false,'WebEnabled'=>true,'DebugEnabled'=>false,'LiveOutputs'=>false,'AutomaticEnabled'=>false,'AutomaticRecirculation'=>false,'AirSelectorVerified'=>true,'AirPathVerified'=>false,'AllowHoliday24h'=>true];
    private const INTS=['HeatingInstanceID'=>11543,'BackendInstanceID'=>0,'PortalInstanceID'=>0,'DocumentationInstanceID'=>0,'BackupInstanceID'=>0,'OutsideTemperatureID'=>17059,'OutsideHumidityID'=>38406,'AirSelectorID'=>44949,'AirSelectorFeedbackID'=>53295,'AirSelectorLockID'=>11048,'VentilationActiveID'=>24607,'CoolingInstanceID'=>49353,'MaxSensorAgeSeconds'=>3600,'EvaluationSeconds'=>30,'QualificationSeconds'=>900,'CycleSeconds'=>300,'MaxCycleSeconds'=>600,'CooldownSeconds'=>1800,'DamperWaitSeconds'=>120,'FeedbackTimeoutSeconds'=>180,'FanPercent'=>30,'StartMinute'=>420,'EndMinute'=>1320];
    private const FLOATS=['StartHumidity'=>60.0,'StopHumidity'=>55.0,'StartMoistureExcess'=>2.0,'StopDryingPotential'=>0.5,'MaximumColdHumidity'=>75.0,'MaximumTemperatureDrop'=>2.0,'MinimumOutsideTemperature'=>-5.0,'RecirculationSpread'=>2.0];
    private static function json($v): string {return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    private static function canonical($v): string
    {
        $sort=function($x)use(&$sort){if(!is_array($x))return $x;if(!array_is_list($x))ksort($x,SORT_STRING);foreach($x as &$a)$a=$sort($a);unset($a);return $x;};return self::json($sort($v));
    }
    private function properties(): array {return json_decode(IPS_GetConfiguration($this->InstanceID),true,64,JSON_THROW_ON_ERROR);}
    private static function validate(array $p): array
    {
        $types=[];foreach(self::BOOLS as $k=>$_)$types[$k]='boolean';foreach(self::INTS as $k=>$_)$types[$k]='integer';foreach(self::FLOATS as $k=>$_)$types[$k]='double';$types['Rooms']='string';
        if(array_diff(array_keys($p),array_keys($types))||array_diff(array_keys($types),array_keys($p)))throw new InvalidArgumentException('Configuration fields do not match VentilationControl.v1.');
        foreach($types as $k=>$t)if(gettype($p[$k])!==$t)throw new InvalidArgumentException('Invalid type for '.$k.'.');
        foreach(self::INTS as $k=>$_)if($p[$k]<0)throw new InvalidArgumentException('Negative setting: '.$k);
        $ranges=['MaxSensorAgeSeconds'=>[60,86400],'EvaluationSeconds'=>[5,300],'QualificationSeconds'=>[0,86400],'CycleSeconds'=>[30,3600],'MaxCycleSeconds'=>[30,3600],'CooldownSeconds'=>[60,86400],'DamperWaitSeconds'=>[1,600],'FeedbackTimeoutSeconds'=>[5,600],'FanPercent'=>[1,100],'StartMinute'=>[0,1439],'EndMinute'=>[0,1439]];
        foreach($ranges as $k=>[$a,$b])if($p[$k]<$a||$p[$k]>$b)throw new InvalidArgumentException('Out of range: '.$k);
        if($p['CycleSeconds']>$p['MaxCycleSeconds'])throw new InvalidArgumentException('Cycle exceeds maximum runtime.');
        foreach(self::FLOATS as $k=>$_)if(!is_finite($p[$k]))throw new InvalidArgumentException('Invalid number: '.$k);
        if($p['StopHumidity']<20||$p['StartHumidity']>95||$p['StopHumidity']>=$p['StartHumidity']||$p['StartMoistureExcess']<0.5||$p['StartMoistureExcess']>10||$p['StopDryingPotential']<0.1||$p['StopDryingPotential']>=$p['StartMoistureExcess']||$p['MaximumColdHumidity']<50||$p['MaximumColdHumidity']>80||$p['MaximumTemperatureDrop']<0.5||$p['MaximumTemperatureDrop']>5||$p['MinimumOutsideTemperature']< -30||$p['MinimumOutsideTemperature']>30||$p['RecirculationSpread']<1||$p['RecirculationSpread']>10)throw new InvalidArgumentException('Invalid humidity or thermal thresholds.');
        if(strlen($p['Rooms'])>262144)throw new InvalidArgumentException('Room configuration too large.');
        $rooms=json_decode($p['Rooms'],true,32,JSON_THROW_ON_ERROR);if(!is_array($rooms)||!array_is_list($rooms)||!$rooms||count($rooms)>100)throw new InvalidArgumentException('Configure 1 to 100 rooms.');
        $seen=[];
        foreach($rooms as $r){
            $keys=['key','name','floor','sources','temperatureID','humidityID','coldTemperatureID','surfaceTemperatureID','minimumTemperature','flapID','enabled'];
            if(!is_array($r)||array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r))||!is_string($r['key'])||!preg_match('/^[a-z0-9-]{1,64}$/D',$r['key'])||isset($seen[$r['key']]))throw new InvalidArgumentException('Invalid room key or fields.');$seen[$r['key']]=true;
            foreach(['name','floor'] as $k)if(!is_string($r[$k])||trim($r[$k])===''||strlen($r[$k])>120)throw new InvalidArgumentException('Invalid room label.');
            foreach(['temperatureID','humidityID','coldTemperatureID','surfaceTemperatureID','flapID'] as $k)if(!is_int($r[$k])||$r[$k]<0)throw new InvalidArgumentException('Invalid room variable ID.');
            if(!is_bool($r['enabled'])||!is_float($r['minimumTemperature'])||!is_finite($r['minimumTemperature'])||$r['minimumTemperature']<5||$r['minimumTemperature']>25)throw new InvalidArgumentException('Invalid room protection minimum.');
            if(!is_array($r['sources'])||!array_is_list($r['sources'])||count($r['sources'])>32)throw new InvalidArgumentException('Invalid room sources.');
            $ids=[];foreach($r['sources'] as $s){if(!is_array($s)||array_diff(array_keys($s),['id','kind','label'])||!is_int($s['id']??null)||$s['id']<2||!in_array($s['kind']??'', ['temperature','humidity'],true)||!is_string($s['label']??null)||strlen($s['label'])>160||isset($ids[$s['id']]))throw new InvalidArgumentException('Invalid sensor candidate.');$ids[$s['id']]=$s['kind'];}
            foreach(['temperatureID'=>'temperature','humidityID'=>'humidity','coldTemperatureID'=>'temperature'] as $k=>$kind)if($r[$k]&&($ids[$r[$k]]??'')!==$kind)throw new InvalidArgumentException('Selected sensor must belong to its room and type.');
        }return $rooms;
    }
    private function rooms(): array {return self::validate($this->properties());}
    private function revision(): string {return hash('sha256',self::canonical($this->properties()));}
    private function save(array $p): void
    {
        self::validate($p);if(IPS_HasChanges($this->InstanceID))throw new InvalidArgumentException('Apply or discard pending console edits first.');
        if($this->ReadAttributeString('Lease')!=='')throw new InvalidArgumentException('Stop ventilation and finish the heating handover before editing configuration.');
        IPS_SetConfiguration($this->InstanceID,self::json($p));IPS_ApplyChanges($this->InstanceID);
    }
    public function ExportConfig(): string {self::validate($this->properties());return self::json(['schema'=>'VentilationControl.v1','properties'=>$this->properties()]);}
    public function ImportConfig(string $JSON): string
    {
        return $this->controlChange(function()use($JSON){
        if(strlen($JSON)>524288)throw new InvalidArgumentException('Import too large.');$v=json_decode($JSON,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($v)||array_diff(array_keys($v),['schema','properties'])||($v['schema']??'')!=='VentilationControl.v1'||!is_array($v['properties']??null))throw new InvalidArgumentException('Unsupported configuration.');
        self::validate($v['properties']);$p=$v['properties'];foreach(['Enabled','LiveOutputs','AutomaticEnabled','WebEnabled','AirPathVerified'] as $k)$p[$k]=false;$this->save($p);return 'Imported with control, automatic mode and web access disabled. Review IDs and air paths before enabling.';
        });
    }
    public function ExportConfigurationBackup(): string
    {
        $p=$this->properties();self::validate($p);
        return self::json(['schema'=>'Symcon.ConfigurationBackup.v1','moduleID'=>self::MODULE,'format'=>'VentilationControl.v1','payload'=>['schema'=>'VentilationControl.v1','properties'=>$p],'sourceHash'=>hash('sha256',self::canonical(['properties'=>$p,'attributes'=>[]])),'attributeNames'=>[],'restoreMode'=>'manual-native-import','exclusions'=>['Passkey sessions','CSRF key','Actuator ownership journal','Manual requests','Qualification timers','Debug log'],'notes'=>['Native import forces all control and web access off.','Export and validation never operate devices.','A backup does not resume an interrupted ventilation cycle.']]);
    }
    public function ValidateConfigurationBackup(string $JSON): string
    {
        if(strlen($JSON)>524288)throw new InvalidArgumentException('Validation input too large.');$v=json_decode($JSON,true,64,JSON_THROW_ON_ERROR);$issues=[];
        try{if(!is_array($v)||array_diff(array_keys($v),['schema','properties'])||($v['schema']??'')!=='VentilationControl.v1'||!is_array($v['properties']??null))throw new InvalidArgumentException('Unsupported configuration.');self::validate($v['properties']);}catch(Throwable $e){$issues[]=$e->getMessage();}
        return self::json(['schema'=>'Symcon.ConfigurationBackupValidation.v1','valid'=>!$issues,'scope'=>'format','payloadHash'=>hash('sha256',self::canonical($v)),'issues'=>$issues,'checks'=>['Native import schema and strict property types','Room sensor mappings and runtime bounds'],'notes'=>['No restore or device operation was executed.']]);
    }
    public function ExportToForm(): void {$this->UpdateFormField('BackupJSON','value',$this->ExportConfig());}
    public function SelectLowestSensors(): string
    {
        return $this->controlChange(function(){
        $p=$this->properties();$rooms=self::validate($p);$selected=0;
        foreach($rooms as &$room){$lowest=null;foreach($room['sources'] as $s){if($s['kind']!=='temperature'||str_starts_with($s['label'],'Humidity reference:')||stripos($s['label'],'faulty')!==false)continue;$r=$this->reading($s['id'],'temperature');if($r['status']==='ok'&&($lowest===null||$r['value']<$lowest['value']||($r['value']===$lowest['value']&&$s['id']<$lowest['id'])))$lowest=['id'=>$s['id'],'value'=>$r['value']];}if($lowest!==null){$room['coldTemperatureID']=$lowest['id'];++$selected;}}unset($room);
        $p['Rooms']=self::json($rooms);$this->save($p);return 'Selected and saved the lowest currently valid sensor in '.$selected.' rooms. These selections remain fixed until edited or explicitly repeated.';
        });
    }
}
