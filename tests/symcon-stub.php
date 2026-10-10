<?php
declare(strict_types=1);

// Run with: php tests/room-editor.php. No Symcon installation or actuators needed.
const KR_READY = 10103;
const VM_UPDATE = 10603;
const IPS_KERNELMESSAGE = 10100;
const KL_ERROR = 0;

class IPSModule
{
    public int $InstanceID = 1;
    public array $properties = [], $attributes = [], $values = [], $messages = [], $formUpdates = [];
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyBoolean($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyString($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyFloat($n, $v) { $this->properties[$n] = $v; }
    public function RegisterAttributeString($n, $v) { $this->attributes[$n] = $v; }
    public function RegisterVariableString($n, ...$args) { $this->values[$n] = ''; }
    public function RegisterVariableBoolean($n, ...$args) { $this->values[$n] = false; }
    public function RegisterTimer(...$args) {}
    public function SetTimerInterval(...$args) {}
    public function ReadPropertyString($n) { return $this->properties[$n]; }
    public function ReadPropertyBoolean($n) { return $this->properties[$n]; }
    public function ReadPropertyInteger($n) { return $this->properties[$n]; }
    public function ReadPropertyFloat($n) { return $this->properties[$n]; }
    public function ReadAttributeString($n) { return $this->attributes[$n]; }
    public function WriteAttributeString($n, $v) { $this->attributes[$n] = $v; }
    public function SetValue($n, $v) { $this->values[$n] = $v; }
    public function GetValue($n) { return $this->values[$n]; }
    public function Translate($s) { return $s; }
    public function RegisterMessage($id, $message) { $this->messages[$id] = $message; }
    public function UnregisterMessage($id, $message) { unset($this->messages[$id]); }
    public function UpdateFormField($n, $field, $v) { $this->formUpdates[$n][$field] = $v; }
    public function LogMessage(...$args) {}
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_VariableExists($id) { return isset($GLOBALS['variables'][$id]); }
function IPS_GetVariable($id) { return $GLOBALS['variables'][$id]; }
function IPS_ObjectExists($id) { return isset($GLOBALS['categories'][$id]) || IPS_VariableExists($id); }
function IPS_GetObject($id) { return ['ObjectType' => isset($GLOBALS['categories'][$id]) ? 0 : 2]; }
function IPS_GetConfiguration($id) { return json_encode($GLOBALS['instance']->properties); }
function IPS_SetConfiguration($id, $json) { $GLOBALS['instance']->properties = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
function IPS_ApplyChanges($id) { if ($id === $GLOBALS['instance']->InstanceID) $GLOBALS['instance']->ApplyChanges(); else $GLOBALS['applies'][] = $id; }
function IPS_SemaphoreEnter(...$args) { return true; }
function IPS_SemaphoreLeave(...$args) {}
function IPS_GetName($id) { return 'Variable ' . $id; }
function GetValue($id) { return $GLOBALS['variables'][$id]['value']; }
function GetValueFloat($id) { return (float)GetValue($id); }
function GetValueInteger($id) { return (int)GetValue($id); }
function GetValueBoolean($id) { return (bool)GetValue($id); }
function RequestAction($id, $value) { $GLOBALS['commands'][] = [$id, $value]; }
function directWrite($id, $value) { $GLOBALS['directWrites'][] = [$id, $value]; $GLOBALS['variables'][$id]['value'] = $value; }
function SetValueBoolean($id, $value) { directWrite($id, $value); }
function SetValueInteger($id, $value) { directWrite($id, $value); }
function SetValueFloat($id, $value) { directWrite($id, $value); }
function SetValueString($id, $value) { directWrite($id, $value); }

