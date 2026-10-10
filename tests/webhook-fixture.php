<?php
declare(strict_types=1);
require __DIR__ . '/symcon-stub.php';
require __DIR__ . '/../HeatingControl/module.php';
function IPS_InstanceExists($id) { return isset($GLOBALS['instanceModules'][$id]); }
function IPS_GetInstance($id) { return ['ModuleInfo'=>['ModuleID'=>$GLOBALS['instanceModules'][$id]]]; }
function IPS_GetInstanceListByModuleID($module) { return array_keys(array_filter($GLOBALS['instanceModules'],fn($v)=>$v===$module)); }
function IPS_GetProperty($id,$name) { return $GLOBALS['instanceProperties'][$id][$name] ?? ''; }
function IPS_SetProperty($id,$name,$value) { $GLOBALS['instanceProperties'][$id][$name]=$value; }
function IPS_HasChanges($id) { return $GLOBALS['pendingChanges'] ?? false; }
function IPS_VariableProfileExists($name) { return isset($GLOBALS['profiles'][$name]); }
function IPS_GetVariableProfile($name) { return $GLOBALS['profiles'][$name]; }
if (($argv[1] ?? '') !== '--no-archive-api') {
function AC_GetLoggingStatus($archive,$id) { if (!empty($GLOBALS['archiveThrows'])) throw new RuntimeException('Archive unavailable'); return $GLOBALS['archiveLogging'][$archive][$id] ?? false; }
function AC_GetAggregationType($archive,$id) { return $GLOBALS['archiveTypes'][$archive][$id] ?? 0; }
function AC_GetLoggedValues($archive,$id,$from,$to,$limit) { $GLOBALS['historyCalls'][]=[$archive,$id,'recorded',$from,$to,$limit]; return $GLOBALS['loggedValues'][$id] ?? []; }
function AC_GetAggregatedValues($archive,$id,$level,$from,$to,$limit) { $GLOBALS['historyCalls'][]=[$archive,$id,$level,$from,$to,$limit]; return $GLOBALS['archiveValues'][$id] ?? []; }
}
if (($argv[1] ?? '') !== '--no-auth-api') {
function SEC_IsPortalAuthenticated($id) { if (!empty($GLOBALS['authThrows'])) throw new RuntimeException('Private vault details'); return $GLOBALS['authenticated'] ?? false; }
}
function heatingFixture(): HeatingControl {
    $m=new HeatingControl();$m->Create();$GLOBALS['instance']=$m;
    $GLOBALS['variables']=[];$GLOBALS['categories']=[];$GLOBALS['commands']=[];$GLOBALS['directWrites']=[];$GLOBALS['applies']=[];
    $GLOBALS['instanceModules']=[7=>'{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}',5=>'{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'];
    $GLOBALS['instanceProperties']=[7=>['PortalEnabled'=>true,'PortalOrigin'=>'https://heating.example:46900','PortalBackupOrigin'=>'https://backup.example'],5=>['Hooks'=>'[{"Hook":"/hook/other","TargetID":88}]']];
    foreach ($m->properties as $key=>$id) if(str_ends_with($key,'ID') && $id>1) $GLOBALS['variables'][$id]=['VariableType'=>2,'VariableUpdated'=>time(),'value'=>25.0];
    foreach(['MasterDisableID','WinterID','NightDisableID','HolidayID','AtHomeID','FanOnID','HeatPumpOnID','HeatPumpHeatModeID','GasPumpID'] as $key) $GLOBALS['variables'][$m->properties[$key]]=['VariableType'=>0,'value'=>$key==='WinterID','VariableUpdated'=>time()];
    foreach(['OperatingModeID','FanSpeedID','FanDesiredSpeedID','HeatPumpPowerID','HeatPumpDesiredPowerID','GasMixerID'] as $key) $GLOBALS['variables'][$m->properties[$key]]=['VariableType'=>1,'value'=>$key==='OperatingModeID'?2:50,'VariableUpdated'=>time()];
    $GLOBALS['variables'][$m->properties['HysteresisID']]['value']=0.1;
    $GLOBALS['variables'][$m->properties['ResidualDeltaID']]['value']=2.0;
    $GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=27.13;
    $GLOBALS['variables'][$m->properties['IncomingAirTempID']]['value']=22.68;
    $GLOBALS['variables'][$m->properties['HeatExchangerTempID']]['value']=40.15;
    foreach(json_decode($m->properties['Rooms'],true) as $room) {
        foreach($room['sensors'] as $id) $GLOBALS['variables'][$id]=['VariableType'=>2,'value'=>23.4,'VariableUpdated'=>time()];
        $GLOBALS['variables'][$room['targetID']]=['VariableType'=>2,'value'=>23.0,'VariableUpdated'=>time()];
        $GLOBALS['variables'][$room['flapID']]=['VariableType'=>is_bool($room['open'])?0:1,'value'=>$room['closed'],'VariableUpdated'=>time()];
        $GLOBALS['variables'][$room['statusID']]=['VariableType'=>0,'value'=>false,'VariableUpdated'=>time()];
        $GLOBALS['categories'][$room['legacyGroupID']]=true;
    }
    foreach(['LegacyActionLogID'=>3,'LegacyResidualHeatingID'=>0,'LegacyLastHeatedGroupID'=>1,'LegacyConfigSnapshotID'=>3] as $key=>$type) $GLOBALS['variables'][$m->properties[$key]]=['VariableType'=>$type,'value'=>$type===3?'':($type===0?false:0)];
    $GLOBALS['variables'][61003]=['VariableType'=>0,'value'=>true,'VariableUpdated'=>time()];
    $m->properties['RecoveryEnableID']=61003;$m->properties['WebEnabled']=true;
    $m->properties['Enabled']=true;$m->properties['DryRun']=false;
    $m->attributes['LastDemandRoom']='Blue Room and Kitchen';
    $GLOBALS['archiveLogging']=[];$GLOBALS['archiveTypes']=[];$GLOBALS['archiveValues']=[];$GLOBALS['loggedValues']=[];$GLOBALS['historyCalls']=[];$GLOBALS['archiveThrows']=false;
    $GLOBALS['instanceModules'][9]='{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    $GLOBALS['authenticated']=false;$GLOBALS['authThrows']=false;$GLOBALS['pendingChanges']=false;
    $_COOKIE=['SEC_PORTAL_V2_7'=>'fixture-session'];
    return $m;
}
function webInvoke($m,$method,...$args) { return (new ReflectionMethod(HeatingControl::class,$method))->invoke($m,...$args); }
function response($m,$method='GET',$query=[],$payload=null,$overrides=[]): array {
    $server=array_replace(['REQUEST_METHOD'=>$method,'HTTP_HOST'=>'heating.example:46900','HTTP_ORIGIN'=>'https://heating.example:46900','HTTP_SEC_FETCH_SITE'=>'same-origin','CONTENT_TYPE'=>'application/json','HTTP_X_HEATING_CSRF'=>webInvoke($m,'webCSRF',7,(int)floor(time()/3600))],$overrides);
    return webInvoke($m,'webResponse',$server,$query,is_string($payload)?$payload:($payload===null?'':json_encode($payload)));
}
