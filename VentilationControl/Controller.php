<?php
declare(strict_types=1);
trait VentilationController
{
    private function controlChange(callable $callback)
    {
        if(!IPS_SemaphoreEnter('VMC.Control.'.$this->InstanceID,1000))throw new RuntimeException('Control is busy; retry.');
        try{return $callback();}finally{IPS_SemaphoreLeave('VMC.Control.'.$this->InstanceID);}
    }
    private function lease(): array {return json_decode($this->ReadAttributeString('Lease'),true,64,JSON_THROW_ON_ERROR)??[];}
    private function storeLease(array $lease): void {$this->WriteAttributeString('Lease',self::json($lease));}
    public function SetAutomatic(bool $enabled): void
    {
        $this->controlChange(function()use($enabled){
        if($enabled&&!$this->ReadPropertyBoolean('Enabled'))throw new InvalidArgumentException('Enable this module first.');
        if(IPS_HasChanges($this->InstanceID))throw new InvalidArgumentException('Apply console edits first.');
        IPS_SetProperty($this->InstanceID,'AutomaticEnabled',$enabled);IPS_ApplyChanges($this->InstanceID);
        $this->WriteAttributeString('Qualification','');if(!$enabled&&$this->ReadAttributeString('Lease')!=='')$this->markStop('Automatic control disabled.');
        });
    }
    public function SetModuleEnabled(bool $enabled): void
    {
        $this->controlChange(function()use($enabled){
        if(IPS_HasChanges($this->InstanceID))throw new InvalidArgumentException('Apply console edits first.');
        if(!$enabled){$this->WriteAttributeString('ManualRequest','');$this->markStop('Module disabled; returning equipment to heating.');}
        IPS_SetProperty($this->InstanceID,'Enabled',$enabled);IPS_ApplyChanges($this->InstanceID);
        });
    }
    public function ManualStart(string $Mode,int $Seconds): string
    {
        $this->controlChange(function()use($Mode,$Seconds){
        if(!in_array($Mode,['auto','inside','outside'],true)||$Seconds<30||$Seconds>$this->ReadPropertyInteger('MaxCycleSeconds'))throw new InvalidArgumentException('Choose auto/inside/outside and a duration within the configured maximum.');
        if(!$this->ReadPropertyBoolean('Enabled'))throw new InvalidArgumentException('Enable this module first.');
        if($this->ReadAttributeString('Lease')!=='')throw new InvalidArgumentException('A cycle or heating handover is already active.');
        $this->WriteAttributeString('ManualRequest',self::json(['mode'=>$Mode,'seconds'=>$Seconds,'expires'=>time()+60]));
        });$this->Tick();return $this->GetValue('Status');
    }
    public function Stop(): void
    {
        $this->controlChange(function(){
        $this->WriteAttributeString('ManualRequest','');$this->WriteAttributeString('Qualification','');
        // A deliberate stop remains stopped; automatic mode must be re-enabled.
        IPS_SetProperty($this->InstanceID,'AutomaticEnabled',false);$this->markStop('Stopped manually; automatic mode disabled.');IPS_ApplyChanges($this->InstanceID);
        });$this->Tick();
    }
    private function markStop(string $reason): void
    {
        if($this->ReadAttributeString('Lease')==='')return;$s=$this->lease();$s['phase']='stop';$s['reason']=$reason;$s['phaseSince']=time();$this->storeLease($s);
    }
    public function Tick(): void
    {
        if(IPS_GetKernelRunlevel()!==KR_READY)return;
        if(!IPS_SemaphoreEnter('VMC.Control.'.$this->InstanceID,1000))return;
        try{
            if($this->ReadAttributeString('Lease')!==''){$this->advance();return;}
            self::validate($this->properties());
            $this->SetValue('Running',false);$this->SetValue('AirMode','off');
            if(!$this->ReadPropertyBoolean('Enabled')){$this->SetValue('Status','Disabled. Monitoring remains available.');return;}
            $s=$this->snapshot();$r=$this->recommendation($s);$this->SetValue('Recommendation',$r['reason']);
            $manual=json_decode($this->ReadAttributeString('ManualRequest'),true)??[];$this->WriteAttributeString('ManualRequest','');
            if($manual&&($manual['expires']??0)<time())$manual=[];
            $mode='off';$seconds=$this->ReadPropertyInteger('CycleSeconds');$source='automatic';
            if($manual){$mode=$manual['mode']==='auto'?$r['mode']:$manual['mode'];$seconds=$manual['seconds'];$source='manual';}
            elseif($this->ReadPropertyBoolean('AutomaticEnabled')){
                if(!$this->withinHours($s)){$this->WriteAttributeString('Qualification','');$this->SetValue('Status','Outside the configured automatic operating hours.');return;}
                $q=json_decode($this->ReadAttributeString('Qualification'),true)??[];
                if($r['mode']==='off'){$this->WriteAttributeString('Qualification','');$this->SetValue('Status',$r['reason']);return;}
                if(($q['mode']??'')!==$r['mode']||($q['seen']??0)<time()-2*$this->ReadPropertyInteger('EvaluationSeconds'))$q=['mode'=>$r['mode'],'since'=>time()];$q['seen']=time();$this->WriteAttributeString('Qualification',self::json($q));
                if(time()-$q['since']<$this->ReadPropertyInteger('QualificationSeconds')){$this->SetValue('Status','Qualifying '.$r['mode'].'-air conditions.');return;}$mode=$r['mode'];
            }
            if($mode==='off'){$this->SetValue('Status',$manual?$r['reason']:'Monitoring; automatic mode is disabled.');return;}
            $last=(int)$this->ReadAttributeString('LastStopped');if(time()-$last<$this->ReadPropertyInteger('CooldownSeconds')){$this->SetValue('Status','Cooling down between ventilation cycles.');return;}
            if(!$this->ReadPropertyBoolean('LiveOutputs')){$this->SetValue('Status','Preview: would run '.$mode.' air for '.$seconds.' seconds. Live outputs are disabled.');return;}
            $this->begin($s,$mode,$seconds,$source);
        }catch(Throwable $e){
            $this->debug('Control error: '.$e->getMessage());$this->SetValue('Status','Blocked: '.$e->getMessage());
            if($this->ReadAttributeString('Lease')!=='')$this->markStop('Interrupted: '.$e->getMessage());
        }finally{$this->SetTimerInterval('Evaluate',$this->ReadAttributeString('Lease')!==''?5000:max(5,$this->ReadPropertyInteger('EvaluationSeconds'))*1000);IPS_SemaphoreLeave('VMC.Control.'.$this->InstanceID);}
    }
    private function withinHours(array $s): bool
    {
        if($this->ReadPropertyBoolean('AllowHoliday24h')&&($s['heating']['holiday']??false))return true;
        // PHP follows the Symcon installation timezone, not the browser timezone.
        $m=(int)date('G')*60+(int)date('i');$a=$this->ReadPropertyInteger('StartMinute');$b=$this->ReadPropertyInteger('EndMinute');return $a===$b||($a<$b?($m>=$a&&$m<$b):($m>=$a||$m<$b));
    }
    private function climateSafety(array $snapshot,string $mode,array $baseline=[]): string
    {
        $connected=$this->connected($snapshot);if(!$connected)return 'No supplied rooms are mapped.';
        foreach($connected as $r){
            if(!$r['valid'])return 'Incomplete or stale climate data in '.$r['name'].'.';
            if($r['coldTemperature']<=$r['minimumTemperature'])return 'Minimum temperature reached in '.$r['name'].'.';
            if(isset($baseline[$r['key']])&&$baseline[$r['key']]-$r['coldTemperature']>=$this->ReadPropertyFloat('MaximumTemperatureDrop'))return 'Maximum temperature drop reached in '.$r['name'].'.';
        }
        if($mode==='outside'){
            if(!$snapshot['outside'])return 'Outside sensor unavailable or stale.';
            if($snapshot['outside']['temperature']<$this->ReadPropertyFloat('MinimumOutsideTemperature'))return 'Outside temperature below the configured limit.';
            foreach($connected as $r)if($r['exchange']['dryingPotential']<$this->ReadPropertyFloat('StopDryingPotential')||$r['exchange']['ventilatedColdHumidity']>=$this->ReadPropertyFloat('MaximumColdHumidity'))return 'Outside air no longer safely dries '.$r['name'].'.';
        }elseif(!$this->recirculationUseful($connected,0.0))return 'Return air could worsen humidity at a cold point.';
        return '';
    }
    private function validateHardware(array $h): void
    {
        if(!$this->ReadPropertyBoolean('AirSelectorVerified')||!$this->ReadPropertyBoolean('AirPathVerified'))throw new RuntimeException('Verify the air selector, exhaust/return paths, fan and cooling interlock on the Admin tab.');
        if(!function_exists('HC_SetModuleEnabled')||!function_exists('HC_ProcessHeating'))throw new RuntimeException('HeatingControl enable/disable API is unavailable.');
        $p=$this->properties();$c=$h['config'];$cool=$p['CoolingInstanceID'];
        if(!IPS_InstanceExists($cool)||IPS_HasChanges($cool))throw new RuntimeException('Select the cooling zoning controller without pending edits.');
        $cc=json_decode(IPS_GetConfiguration($cool),true,64,JSON_THROW_ON_ERROR);
        foreach(['VentilationActiveLink'=>$p['VentilationActiveID'],'MainFanControlLink'=>$c['FanOnID'],'MainFanSpeedLink'=>$c['FanSpeedID'],'MainACOnOffLink'=>$c['HeatPumpOnID'],'WinterModeID'=>$c['WinterID']] as $k=>$v)if(($cc[$k]??null)!==$v)throw new RuntimeException('Cooling interlock wiring does not match '.$k.'.');
        if($this->value($p['VentilationActiveID'],0))throw new RuntimeException('Another ventilation program is active.');
        if($p['AirSelectorLockID']>1&&$this->value($p['AirSelectorLockID'],0))throw new RuntimeException('Air-intake selector is locked. The module will not unlock it.');
        $this->value($p['AirSelectorID'],0);$this->value($p['AirSelectorFeedbackID'],0);
        $outputs=[$p['AirSelectorID'],$p['VentilationActiveID'],$c['FanOnID'],$c['FanSpeedID']];foreach($h['rooms'] as $room)$outputs[]=$room['flapID'];
        if(count(array_unique($outputs))!==count($outputs))throw new RuntimeException('Control destinations overlap.');
        $protected=[$p['OutsideTemperatureID'],$p['OutsideHumidityID'],$p['AirSelectorFeedbackID'],$p['AirSelectorLockID']];
        foreach($this->rooms() as $room)foreach($room['sources'] as $sensor)$protected[]=$sensor['id'];
        foreach($c as $key=>$id)if(str_ends_with($key,'ID')&&is_int($id)&&!in_array($key,['FanOnID','FanSpeedID'],true))$protected[]=$id;
        foreach($outputs as $id){if(in_array($id,$protected,true))throw new RuntimeException('Control destination aliases a protected input.');$v=IPS_GetVariable($id);if(($v['VariableCustomAction']??0)<2&&($v['VariableAction']??0)<2)throw new RuntimeException('Output '.$id.' has no device action.');}
        foreach($h['rooms'] as $room)if(!in_array($room['flapID'],array_column($this->rooms(),'flapID'),true))throw new RuntimeException('Map every supplied heating branch before ventilation.');
    }
    private function begin(array $snapshot,string $mode,int $seconds,string $source): void
    {
        $reason=$this->eligibility($snapshot);if($reason!=='')throw new RuntimeException($reason);
        $reason=$this->climateSafety($snapshot,$mode);if($reason!=='')throw new RuntimeException($reason);
        $h=$snapshot['heating'];$this->validateHardware($h);$c=$h['config'];$p=$this->properties();
        $restore=[];$desired=[];$fan=(int)$c['FanOnID'];$speed=(int)$c['FanSpeedID'];
        $desired[$p['AirSelectorID']]=$mode==='inside';$desired[$p['VentilationActiveID']]=true;$desired[$speed]=$p['FanPercent'];
        foreach($h['rooms'] as $room)$desired[$room['flapID']]=$room['open'];
        foreach($desired as $id=>$value)$restore[$id]=GetValue($id);
        $restore[$fan]=false;
        $baseline=[];foreach($this->connected($snapshot) as $room)$baseline[$room['key']]=$room['coldTemperature'];
        $lease=['phase'=>'pause','touched'=>false,'phaseSince'=>time(),'started'=>time(),'kernel'=>IPS_GetKernelStartTime(),'controller'=>$h['id'],'controllerHash'=>$h['configHash'],'seconds'=>$seconds,'mode'=>$mode,'source'=>$source,'fan'=>$fan,'speed'=>$speed,'selector'=>$p['AirSelectorID'],'feedback'=>$p['AirSelectorFeedbackID'],'selectorLock'=>$p['AirSelectorLockID'],'ventilationFlag'=>$p['VentilationActiveID'],'desired'=>$desired,'restore'=>$restore,'baseline'=>$baseline,'reason'=>'','resume'=>true];
        // Journal precedes the external pause, so a restart can recover even if
        // it occurs immediately after HeatingControl accepts the API call.
        $this->storeLease($lease);HC_SetModuleEnabled($h['id'],false);
        $lease['phase']='prepare';$lease['phaseSince']=time();$this->storeLease($lease);$this->SetValue('Status','Heating paused; preparing '.$mode.' air.');$this->debug('Acquired heating equipment for '.$source.' '.$mode.' cycle.');
    }
    private function command(int $id,$value): void
    {
        if(!IPS_VariableExists($id))throw new RuntimeException('Output missing: '.$id);$v=IPS_GetVariable($id);$type=$v['VariableType'];
        if($type===0&&!is_bool($value)||$type===1&&!is_int($value)||$type===2&&!is_numeric($value)||$type>2)throw new RuntimeException('Wrong command type: '.$id);
        if($type===2)$value=(float)$value;
        if(GetValue($id)===$value)return;
        if(($v['VariableCustomAction']??0)<2&&($v['VariableAction']??0)<2)throw new RuntimeException('No device action for '.$id);
        if(RequestAction($id,$value)===false)throw new RuntimeException('Device action failed for '.$id);
    }
    private function advance(): void
    {
        $s=$this->lease();$id=$s['controller'];$lock='HeatingControl.'.$id;
        if(!IPS_SemaphoreEnter($lock,1000))return;
        $resume=false;
        try{
            if(!IPS_InstanceExists($id))throw new RuntimeException('Paused heating controller is missing.');
            // When another operator has resumed heating, never send fan/flap
            // commands which could interrupt it. Keep the journal for review.
            if(IPS_GetProperty($id,'Enabled')===true){
                if($s['phase']==='release'){$this->WriteAttributeString('Lease','');$this->WriteAttributeString('LastStopped',(string)time());$this->SetValue('Running',false);$this->SetValue('AirMode','off');$this->SetValue('Status','Heating resumption acknowledged after interrupted handover.');return;}
                if(!$s['touched']){ $this->WriteAttributeString('Lease','');$this->SetValue('Status','Handover did not pause heating; no outputs changed.');return; }
                $this->SetValue('Status','Ownership changed: heating resumed externally. Stop/recovery requires review.');$this->SetValue('Running',false);return;
            }
            $c=json_decode(IPS_GetConfiguration($id),true,64,JSON_THROW_ON_ERROR);unset($c['Enabled']);
            if($s['resume']&&!hash_equals($s['controllerHash'],hash('sha256',self::canonical($c)))){$s['resume']=false;$s['phase']='stop';$s['phaseSince']=time();$s['reason']='Heating configuration changed while paused; resume heating manually after recovery.';}
            if($s['kernel']!==IPS_GetKernelStartTime()||!$this->ReadPropertyBoolean('Enabled')||!$this->ReadPropertyBoolean('LiveOutputs')){if(!in_array($s['phase'],['stop','restore','release'],true)){$s['phase']='stop';$s['phaseSince']=time();$s['reason']='Interrupted by restart or disabled controls.';}}
            if(!in_array($s['phase'],['stop','restore','release'],true)){
                $snapshot=$this->snapshot();$reason=$this->eligibility($snapshot,true);if($reason==='')$reason=$this->climateSafety($snapshot,$s['mode'],$s['baseline']);
                if(!$s['touched']&&($snapshot['heating']['fanOn']??false))$reason='Shared fan started before ventilation acquired the air path.';
                if($s['selectorLock']>1&&$this->value($s['selectorLock'],0))$reason='Air-intake selector was locked.';
                if($reason!==''||time()-$s['started']>$this->ReadPropertyInteger('MaxCycleSeconds')+2*$this->ReadPropertyInteger('FeedbackTimeoutSeconds')+$this->ReadPropertyInteger('DamperWaitSeconds')){$s['phase']='stop';$s['phaseSince']=time();$s['reason']=$reason?:'Maximum total cycle time reached.';}
            }
            if(!$s['touched']&&in_array($s['phase'],['pause','stop'],true)){$s['phase']='release';$resume=$s['resume'];}
            if($s['phase']==='prepare'){
                $s['touched']=true;$this->storeLease($s);
                $this->command($s['fan'],false);
                foreach($s['desired'] as $v=>$wanted)$this->command((int)$v,$wanted);
                $ready=$this->value($s['feedback'],0)===($s['mode']==='inside');
                foreach($s['desired'] as $v=>$wanted)if(GetValue((int)$v)!==$wanted)$ready=false;
                if(!$ready&&time()-$s['phaseSince']>$this->ReadPropertyInteger('FeedbackTimeoutSeconds'))throw new RuntimeException('Air-path acknowledgement timed out.');
                if($ready&&time()-$s['phaseSince']>=$this->ReadPropertyInteger('DamperWaitSeconds')){$s['phase']='run';$s['phaseSince']=time();$s['until']=time()+$s['seconds'];}
            }
            if($s['phase']==='run'){
                if($this->value($s['feedback'],0)!==($s['mode']==='inside'))throw new RuntimeException('Air-intake feedback changed during ventilation.');
                foreach($s['desired'] as $v=>$wanted)if(GetValue((int)$v)!==$wanted)throw new RuntimeException('A shared output changed externally: '.$v);
                $this->command($s['fan'],true);$fanAcknowledged=$this->value($s['fan'],0)===true;
                if(!$fanAcknowledged&&time()-$s['phaseSince']>$this->ReadPropertyInteger('FeedbackTimeoutSeconds'))throw new RuntimeException('Fan start was not acknowledged.');
                $this->SetValue('Running',$fanAcknowledged);$this->SetValue('AirMode',$s['mode']);
                $stop=time()>=$s['until'];
                if($s['source']==='automatic'){
                    $rooms=$this->connected($snapshot);$remaining=array_filter($rooms,fn($r)=>$r['enabled']&&($r['air']['humidity']>$this->ReadPropertyFloat('StopHumidity')||$r['coldHumidity']>$this->ReadPropertyFloat('MaximumColdHumidity')-5));
                    if(!$remaining)$stop=true;
                    if($s['mode']==='inside'&&!$this->recirculationUseful($rooms,1.0))$stop=true;
                }
                $this->SetValue('Status',$fanAcknowledged?ucfirst($s['mode']).' air: '.max(0,$s['until']-time()).' seconds remaining; heating demand is checked every five seconds.':'Waiting for fan-start acknowledgement.');
                if($stop){$s['phase']='stop';$s['phaseSince']=time();$s['reason']='Cycle finished or humidity target reached.';}
            }
            if($s['phase']==='stop'){
                $this->command($s['fan'],false);$this->SetValue('Running',false);$this->SetValue('Status','Stopping ventilation: '.$s['reason']);
                if($this->value($s['fan'],0)===false){$s['phase']='restore';$s['phaseSince']=time();}
                elseif(time()-$s['phaseSince']>$this->ReadPropertyInteger('FeedbackTimeoutSeconds'))throw new RuntimeException('Fan stop not acknowledged; heating remains paused pending recovery.');
            }
            if($s['phase']==='restore'){
                foreach($s['restore'] as $v=>$value)$this->command((int)$v,$value);
                $ready=$this->value($s['feedback'],0)===$s['restore'][$s['selector']];
                foreach($s['restore'] as $v=>$value)if(GetValue((int)$v)!==$value)$ready=false;
                if($ready&&time()-$s['phaseSince']>=$this->ReadPropertyInteger('DamperWaitSeconds')){$s['phase']='release';$resume=$s['resume'];}
                elseif(time()-$s['phaseSince']>$this->ReadPropertyInteger('FeedbackTimeoutSeconds')+$this->ReadPropertyInteger('DamperWaitSeconds'))throw new RuntimeException('Original air-path state not acknowledged; retry recovery.');
            }
            $this->storeLease($s);
        }finally{IPS_SemaphoreLeave($lock);}
        // Public HeatingControl methods acquire their own lock: never call them
        // while holding that lock, including its synchronous ApplyChanges path.
        if($s['phase']==='release'){
            if($resume){HC_SetModuleEnabled($id,true);if(IPS_GetProperty($id,'Enabled')!==true)throw new RuntimeException('Heating did not acknowledge resumption.');}
            $this->WriteAttributeString('Lease','');$this->WriteAttributeString('LastStopped',(string)time());$this->WriteAttributeString('Qualification','');$this->SetValue('AirMode','off');
            $this->SetValue('Status',$s['reason'].($resume?' Heating Control resumed.':' Resume Heating Control manually.'));$this->debug('Released equipment; resumed='.(int)$resume);
        }
    }
    public function AcceptExternalHandover(): string
    {
        if(!IPS_SemaphoreEnter('VMC.Control.'.$this->InstanceID,1000))throw new RuntimeException('Control is busy; retry.');
        try{
            if($this->ReadAttributeString('Lease')==='')return 'No recovery record is pending.';
            $s=$this->lease();if(!IPS_InstanceExists($s['controller'])||IPS_GetProperty($s['controller'],'Enabled')!==true)throw new InvalidArgumentException('Heating must already have been resumed externally. Otherwise use Stop to finish recovery.');
            $this->WriteAttributeString('Lease','');$this->WriteAttributeString('LastStopped',(string)time());$this->SetValue('Running',false);$this->SetValue('AirMode','off');
            return 'External handover accepted. No equipment command was sent. Review the air selector and ventilation-active flag before future ventilation.';
        }finally{IPS_SemaphoreLeave('VMC.Control.'.$this->InstanceID);}
    }
}
