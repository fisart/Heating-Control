<?php
declare(strict_types=1);
trait VentilationWeb
{
    private function csrf(array $server,int $backend,int $slot): string
    {
        preg_match_all('/(?:^|;\s*)__Host-SECB_'.preg_quote((string)$backend,'/').'=([A-Za-z0-9_-]{43})(?=;|$)/',(string)($server['HTTP_COOKIE']??''),$matches);
        if(count($matches[1])!==1)throw new RuntimeException('Native passkey session cookie unavailable.');
        return hash_hmac('sha256',$this->InstanceID.'|'.$backend.'|'.$matches[1][0].'|'.$slot,$this->ReadAttributeString('CSRFKey'));
    }
    private static function origin(string $value): string
    {
        $p=parse_url($value);if(!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true))return '';
        return 'https://'.strtolower($p['host']).((int)($p['port']??443)===443?'':':'.$p['port']);
    }
    protected function ProcessHookData()
    {
        $body=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?(string)file_get_contents('php://input',false,null,0,524289):'';
        $r=$this->response($_SERVER,$_GET,$body);http_response_code($r['status']);foreach($r['headers'] as $k=>$v)header($k.': '.$v);echo $r['body'];
    }
    private function webState(): array
    {
        $s=$this->snapshot();$s['recommendation']=$this->recommendation($s);$h=$s['heating'];if($h){unset($h['config'],$h['rooms'],$h['configHash']);$s['heating']=$h;}
        $s['status']=$this->GetValue('Status');$s['running']=$this->GetValue('Running');$s['airMode']=$this->GetValue('AirMode');
        $s['enabled']=$this->ReadPropertyBoolean('Enabled');$s['automatic']=$this->ReadPropertyBoolean('AutomaticEnabled');$s['live']=$this->ReadPropertyBoolean('LiveOutputs');
        $s['recoveryPending']=$this->ReadAttributeString('Lease')!=='';$s['maximumSeconds']=$this->ReadPropertyInteger('MaxCycleSeconds');$s['defaultSeconds']=$this->ReadPropertyInteger('CycleSeconds');$s['navigation']=$this->navigation();
        $backup=$this->ReadPropertyInteger('BackupInstanceID');if($backup>0&&IPS_InstanceExists($backup))$s['navigation']['backup']='/hook/module_backup_'.$backup;
        $id=$this->ReadPropertyInteger('HeatingInstanceID');if(IPS_InstanceExists($id))$s['navigation']['heating']='/hook/heating_'.$id;
        return $s;
    }
    private function saveWebSettings(array $data): string
    {
        if(!is_string($data['revision']??null)||!is_array($data['properties']??null))throw new InvalidArgumentException('Invalid settings request.');
        if(!IPS_SemaphoreEnter('VMC.Control.'.$this->InstanceID,1000))throw new RuntimeException('Control is busy; retry.');
        try{
            if(!hash_equals($this->revision(),$data['revision']))throw new InvalidArgumentException('Settings changed elsewhere. Reload Admin before saving.');
            $p=$data['properties'];
            // JavaScript has one Number type; preserve module float types when a
            // web form submits an integral numeric value such as 60 or 8.
            foreach(self::FLOATS as $key=>$_)if(isset($p[$key])&&(is_int($p[$key])||is_float($p[$key])))$p[$key]=(float)$p[$key];
            if(isset($p['Rooms'])&&is_array($p['Rooms'])){foreach($p['Rooms'] as &$room)if(isset($room['minimumTemperature'])&&(is_int($room['minimumTemperature'])||is_float($room['minimumTemperature'])))$room['minimumTemperature']=(float)$room['minimumTemperature'];unset($room);$p['Rooms']=self::json($p['Rooms']);}
            $this->save($p);return 'Settings saved. Fixed sensor selections are retained across restarts.';
        }finally{IPS_SemaphoreLeave('VMC.Control.'.$this->InstanceID);}
    }
    private function response(array $server,array $query,string $body=''): array
    {
        $headers=['Cache-Control'=>'no-store','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','Content-Type'=>'application/json; charset=utf-8'];
        $reply=static fn(int $status,$data)=>['status'=>$status,'headers'=>$headers,'body'=>self::json($data)];
        if(!$this->ReadPropertyBoolean('WebEnabled'))return $reply(404,['error'=>'Page disabled.']);$method=$server['REQUEST_METHOD']??'GET';
        if(!in_array($method,['GET','POST'],true))return $reply(405,['error'=>'GET or POST required.']);
        try{
            $backend=$this->backend();if(!$backend)throw new RuntimeException('Select an enabled native Secrets backend and frontend.');
            $transport=array_intersect_key($server,array_flip(['HTTP_HOST','HTTP_COOKIE','REMOTE_ADDR','HTTPS','REQUEST_SCHEME','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_PROTO','HTTP_FORWARDED']));
            $login=SECB_GetNativePortalLoginURL($backend,$this->path(),self::json($transport));
            if(SECB_IsNativePortalAuthenticated($backend)!==true){$r=$reply(401,['error'=>'Sign in with your passkey.','login'=>$login]);if(!isset($query['action'])){$r['status']=303;$r['headers']['Location']=$login;}return $r;}
            $action=$query['action']??'page';if(!is_string($action))return $reply(400,['error'=>'Invalid action.']);
            if($method==='POST'){
                if(IPS_GetKernelRunlevel()!==KR_READY)return $reply(503,['error'=>'Symcon is starting.']);
                $url=parse_url($login);$trusted=self::origin('https://'.($url['host']??'').(isset($url['port'])?':'.$url['port']:''));
                if(!$trusted||self::origin((string)($server['HTTP_ORIGIN']??''))!==$trusted||($server['HTTP_SEC_FETCH_SITE']??'same-origin')!=='same-origin')return $reply(403,['error'=>'Same-origin access required.']);
                if(strlen($body)>524288||!preg_match('~^application/json(?:\s*;|$)~i',(string)($server['CONTENT_TYPE']??$server['HTTP_CONTENT_TYPE']??'')))return $reply(400,['error'=>'Use a bounded JSON request.']);
                $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);if(!is_array($data)||!is_string($data['csrf']??null))return $reply(403,['error'=>'Session verification required.']);$slot=(int)floor(time()/900);
                if(!hash_equals($this->csrf($server,$backend,$slot),$data['csrf'])&&!hash_equals($this->csrf($server,$backend,$slot-1),$data['csrf']))return $reply(403,['error'=>'Session verification expired; reload the page.']);
                switch($action){
                    case 'settings':$message=$this->saveWebSettings($data);break;
                    case 'enabled':if(!is_bool($data['value']??null))throw new InvalidArgumentException('Boolean value required.');$this->SetModuleEnabled($data['value']);$message='Module '.($data['value']?'enabled.':'disabled; an active cycle returns control to heating.');break;
                    case 'automatic':if(!is_bool($data['value']??null))throw new InvalidArgumentException('Boolean value required.');$this->SetAutomatic($data['value']);$message='Automatic ventilation '.($data['value']?'enabled.':'disabled.');break;
                    case 'start':if(!is_string($data['mode']??null)||!is_int($data['seconds']??null))throw new InvalidArgumentException('Mode and whole seconds required.');$message=$this->ManualStart($data['mode'],$data['seconds']);break;
                    case 'stop':$this->Stop();$message='Stop requested; automatic mode is off.';break;
                    case 'select-lowest':$message=$this->SelectLowestSensors();break;
                    case 'portal':$message=$this->RegisterPortalPage();break;
                    case 'accept-handover':$message=$this->AcceptExternalHandover();break;
                    case 'import':if(!is_string($data['configuration']??null))throw new InvalidArgumentException('Paste an exported configuration.');$message=$this->ImportConfig($data['configuration']);break;
                    default:return $reply(404,['error'=>'Unknown command.']);
                }return $reply(200,['message'=>$message]);
            }
            if($action==='state')return $reply(200,$this->webState()+['csrf'=>$this->csrf($server,$backend,(int)floor(time()/900))]);
            if($action==='admin'){$p=$this->properties();$p['Rooms']=json_decode($p['Rooms'],true);return $reply(200,['properties'=>$p,'revision'=>$this->revision(),'debug'=>$this->GetValue('Debug'),'integration'=>$this->GetValue('IntegrationStatus'),'csrf'=>$this->csrf($server,$backend,(int)floor(time()/900))]);}
            if($action==='export')return $reply(200,json_decode($this->ExportConfig(),true));
            if($action==='guide')return $reply(200,['text'=>file_get_contents(__DIR__.'/guide.md')]);
            if($action!=='page')return $reply(405,['error'=>'This command requires a JSON POST.']);
            $nonce=base64_encode(random_bytes(18));$headers['Content-Type']='text/html; charset=utf-8';$headers['Content-Security-Policy']="default-src 'none'; script-src 'nonce-".$nonce."'; style-src 'nonce-".$nonce."'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
            return ['status'=>200,'headers'=>$headers,'body'=>str_replace('{{NONCE}}',$nonce,file_get_contents(__DIR__.'/page.html'))];
        }catch(InvalidArgumentException|JsonException $e){return $reply(400,['error'=>$e->getMessage()]);}
        catch(Throwable $e){$this->debug('Web request failed: '.$e->getMessage());return $reply(503,['error'=>$e->getMessage()]);}
    }
}
