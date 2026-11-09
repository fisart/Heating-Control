<?php
declare(strict_types=1);
require __DIR__.'/ventilation-fixture.php';
$root=getenv('VMC_BACKUP_MODULE');if(!$root)throw new RuntimeException('Set VMC_BACKUP_MODULE to an independently obtained Symcon-Backup-Module checkout.');
require_once $root.'/ConfigurationBackup/Engine.php';
function IPS_GetModule($id){return ['Prefix'=>'VMC','ModuleName'=>'VentilationControl'];}
function VMC_ExportConfigurationBackup($id){return $GLOBALS['vmc']->ExportConfigurationBackup();}
function VMC_ValidateConfigurationBackup($id,$json){return $GLOBALS['vmc']->ValidateConfigurationBackup($json);}
$m=fixture();$capture=MCBBackupProtocol::capture(700,VentilationControl::MODULE,json_decode(IPS_GetConfiguration(700),true),[]);
if(($capture['validation']['valid']??false)!==true)throw new RuntimeException('Independent central backup capture/validation failed.');
$m->properties['StartHumidity']=60.0;$m->properties['MaximumTemperatureDrop']=2.0;$m->properties['RecirculationSpread']=2.0;
$capture=MCBBackupProtocol::capture(700,VentilationControl::MODULE,json_decode(IPS_GetConfiguration(700),true),[]);if(($capture['validation']['valid']??false)!==true)throw new RuntimeException('Whole-number float capture failed.');
$bad=json_decode(IPS_GetConfiguration(700),true);$bad['FanPercent']=90;
try{MCBBackupProtocol::capture(700,VentilationControl::MODULE,$bad,[]);throw new LogicException('Real mismatch was not rejected.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;if(!str_contains($e->getMessage(),'Saved settings differ'))throw $e;}
if($GLOBALS['commands']||$GLOBALS['calls'])throw new RuntimeException('Backup operated equipment.');
echo "Ventilation backup: independent central capture, float preservation, mismatch detection and read-only behavior passed.\n";
