<?php
declare(strict_types=1);

/** Read the one controller explicitly connected to this price provider; never command it. */
trait LichtBlickMeasuredHeating
{
    private function measuredHeatingView(): array
    {
        $view=['connected'=>false,'instanceID'=>0,'path'=>'','automatic'=>false,'effectiveSource'=>0,'selectedPower'=>0,
            'economics'=>null,'points'=>[],'reason'=>'No Heating Control instance is connected to this price provider.'];
        if(!function_exists('IPS_GetInstanceListByModuleID')||!function_exists('IPS_GetObjectIDByIdent'))return $view;
        try {
            $ids=[];
            foreach(IPS_GetInstanceListByModuleID('{8B333AC9-EE67-4D24-8CE7-433BBB391E6C}') as $id)
                if((int)IPS_GetProperty($id,'ElectricityPriceInstanceID')===$this->InstanceID)$ids[]=(int)$id;
            if(count($ids)!==1){if($ids)$view['reason']='Several heating controllers use this provider; no single decision or control link can be selected.';return $view;}
            $id=$ids[0];$view['connected']=true;$view['instanceID']=$id;
            $read=static function(string $ident,int $type)use($id){$variable=IPS_GetObjectIDByIdent($ident,$id);
                if(!$variable||!IPS_VariableExists($variable)||IPS_GetVariable($variable)['VariableType']!==$type)throw new RuntimeException('Heating status unavailable: '.$ident);return GetValue($variable);};
            $view['automatic']=$read('AutomaticSourceSelection',0)===true;
            $view['effectiveSource']=(int)$read('EffectiveSource',1);$view['selectedPower']=(int)$read('SelectedHeatPumpPower',1);
            $raw=$read('HeatPumpEconomicsJSON',3);$e=strlen($raw)<1048576?json_decode($raw,true,32):null;$now=time();
            $valid=is_array($e)&&($e['schema']??0)===1&&($e['valid']??false)===true
                &&is_int($e['updatedAt']??null)&&$e['updatedAt']<=$now+30&&$now-$e['updatedAt']<=180
                &&is_int($e['powerPercent']??null)&&$e['powerPercent']>=1&&$e['powerPercent']<=100
                &&is_int($e['validUntil']??null)&&$e['validUntil']>$now&&in_array($e['source']??0,[1,2],true);
            foreach(['currentPriceCt','gasPriceCt','safetyMarginPercent','hysteresisCt','breakEvenCt','entryCt','exitCt'] as $k)
                $valid=$valid&&is_numeric($e[$k]??null)&&is_finite((float)($e[$k]??NAN));
            if($valid)$valid=abs($e['currentPriceCt']-(float)$this->GetValue('CurrentPriceCt'))<0.0001
                &&abs($e['gasPriceCt']-$this->ReadPropertyFloat('GasPriceCt'))<0.0001
                &&abs($e['safetyMarginPercent']-$this->ReadPropertyFloat('SafetyMarginPercent'))<0.0001
                &&abs($e['hysteresisCt']-$this->ReadPropertyFloat('HysteresisCt'))<0.0001
                &&$this->GetValue('PriceValid')===true;
            $view['economics']=$valid?$e:null;
            $view['reason']=$valid?(string)($e['reason']??'Measured heating comparison'):(string)($e['reason']??'Enable automatic source selection in Heating Control to evaluate the current measured comparison.');
            if(!$valid&&($e['valid']??false))$view['reason']='Measured comparison expired or price/settings changed; waiting for Heating Control evaluation.';
            $model=$read('HeatPumpModelJSON',3);$m=strlen($model)<2097152?json_decode($model,true,64):null;
            if(($m['schema']??0)===2&&is_array($m['points']??null)&&count($m['points'])<=512)$view['points']=$m['points'];
            // Cross-page links require the same vault AND a registered route owned by that controller.
            if(IPS_GetProperty($id,'WebEnabled')) {
                $vault=(int)IPS_GetProperty($id,'VaultInstanceID');$own=$this->webVault();
                if($vault<=1){$eligible=[];foreach(IPS_GetInstanceListByModuleID(self::PORTAL_VAULT) as $candidate)if(IPS_GetProperty($candidate,'PortalEnabled')===true&&$this->webOrigin((string)IPS_GetProperty($candidate,'PortalOrigin'))!=='')$eligible[]=(int)$candidate;$vault=count($eligible)===1?$eligible[0]:0;}
                if($vault===$own&&$own>0)foreach(IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL) as $hook) {
                    $routes=json_decode((string)IPS_GetProperty($hook,'Hooks'),true);
                    foreach(is_array($routes)?$routes:[] as $route)if(($route['Hook']??'')==='/hook/heating_'.$id&&($route['TargetID']??0)===$id)$view['path']='/hook/heating_'.$id;
                }
            }
        }catch(Throwable $e){$view['reason']='Heating comparison unavailable: '.$e->getMessage();$view['economics']=null;}
        return $view;
    }
}
