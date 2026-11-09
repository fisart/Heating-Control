<?php
declare(strict_types=1);
require_once __DIR__.'/Configuration.php';
require_once __DIR__.'/Psychrometrics.php';
require_once __DIR__.'/Sensors.php';
require_once __DIR__.'/Controller.php';
require_once __DIR__.'/Security.php';
require_once __DIR__.'/Web.php';

class VentilationControl extends IPSModule
{
    use VentilationConfiguration,VentilationSensors,VentilationController,VentilationSecurity,VentilationWeb;
    public const MODULE='{E4197119-D932-4CF2-86BC-FA27E9FC76F1}';
    private const HEATING='{8B333AC9-EE67-4D24-8CE7-433BBB391E6C}';
    public function Create()
    {
        parent::Create();
        foreach(self::BOOLS as $k=>$v)$this->RegisterPropertyBoolean($k,$v);
        foreach(self::INTS as $k=>$v)$this->RegisterPropertyInteger($k,$v);
        foreach(self::FLOATS as $k=>$v)$this->RegisterPropertyFloat($k,$v);
        $this->RegisterPropertyString('Rooms',file_get_contents(__DIR__.'/rooms.json'));
        foreach(['Lease'=>'','ManualRequest'=>'','Qualification'=>'','LastStopped'=>'0','CSRFKey'=>bin2hex(random_bytes(32))] as $k=>$v)$this->RegisterAttributeString($k,$v);
        $this->RegisterVariableBoolean('Running','Ventilation running','~Switch',10);
        $this->RegisterVariableString('AirMode','Ventilation air source','',20);
        $this->RegisterVariableString('Status','Ventilation status','~TextBox',30);
        $this->RegisterVariableString('Recommendation','Ventilation recommendation','~TextBox',40);
        $this->RegisterVariableString('WebPath','Ventilation page','',50);
        $this->RegisterVariableString('IntegrationStatus','Portal and backup integration','~TextBox',60);
        $this->RegisterVariableString('Debug','Ventilation debug log','~TextBox',90);
        $this->RegisterTimer('Evaluate',0,'VMC_Tick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Setup',1000,'VMC_Setup($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0,IPS_KERNELMESSAGE);
    }
    public function ApplyChanges()
    {
        parent::ApplyChanges();$this->SetValue('WebPath',$this->path());$this->SetTimerInterval('Setup',1000);
        // Applying configuration or restarting never continues a former cycle.
        if($this->ReadAttributeString('Lease')!=='')$this->markStop('Interrupted by configuration apply or restart; recovering original equipment state.');
        $this->WriteAttributeString('Qualification','');
        try{
            $rooms=self::validate($this->properties());$configured=self::configuredRooms($rooms);
            if(count($configured)!==count($rooms)){
                // Persist this migration so Overview, Admin and native backups
                // all use the same saved list, including existing instances.
                IPS_SetProperty($this->InstanceID,'Rooms',self::json($configured));
                $this->debug('Removed '.(count($rooms)-count($configured)).' rooms with an unconfigured required sensor.');
                IPS_ApplyChanges($this->InstanceID);return;
            }
            $this->SetStatus(102);
        }
        catch(Throwable $e){$this->SetStatus(200);$this->SetValue('Status',$e->getMessage());}
        $this->SetTimerInterval('Evaluate',$this->ReadAttributeString('Lease')!==''?5000:max(5,$this->ReadPropertyInteger('EvaluationSeconds'))*1000);
    }
    public function MessageSink($TimeStamp,$SenderID,$Message,$Data)
    {
        if($SenderID===0&&$Message===IPS_KERNELMESSAGE&&(int)($Data[0]??0)===KR_READY){$this->markStop('Symcon restarted; recovering before new ventilation.');$this->SetTimerInterval('Setup',1000);$this->SetTimerInterval('Evaluate',5000);}
    }
    private function path(): string {return '/hook/ventilation_'.$this->InstanceID;}
    private function debug(string $s): void
    {
        if(!$this->ReadPropertyBoolean('DebugEnabled'))return;
        $this->SetValue('Debug',substr($this->GetValue('Debug').date('c').' '.$s."\n",-32000));
    }
    public function Setup(): void
    {
        if(IPS_GetKernelRunlevel()!==KR_READY)return;
        if(!IPS_SemaphoreEnter('ALPORT.WebhookControl',1000))return;
        try{
            $ids=IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');if(count($ids)!==1)throw new RuntimeException('Exactly one WebhookControl is required.');$id=(int)$ids[0];
            if(IPS_HasChanges($id))throw new RuntimeException('Apply pending WebhookControl changes first.');
            $hooks=json_decode(IPS_GetProperty($id,'Hooks'),true,32,JSON_THROW_ON_ERROR);if(!is_array($hooks)||!array_is_list($hooks))throw new RuntimeException('Invalid hook registry.');$next=[];$found=false;
            foreach($hooks as $hook){if(($hook['Hook']??'')===$this->path()){if(($hook['TargetID']??0)!==$this->InstanceID)throw new RuntimeException('Ventilation hook belongs to another object.');if(!$this->ReadPropertyBoolean('WebEnabled')||$found)continue;$found=true;}$next[]=$hook;}
            if(!$found&&$this->ReadPropertyBoolean('WebEnabled'))$next[]=['Hook'=>$this->path(),'TargetID'=>$this->InstanceID];
            if($next!==$hooks){IPS_SetProperty($id,'Hooks',self::json($next));IPS_ApplyChanges($id);}
            $this->SetTimerInterval('Setup',0);$this->SetValue('IntegrationStatus','Page registered. Use Register Portal link to add the card without changing Portal software. Configuration Backup discovers VMC exports automatically.');
        }catch(Throwable $e){$this->SetValue('IntegrationStatus',$e->getMessage());$this->debug($e->getMessage());}
        finally{IPS_SemaphoreLeave('ALPORT.WebhookControl');}
    }
    public function RegisterPortalPage(): string
    {
        $selected=$this->ReadPropertyInteger('PortalInstanceID');$ids=$selected>0?[$selected]:IPS_GetInstanceListByModuleID('{922E5A10-A048-463D-A973-97B4BBD9096B}');if(count($ids)!==1)throw new RuntimeException('Select exactly one Portal instance.');$id=(int)$ids[0];
        if(!IPS_InstanceExists($id)||strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')!=='{922E5A10-A048-463D-A973-97B4BBD9096B}')throw new RuntimeException('Selected object is not the Portal.');
        if(!IPS_SemaphoreEnter('VMC.Portal.'.$id,1000))throw new RuntimeException('Portal registration is busy.');
        try{
            if(IPS_HasChanges($id))throw new RuntimeException('Apply or discard pending Portal edits first.');
            $rows=json_decode(IPS_GetProperty($id,'AdditionalPages'),true,32,JSON_THROW_ON_ERROR);if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('Invalid Portal additional-page registry.');
            $next=[];foreach($rows as $row){if(($row['Hook']??'')===$this->path()){if(($row['TargetID']??0)!==$this->InstanceID)throw new RuntimeException('Portal entry belongs to another object.');continue;}$next[]=$row;}
            if(count($next)>=100)throw new RuntimeException('Portal additional-page limit reached.');
            $next[]=['Name'=>'Ventilation & moisture','Description'=>'Room moisture, air source, manual cycles and heating coordination.','Cluster'=>'smart','Hook'=>$this->path(),'TargetID'=>$this->InstanceID];
            if($next!==$rows){IPS_SetProperty($id,'AdditionalPages',self::json($next));IPS_ApplyChanges($id);}
            $message='Ventilation & moisture is registered in Portal → Smart Home.';$this->SetValue('IntegrationStatus',$message);return $message;
        }finally{IPS_SemaphoreLeave('VMC.Portal.'.$id);}
    }
    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__.'/form.json');
    }
}
