<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
require __DIR__.'/../HeatingControl/HeatPumpMatrix.php';
function IPS_InstanceExists($id){return isset($GLOBALS['instanceModules'][$id]);}
function IPS_GetInstance($id){return ['ModuleInfo'=>['ModuleID'=>$GLOBALS['instanceModules'][$id]]];}
function IPS_GetInstanceListByModuleID($m){return array_keys(array_filter($GLOBALS['instanceModules'],static fn($id)=>$id===$m));}
function IPS_GetProperty($id,$key){return $GLOBALS['instanceProperties'][$id][$key]??'';}
function IPS_GetObjectIDByIdent($key,$id){return $GLOBALS['idents'][$id][$key]??0;}
$GLOBALS['instanceModules']=[7=>'{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}',5=>'{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}',10=>'{8B333AC9-EE67-4D24-8CE7-433BBB391E6C}'];
$GLOBALS['instanceProperties']=[7=>['PortalEnabled'=>true,'PortalOrigin'=>'https://prices.example'],5=>['Hooks'=>'[{"Hook":"/hook/heating_10","TargetID":10}]'],10=>['ElectricityPriceInstanceID'=>1,'WebEnabled'=>true,'VaultInstanceID'=>7]];
$m=newModule();$m->properties['VaultInstanceID']=7;$m->properties['Enabled']=true;$m->properties['TariffVerified']=true;
$now=time();$snapshot=['schema'=>1,'valid'=>true,'updatedAt'=>$now,'validUntil'=>$now+600,'source'=>1,'powerPercent'=>50,'currentPriceCt'=>19,
    'gasPriceCt'=>10.48,'safetyMarginPercent'=>25,'hysteresisCt'=>2,'breakEvenCt'=>37.8,'entryCt'=>26.35,'exitCt'=>28.35,'reason'=>'Measured'];
$items=['AutomaticSourceSelection'=>true,'EffectiveSource'=>1,'SelectedHeatPumpPower'=>50,'HeatPumpEconomicsJSON'=>json_encode($snapshot),'HeatPumpModelJSON'=>json_encode(['schema'=>2,'points'=>HeatPumpMatrix::seed()])];
foreach($items as $name=>$value){$id=90000+count($GLOBALS['idents'][10]??[]);$GLOBALS['idents'][10][$name]=$id;$GLOBALS['liveVariables'][$id]=['VariableType'=>is_bool($value)?0:(is_int($value)?1:3),'value'=>$value];}
function view($m){return (new ReflectionMethod(LichtBlickElectricityPrice::class,'measuredHeatingView'))->invoke($m);}
function snapshot(array $e){$GLOBALS['liveVariables'][$GLOBALS['idents'][10]['HeatPumpEconomicsJSON']]['value']=json_encode($e);}
$m->response=quote(19,(int)(floor(time()/900)*900));$m->RefreshPrices();
$v=view($m);check($v['connected']&&$v['path']==='/hook/heating_10'&&$v['automatic']&&$v['economics']['source']===1,'One matched measured controller and registered same-vault link');
check(count($v['points'])===8,'Only current measured matrix is exposed');
check($m->values['RecommendedSource']===1&&$m->values['ThresholdCt']===37.8,'Provider mirrors measured comparison rather than a retired threshold');
$before=serialize([$m->values,$m->attributes,$m->properties,$m->updates]);view($m);check($before===serialize([$m->values,$m->attributes,$m->properties,$m->updates]),'Cross-page status/link lookup is read only');
$GLOBALS['instanceProperties'][5]['Hooks']='[{"Hook":"/hook/heating_10","TargetID":999}]';check(view($m)['path']==='','Link rejected when route belongs to another instance');
$GLOBALS['instanceProperties'][5]['Hooks']='[{"Hook":"/hook/heating_10","TargetID":10}]';$GLOBALS['instanceProperties'][10]['WebEnabled']=false;check(view($m)['path']==='','Disabled target has no link');$GLOBALS['instanceProperties'][10]['WebEnabled']=true;
$GLOBALS['instanceProperties'][10]['VaultInstanceID']=1;check(view($m)['path']==='/hook/heating_10','Symcon unselected-instance sentinel resolves the one eligible vault');$GLOBALS['instanceProperties'][10]['VaultInstanceID']=7;
$GLOBALS['instanceProperties'][10]['VaultInstanceID']=8;check(view($m)['path']==='','Different authentication vault has no cross-page link');$GLOBALS['instanceProperties'][10]['VaultInstanceID']=7;
$GLOBALS['instanceModules'][11]='{8B333AC9-EE67-4D24-8CE7-433BBB391E6C}';$GLOBALS['instanceProperties'][11]=['ElectricityPriceInstanceID'=>1];check(!view($m)['connected'],'Ambiguous multiple controllers never select an arbitrary source');unset($GLOBALS['instanceModules'][11]);
foreach([['updatedAt'=>$now-181],['validUntil'=>$now],['gasPriceCt'=>99],['safetyMarginPercent'=>0],['currentPriceCt'=>100],['powerPercent'=>101]] as $bad){snapshot(array_replace($snapshot,$bad));check(view($m)['economics']===null,'Invalid/expired/mismatched measured comparison rejected');}
snapshot($snapshot);$GLOBALS['instanceModules'][10]='{WRONG}';check(!view($m)['connected'],'Unrelated module cannot provide the displayed decision');
echo "PASS: measured controller discovery, quote consistency, read-only status and cross-page link ownership/authentication checks\n";
