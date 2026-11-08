<?php
declare(strict_types=1);
require_once __DIR__.'/Security.php';
require_once __DIR__.'/History.php';
class RoomClimate extends IPSModule
{
    use RoomClimateSecurity, RoomClimateHistory;
    private const STALE_AFTER=86400;
    public const MODULE='{A1D8059C-4B7F-4A75-89D7-B8245397DC60}';
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('WebEnabled',true);
        $this->RegisterPropertyBoolean('DebugEnabled',false);
        foreach(['BackendInstanceID'=>0,'PortalInstanceID'=>0,'DocumentationInstanceID'=>0,'PollSeconds'=>2,'StaleSeconds'=>86400] as $k=>$v) $this->RegisterPropertyInteger($k,$v);
        $this->RegisterPropertyString('Rooms',file_get_contents(__DIR__.'/rooms.json'));
        $this->RegisterVariableString('WebPath','Room climate page','',10);
        $this->RegisterVariableString('Status','Room climate status','~TextBox',20);
        $this->RegisterVariableString('Debug','Debug log','~TextBox',90);
        $this->RegisterTimer('Setup',1000,'RCL_Setup($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0,IPS_KERNELMESSAGE);
    }
    public function ApplyChanges()
    {
        parent::ApplyChanges();$this->SetBuffer('Snapshot','');$this->SetBuffer('History','');
        $this->SetValue('WebPath',$this->path());$this->SetTimerInterval('Setup',1000);
        try {$this->configuration();$this->SetStatus($this->ReadPropertyBoolean('WebEnabled')?102:104);}
        catch(Throwable $e){$this->SetStatus(200);$this->SetValue('Status',$e->getMessage());}
    }
    public function MessageSink($TimeStamp,$SenderID,$Message,$Data)
    {
        if($SenderID===0&&$Message===IPS_KERNELMESSAGE&&(int)($Data[0]??0)===KR_READY)$this->SetTimerInterval('Setup',100);
    }
    private function path(): string {return '/hook/room_climate_'.$this->InstanceID;}
    private function debug(string $message): void
    {
        if(!$this->ReadPropertyBoolean('DebugEnabled'))return;
        $this->SetValue('Debug',substr($this->GetValue('Debug').date('c').' '.$message."\n",-32000));
    }
    public function Setup(): void
    {
        if(IPS_GetKernelRunlevel()!==KR_READY)return;
        if(!IPS_SemaphoreEnter('ALPORT.WebhookControl',1000))return;
        try {
            $ids=IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
            if(count($ids)!==1)throw new RuntimeException('Exactly one WebhookControl is required.');
            $id=(int)$ids[0];if(IPS_HasChanges($id))return;
            $old=json_decode(IPS_GetProperty($id,'Hooks'),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($old)||!array_is_list($old))throw new RuntimeException('Invalid webhook registry.');
            $next=[];$found=false;
            foreach($old as $row){
                if(($row['Hook']??'')===$this->path()){
                    if(($row['TargetID']??0)!==$this->InstanceID)throw new RuntimeException('Webhook belongs to another instance.');
                    if(!$this->ReadPropertyBoolean('WebEnabled')||$found)continue;$found=true;
                }$next[]=$row;
            }
            if(!$found&&$this->ReadPropertyBoolean('WebEnabled'))$next[]=['Hook'=>$this->path(),'TargetID'=>$this->InstanceID];
            if($next!==$old){IPS_SetProperty($id,'Hooks',json_encode($next,JSON_THROW_ON_ERROR));IPS_ApplyChanges($id);}
            $this->configuration();$backend=$this->backend();
            $this->SetValue('Status',$backend?'Ready — native Secrets passkey session required.':'Select an enabled native Secrets backend with a linked frontend.');
            $this->SetTimerInterval('Setup',0);
        }catch(Throwable $e){$this->SetValue('Status',$e->getMessage());$this->debug($e->getMessage());}
        finally{IPS_SemaphoreLeave('ALPORT.WebhookControl');}
    }
    public function GetConfigurationForm()
    {
        $f=json_decode(file_get_contents(__DIR__.'/form.json'),true,64,JSON_THROW_ON_ERROR);
        try{$rooms=$this->configuration();foreach($f['elements'] as &$element)if(($element['name']??'')==='Rooms')$element['values']=$rooms;unset($element);}catch(Throwable $e){}
        $f['actions'][]=['type'=>'Label','caption'=>'Page: '.$this->path().' — open using the native Secrets HTTPS address.'];
        return json_encode($f,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private static function validate(array $p): array
    {
        $expected=['WebEnabled'=>'boolean','DebugEnabled'=>'boolean','BackendInstanceID'=>'integer','PortalInstanceID'=>'integer','DocumentationInstanceID'=>'integer','PollSeconds'=>'integer','StaleSeconds'=>'integer','Rooms'=>'string'];
        if(array_diff(array_keys($p),array_keys($expected))||array_diff(array_keys($expected),array_keys($p)))throw new InvalidArgumentException('Configuration fields do not match RoomClimate v1.');
        foreach($expected as $key=>$type)if(gettype($p[$key])!==$type)throw new InvalidArgumentException('Invalid type: '.$key);
        foreach(['BackendInstanceID','PortalInstanceID','DocumentationInstanceID'] as $k)if($p[$k]<0)throw new InvalidArgumentException('Invalid instance ID.');
        if($p['PollSeconds']<1||$p['PollSeconds']>60||$p['StaleSeconds']<60||$p['StaleSeconds']>604800)throw new InvalidArgumentException('Invalid update/freshness interval.');
        if(strlen($p['Rooms'])>262144)throw new InvalidArgumentException('Room mapping is too large.');
        $rooms=json_decode($p['Rooms'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($rooms)||!array_is_list($rooms)||count($rooms)>100)throw new InvalidArgumentException('Use at most 100 rooms.');
        $keys=[];$total=0;
        foreach($rooms as $r){
            if(!is_array($r)||array_diff(array_keys($r),['key','floor','name','sources'])||!is_string($r['key']??null)||!preg_match('/^[a-z0-9-]{1,64}$/D',$r['key'])||isset($keys[$r['key']]))throw new InvalidArgumentException('Invalid or duplicate room key.');
            $keys[$r['key']]=true;
            foreach(['floor','name'] as $k)if(!is_string($r[$k]??null)||trim($r[$k])===''||strlen($r[$k])>120)throw new InvalidArgumentException('Room/floor name is missing or too long.');
            if(!is_array($r['sources']??null)||!array_is_list($r['sources'])||count($r['sources'])>32)throw new InvalidArgumentException('Use up to 32 sources per room.');
            $seen=[];
            foreach($r['sources'] as $v){
                if(!is_array($v)||array_diff(array_keys($v),['id','kind','label'])||!is_int($v['id']??null)||$v['id']<=1||!in_array($v['kind']??'', ['temperature','target','humidity'],true)||!is_string($v['label']??null)||trim($v['label'])===''||strlen($v['label'])>160||isset($seen[$v['id']]))throw new InvalidArgumentException('Invalid or duplicate room source.');
                $seen[$v['id']]=true;++$total;
            }
        }
        if($total>1000)throw new InvalidArgumentException('Use at most 1000 mapped sources.');return $rooms;
    }
    /** Normalize older multi-target mappings without changing heating controllers or saved device values. */
    private static function singleTargets(array $rooms): array
    {
        $homeMaticIDs=[10232,16991,26861,27558,28554,38934,39084,40482,42211,58174];
        foreach($rooms as &$room){
            $best=null;$rank=PHP_INT_MAX;
            foreach($room['sources'] as $source){
                if($source['kind']!=='target'||in_array($source['id'],$homeMaticIDs,true)||preg_match('/home[\s-]*matic/i',$source['label']))continue;
                // FRITZ replaces the older Shelly mappings in the supplied Berlin inventory.
                // Select deterministically, never switch targets based on timestamp or availability.
                $candidate=preg_match('/FRITZ/i',$source['label'])?0:(preg_match('/Shelly|thermostat|radiator|TRV/i',$source['label'])?1:2);
                if($candidate<$rank){$rank=$candidate;$best=$source;}
            }
            $room['sources']=array_values(array_filter($room['sources'],static fn($source)=>$source['kind']!=='target'));
            if($best!==null)$room['sources'][]=$best;
        }unset($room);return $rooms;
    }
    private function configuration(): array {return self::singleTargets(self::validate(json_decode(IPS_GetConfiguration($this->InstanceID),true,64,JSON_THROW_ON_ERROR)));}
    private static function normalizedProperties(array $properties): array
    {
        $properties['Rooms']=json_encode(self::singleTargets(self::validate($properties)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);return $properties;
    }
    private static function canonical($v): string
    {
        $sort=function($x)use(&$sort){if(!is_array($x))return $x;if(!array_is_list($x))ksort($x,SORT_STRING);foreach($x as &$a)$a=$sort($a);return $x;};
        return json_encode($sort($v),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    public function ExportConfig(): string {return json_encode(['schema'=>'RoomClimate.v1','properties'=>self::normalizedProperties(json_decode(IPS_GetConfiguration($this->InstanceID),true))],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    public function ImportConfig(string $JSON): string
    {
        if(strlen($JSON)>524288)throw new InvalidArgumentException('Import too large.');$v=json_decode($JSON,true,64,JSON_THROW_ON_ERROR);
        if(($v['schema']??'')!=='RoomClimate.v1'||!is_array($v['properties']??null)||array_diff(array_keys($v),['schema','properties']))throw new InvalidArgumentException('Unsupported configuration.');
        $v['properties']=self::normalizedProperties($v['properties']);$v['properties']['WebEnabled']=false;
        IPS_SetConfiguration($this->InstanceID,json_encode($v['properties'],JSON_THROW_ON_ERROR));IPS_ApplyChanges($this->InstanceID);
        return 'Imported; review variable IDs and native Secrets backend, then enable the page.';
    }
    public function ExportConfigurationBackup(): string
    {
        $p=json_decode(IPS_GetConfiguration($this->InstanceID),true,64,JSON_THROW_ON_ERROR);self::validate($p);
        return json_encode(['schema'=>'Symcon.ConfigurationBackup.v1','moduleID'=>self::MODULE,'format'=>'RoomClimate.v1','payload'=>json_decode($this->ExportConfig(),true),'sourceHash'=>hash('sha256',self::canonical(['properties'=>$p,'attributes'=>[]])),'attributeNames'=>[],'restoreMode'=>'manual-native-import','exclusions'=>['Sessions','Live readings','History cache','Debug log'],'notes'=>['Use ImportConfig; restored web access is disabled.']],JSON_THROW_ON_ERROR);
    }
    public function ValidateConfigurationBackup(string $JSON): string
    {
        $v=json_decode($JSON,true,64,JSON_THROW_ON_ERROR);$issues=[];
        try{if(($v['schema']??'')!=='RoomClimate.v1'||array_diff(array_keys($v),['schema','properties']))throw new RuntimeException('Unsupported configuration schema.');self::validate($v['properties']??[]);}catch(Throwable $e){$issues[]=$e->getMessage();}
        return json_encode(['schema'=>'Symcon.ConfigurationBackupValidation.v1','valid'=>!$issues,'scope'=>'format','payloadHash'=>hash('sha256',self::canonical($v)),'issues'=>$issues,'checks'=>['Native importer schema and property validation'],'notes'=>['No restore was executed.']],JSON_THROW_ON_ERROR);
    }
    public function ExportToForm(): void {$this->UpdateFormField('BackupJSON','value',$this->ExportConfig());}
    private function reading(int $id,string $kind): array
    {
        try{
            if(!IPS_VariableExists($id)||!in_array(IPS_GetVariable($id)['VariableType'],[1,2],true))return ['value'=>null,'updated'=>0,'changed'=>0,'status'=>'missing'];
            $v=IPS_GetVariable($id);$n=GetValue($id);$updated=(int)($v['VariableUpdated']??0);
            if(!is_numeric($n)||!is_finite((float)$n)||($kind==='humidity'?($n<0||$n>100):($n< -50||$n>100)))return ['value'=>null,'updated'=>$updated,'changed'=>(int)($v['VariableChanged']??0),'status'=>'invalid'];
            return ['value'=>(float)$n,'updated'=>$updated,'changed'=>(int)($v['VariableChanged']??0),'status'=>$kind!=='target'&&(!$updated||time()-$updated>=self::STALE_AFTER)?'stale':'ok'];
        }catch(Throwable $e){return ['value'=>null,'updated'=>0,'changed'=>0,'status'=>'unavailable'];}
    }
    private function state(string $cursor): array
    {
        $rooms=$this->configuration();$layout=hash('sha256',self::canonical($rooms));
        if(!IPS_SemaphoreEnter('RCL.State.'.$this->InstanceID,1000))throw new RuntimeException('State is busy; retry shortly.');
        try{
            $cache=json_decode($this->GetBuffer('Snapshot'),true)??[];
            if(($cache['layout']??'')!==$layout)$cache=['layout'=>$layout,'epoch'=>bin2hex(random_bytes(8)),'revision'=>0,'values'=>[],'versions'=>[]];
            $readings=[];foreach($rooms as $r)foreach($r['sources'] as $s)$readings[(string)$s['id']]=$this->reading($s['id'],$s['kind']);
            foreach($readings as $id=>$value)if(($cache['values'][$id]??null)!=$value){$cache['values'][$id]=$value;$cache['versions'][$id]=++$cache['revision'];}
            $parts=explode(':',$cursor,2);$full=count($parts)!==2||$parts[0]!==$cache['epoch']||!ctype_digit($parts[1])||(int)$parts[1]>$cache['revision'];$since=$full?-1:(int)$parts[1];$delta=[];
            foreach($cache['values'] as $id=>$value)if($full||$cache['versions'][$id]>$since)$delta[$id]=$value;
            $this->SetBuffer('Snapshot',json_encode($cache,JSON_THROW_ON_ERROR));
            return ['cursor'=>$cache['epoch'].':'.$cache['revision'],'full'=>$full,'rooms'=>$full?$rooms:null,'values'=>(object)$delta,'now'=>time(),'poll'=>$this->ReadPropertyInteger('PollSeconds'),'navigation'=>$full?$this->navigation():null];
        }finally{IPS_SemaphoreLeave('RCL.State.'.$this->InstanceID);}
    }
    protected function ProcessHookData()
    {
        $r=$this->response($_SERVER,$_GET);http_response_code($r['status']);foreach($r['headers'] as $k=>$v)header($k.': '.$v);echo $r['body'];
    }
    private function response(array $server,array $query): array
    {
        $headers=['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','Content-Type'=>'application/json; charset=utf-8'];
        $reply=static fn(int $status,$data)=>['status'=>$status,'headers'=>$headers,'body'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
        if(!$this->ReadPropertyBoolean('WebEnabled'))return $reply(404,['error'=>'Page disabled.']);
        if(($server['REQUEST_METHOD']??'GET')!=='GET')return $reply(405,['error'=>'GET required.']);
        try{
            $backend=$this->backend();if(!$backend)throw new RuntimeException('Select an enabled native Secrets backend and frontend.');
            // Validates current HTTPS transport, primary or approved backup origin in the native backend.
            $login=SECB_GetNativePortalLoginURL($backend,$this->path(),json_encode(array_intersect_key($server,array_flip(['HTTP_HOST','HTTP_COOKIE','REMOTE_ADDR','HTTPS','REQUEST_SCHEME','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_PROTO','HTTP_FORWARDED'])),JSON_THROW_ON_ERROR));
            if(SECB_IsNativePortalAuthenticated($backend)!==true){
                $r=$reply(401,['error'=>'Sign in with your passkey.','login'=>$login]);
                if(!isset($query['action'])){$r['status']=303;$r['headers']['Location']=$login;}return $r;
            }
            $action=$query['action']??'page';if(!is_string($action))return $reply(400,['error'=>'Invalid action.']);
            if($action==='state')return $reply(200,$this->state(is_string($query['cursor']??null)?substr($query['cursor'],0,80):''));
            if($action==='history')return $reply(200,$this->history($query));
            if($action!=='page')return $reply(404,['error'=>'Unknown action.']);
            $nonce=base64_encode(random_bytes(18));$headers['Content-Type']='text/html; charset=utf-8';$headers['Content-Security-Policy']="default-src 'none'; script-src 'nonce-".$nonce."'; style-src 'nonce-".$nonce."'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
            return ['status'=>200,'headers'=>$headers,'body'=>str_replace('{{NONCE}}',$nonce,file_get_contents(__DIR__.'/page.html'))];
        }catch(InvalidArgumentException $e){return $reply(400,['error'=>$e->getMessage()]);}
        catch(Throwable $e){$this->debug('Request failed: '.get_class($e));return $reply(503,['error'=>'Room climate unavailable. Check the instance status, native Secrets connection and debug log.']);}
    }
}
