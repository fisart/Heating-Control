<?php
declare(strict_types=1);
trait VentilationSecurity
{
    private function backend(): int
    {
        if(!function_exists('SECB_IsNativePortalAuthenticated')||!function_exists('SECB_GetNativePortalLoginURL'))return 0;
        $selected=$this->ReadPropertyInteger('BackendInstanceID');$ids=$selected>0?[$selected]:IPS_GetInstanceListByModuleID('{52165EDD-B2C0-40EC-8A59-42A97319B6E2}');$ok=[];
        foreach($ids as $id)try{
            if(!IPS_InstanceExists($id)||IPS_HasChanges($id)||strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')!=='{52165EDD-B2C0-40EC-8A59-42A97319B6E2}'||IPS_GetProperty($id,'Enabled')!==true)continue;
            $f=IPS_GetProperty($id,'FrontendInstanceID');
            if(!is_int($f)||!IPS_InstanceExists($f)||IPS_HasChanges($f)||strtoupper(IPS_GetInstance($f)['ModuleInfo']['ModuleID']??'')!=='{8D6AE8F7-EA3A-4513-AA14-C3F9613B0E83}'||IPS_GetProperty($f,'Enabled')!==true||IPS_GetProperty($f,'BackendInstanceID')!==$id)continue;
            if(IPS_GetProperty($f,'AdminOrigin')!==IPS_GetProperty($id,'AdminOrigin'))continue;
            $ok[]=(int)$id;
        }catch(Throwable $e){continue;}
        return count($ok)===1?$ok[0]:0;
    }
    private function navigation(): array
    {
        $out=[];
        foreach(['portal'=>['{922E5A10-A048-463D-A973-97B4BBD9096B}','PortalInstanceID','portal'],'documentation'=>['{FBEB2EF6-8D23-49D7-A256-6D0C8D973B24}','DocumentationInstanceID','alarm_documentation_']] as $key=>[$module,$property,$prefix]){
            $selected=$this->ReadPropertyInteger($property);$ids=$selected>0?[$selected]:IPS_GetInstanceListByModuleID($module);$paths=[];
            foreach($ids as $id)try{if(IPS_InstanceExists($id)&&strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')===$module&&IPS_GetProperty($id,'Enabled')===true)$paths[]=$key==='portal'?'/hook/portal':'/hook/'.$prefix.$id;}catch(Throwable $e){}
            if(count($paths)===1)$out[$key]=$paths[0].($key==='documentation'?'#documentation-details':'');
        }return $out;
    }
}
