<?php
declare(strict_types=1);
trait VentilationSensors
{
    private function reading(int $id,string $kind): array
    {
        try{
            if($id<2||!IPS_VariableExists($id))return ['value'=>null,'updated'=>0,'status'=>'missing'];$v=IPS_GetVariable($id);$n=GetValue($id);$u=(int)($v['VariableUpdated']??0);
            if(!in_array($v['VariableType'],[1,2],true)||!is_numeric($n)||!is_finite((float)$n)||($kind==='humidity'?($n<=0||$n>100):($n< -50||$n>80)))return ['value'=>null,'updated'=>$u,'status'=>'invalid'];
            return ['value'=>(float)$n,'updated'=>$u,'status'=>($u<=0||$u>time()+60||time()-$u>$this->ReadPropertyInteger('MaxSensorAgeSeconds'))?'stale':'ok'];
        }catch(Throwable $e){return ['value'=>null,'updated'=>0,'status'=>'unavailable'];}
    }
    private function measured(int $id,string $kind): float
    {
        $r=$this->reading($id,$kind);if($r['status']!=='ok')throw new RuntimeException('Sensor '.$id.' is '.$r['status'].'.');return $r['value'];
    }
    private function value(int $id,int $type)
    {
        if($id<2||!IPS_VariableExists($id)||IPS_GetVariable($id)['VariableType']!==$type)throw new RuntimeException('Missing or wrong-type control variable '.$id.'.');
        return GetValue($id);
    }
    private function snapshot(): array
    {
        $p=$this->properties();$rooms=self::validate($p);$result=[];$outside=null;$outsideError='';
        try{$outside=VentilationPsychrometrics::air($this->measured($p['OutsideTemperatureID'],'temperature'),$this->measured($p['OutsideHumidityID'],'humidity'));}catch(Throwable $e){$outsideError=$e->getMessage();}
        foreach($rooms as $room){
            $r=['key'=>$room['key'],'name'=>$room['name'],'floor'=>$room['floor'],'enabled'=>$room['enabled'],'flapID'=>$room['flapID'],'minimumTemperature'=>$room['minimumTemperature'],'coldTemperatureID'=>$room['coldTemperatureID'],'surfaceMeasured'=>$room['surfaceTemperatureID']>1,'valid'=>false,'error'=>'','air'=>null,'coldTemperature'=>null,'coldHumidity'=>null,'exchange'=>null,'protectionTemperature'=>null];
            try{
                $air=VentilationPsychrometrics::air($this->measured($room['temperatureID'],'temperature'),$this->measured($room['humidityID'],'humidity'));
                $cold=$this->measured($room['coldTemperatureID'],'temperature');
                $surface=$room['surfaceTemperatureID']>1?$this->measured($room['surfaceTemperatureID'],'temperature'):$cold;
                $r+=[];$r['air']=$air;$r['coldTemperature']=$cold;$r['coldHumidity']=VentilationPsychrometrics::relativeAt($air['vapourPressure'],$surface);$r['surfaceTemperature']=$surface;
                $r['protectionTemperature']=max($room['minimumTemperature'],VentilationPsychrometrics::temperatureAt($air['vapourPressure'],$p['MaximumColdHumidity']));
                $r['exchange']=$outside===null?null:VentilationPsychrometrics::exchange($air,$outside,$surface);$r['valid']=true;
            }catch(Throwable $e){$r['error']=$e->getMessage();}
            $result[]=$r;
        }
        $heating=null;$heatingError='';try{$heating=$this->heating();}catch(Throwable $e){$heatingError=$e->getMessage();}
        return ['now'=>time(),'rooms'=>$result,'outside'=>$outside,'outsideError'=>$outsideError,'heating'=>$heating,'heatingError'=>$heatingError];
    }
    /** Accept the same sensor rows and typed flap commands as HeatingControl. */
    private static function heatingRooms(string $json): array
    {
        $rows=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>100)throw new RuntimeException('Heating rooms must be a non-empty list.');
        $rooms=[];
        foreach($rows as $row){
            if(!is_array($row)||!is_string($row['name']??null)||!is_array($row['sensors']??null)||!array_is_list($row['sensors'])||!$row['sensors']||count($row['sensors'])>32)throw new RuntimeException('Invalid heating room or temperature-sensor list.');
            $sensors=[];
            foreach($row['sensors'] as $sensor){
                $id=is_array($sensor)?($sensor['id']??null):$sensor;
                if(!is_int($id)||$id<2)throw new RuntimeException('Heating room '.$row['name'].' has an invalid temperature sensor ID.');
                $sensors[]=$id;
            }
            foreach(['targetID','flapID'] as $key)if(!is_int($row[$key]??null)||$row[$key]<2)throw new RuntimeException('Heating room '.$row['name'].' has an invalid '.$key.'.');
            $open=$row['open']??null;$closed=$row['closed']??null;
            if(array_key_exists('flapType',$row)){
                if(!in_array($row['flapType'],[0,1],true)||!is_int($open)||!is_int($closed))throw new RuntimeException('Heating room '.$row['name'].' has invalid typed flap commands.');
                $max=$row['flapType']===0?1:100;
                if($open<0||$open>$max||$closed<0||$closed>$max)throw new RuntimeException('Heating flap commands are outside the allowed range.');
                if($row['flapType']===0){$open=(bool)$open;$closed=(bool)$closed;}
            }
            if(!((is_bool($open)&&is_bool($closed))||(is_int($open)&&is_int($closed)&&$open>=0&&$open<=100&&$closed>=0&&$closed<=100))||$open===$closed)throw new RuntimeException('Heating flap open/closed commands must have matching types and different values.');
            $rooms[]=['name'=>$row['name'],'sensors'=>$sensors,'targetID'=>$row['targetID'],'flapID'=>$row['flapID'],'open'=>$open,'closed'=>$closed];
        }
        return $rooms;
    }
    private function heating(): array
    {
        $id=$this->ReadPropertyInteger('HeatingInstanceID');
        if(!IPS_InstanceExists($id)||strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')!==self::HEATING)throw new RuntimeException('Select the HeatingControl instance.');
        if(IPS_HasChanges($id))throw new RuntimeException('Heating Control has unapplied configuration edits.');
        $c=json_decode(IPS_GetConfiguration($id),true,64,JSON_THROW_ON_ERROR);
        if(!is_string($c['Rooms']??null))throw new RuntimeException('Heating room configuration is unavailable.');
        $rooms=self::heatingRooms($c['Rooms']);
        $demand=[];$h=GetValue((int)$c['HysteresisID']);if(!is_numeric($h)||!is_finite((float)$h)||$h<0||$h>5)throw new RuntimeException('Invalid heating hysteresis.');
        foreach($rooms as $r){
            $temps=[];foreach($r['sensors'] as $sensor)$temps[]=$this->measured((int)$sensor,'temperature');
            if(!$temps)throw new RuntimeException('Heating room has no sensors.');
            $actual=array_sum($temps)/count($temps);$target=$this->value((int)$r['targetID'],2);
            if(!is_finite($target)||$target<5||$target>35)throw new RuntimeException('Invalid heating target.');
            // Return equipment before a room crosses the normal heating-on threshold.
            if($actual<=$target+(float)$h)$demand[]=$r['name'];
        }
        $child=function(string $ident)use($id){$v=IPS_GetObjectIDByIdent($ident,$id);if(!$v||!IPS_VariableExists($v))throw new RuntimeException('Heating Control lacks '.$ident.'.');return GetValue($v);};
        $fingerprint=$c;unset($fingerprint['Enabled']);
        return ['id'=>$id,'enabled'=>$c['Enabled']===true,'dryRun'=>$c['DryRun']===true,'config'=>$c,'configHash'=>hash('sha256',self::canonical($fingerprint)),
            'rooms'=>$rooms,'demand'=>$demand,'winter'=>$this->value((int)$c['WinterID'],0),'masterDisabled'=>$this->value((int)$c['MasterDisableID'],0),
            'mode'=>$this->value((int)$c['OperatingModeID'],1),'holiday'=>$this->value((int)$c['HolidayID'],0),'nightDisabled'=>$this->value((int)$c['NightDisableID'],0),
            'heatPumpOn'=>$this->value((int)$c['HeatPumpOnID'],0),'gasPumpOn'=>$this->value((int)$c['GasPumpID'],0),'fanOn'=>$this->value((int)$c['FanOnID'],0),
            'residual'=>$child('ResidualHeating')===true,'test'=>$child('HeatPumpTestActive')===true,'calibration'=>$child('FlapCalibrationActive')===true];
    }
    private function eligibility(array $s,bool $owning=false): string
    {
        $h=$s['heating'];if(!$h)return $s['heatingError'];
        if($h['dryRun'])return 'Heating Control is in dry run.';
        if(!$owning&&!$h['enabled'])return 'Heating Control is disabled; no automatic takeover.';
        if($owning&&$h['enabled'])return 'Heating Control was re-enabled externally.';
        if(!$h['winter']||$h['masterDisabled'])return 'Heating is in summer or master bypass.';
        if($h['mode']===$h['config']['CoolingModeValue'])return 'Cooling has priority over ventilation.';
        if($h['nightDisabled']&&!$h['holiday'])return 'Night shutdown is active.';
        if($h['test']||$h['calibration'])return 'A heating test or flap calibration owns the equipment.';
        if($h['heatPumpOn']||$h['gasPumpOn']||$h['residual'])return 'Heating or residual heat recovery has priority.';
        if($h['demand'])return 'Heating priority: '.implode(', ',$h['demand']).'.';
        if(!$owning&&$h['fanOn'])return 'The shared fan is already running.';
        return '';
    }
    private function connected(array $s): array
    {
        if(!$s['heating'])return [];$flaps=array_column($s['heating']['rooms'],'flapID');
        // Every configured room supplied by an opened air branch is checked, even
        // when its own automatic-demand checkbox is off.
        return array_values(array_filter($s['rooms'],static fn($r)=>in_array($r['flapID'],$flaps,true)));
    }
    private function recommendation(array $s): array
    {
        $p=$this->properties();$connected=$this->connected($s);$reason=$this->eligibility($s,$this->ReadAttributeString('Lease')!=='');
        if($reason!=='')return ['mode'=>'off','reason'=>$reason];
        if(!$connected)return ['mode'=>'off','reason'=>'No supplied rooms are mapped.'];
        foreach($connected as $r)if(!$r['valid']||$r['coldTemperature']<=$r['minimumTemperature'])return ['mode'=>'off','reason'=>'Missing climate data or minimum-temperature limit in '.$r['name'].'.'];
        $needs=array_filter($connected,fn($r)=>$r['enabled']&&($r['air']['humidity']>=$p['StartHumidity']||$r['coldHumidity']>=$p['MaximumColdHumidity']));
        if(!$needs)return ['mode'=>'off','reason'=>'No room currently needs moisture ventilation.'];
        if($s['outside']!==null&&$s['outside']['temperature']>=$p['MinimumOutsideTemperature']){
            $safe=true;foreach($connected as $r)if($r['exchange']['dryingPotential']<0||$r['exchange']['ventilatedColdHumidity']>=$p['MaximumColdHumidity'])$safe=false;
            if($safe&&array_filter($needs,fn($r)=>$r['exchange']['moistureExcess']>=$p['StartMoistureExcess']&&$r['exchange']['dryingPotential']>=$p['StartMoistureExcess']))return ['mode'=>'outside','reason'=>'Outside air dries the demanding rooms and stays below the configured cold-point humidity limit.'];
        }
        if($p['AutomaticRecirculation']&&$this->recirculationUseful($connected,$p['RecirculationSpread']))return ['mode'=>'inside','reason'=>'Recirculation can even out temperatures; it does not remove moisture.'];
        return ['mode'=>'off','reason'=>'Outside air is unsuitable or unavailable; no useful recirculation cycle.'];
    }
    private function recirculationUseful(array $rooms,float $spread): bool
    {
        if(count($rooms)<2)return false;$temps=array_column($rooms,'coldTemperature');if(max($temps)-min($temps)<$spread)return false;
        // Without commissioned flow weights, use a conservative upper bound on
        // return-air vapour pressure instead of inventing a mixing ratio.
        $vapour=max(array_map(fn($r)=>$r['air']['vapourPressure'],$rooms));
        foreach($rooms as $r)if(VentilationPsychrometrics::relativeAt($vapour,$r['surfaceTemperature'])>=$this->ReadPropertyFloat('MaximumColdHumidity'))return false;return true;
    }
}
