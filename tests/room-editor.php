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
function IPS_GetConfiguration($id) { return json_encode($GLOBALS['instance']->properties); }
function IPS_SetConfiguration($id, $json) { $GLOBALS['instance']->properties = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
function IPS_ApplyChanges($id) { $GLOBALS['instance']->ApplyChanges(); }
function IPS_SemaphoreEnter(...$args) { return true; }
function IPS_SemaphoreLeave(...$args) {}
function IPS_GetName($id) { return 'Variable ' . $id; }
function GetValue($id) { return $GLOBALS['variables'][$id]['value']; }
function GetValueFloat($id) { return (float)GetValue($id); }
function GetValueInteger($id) { return (int)GetValue($id); }
function GetValueBoolean($id) { return (bool)GetValue($id); }
function RequestAction($id, $value) { $GLOBALS['commands'][] = [$id, $value]; }
function SetValueBoolean($id, $value) { RequestAction($id, $value); }
function SetValueInteger($id, $value) { RequestAction($id, $value); }
function SetValueFloat($id, $value) { RequestAction($id, $value); }

require __DIR__ . '/../HeatingControl/module.php';
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function privateCall($instance, string $name, ...$args)
{
    return (new ReflectionMethod(HeatingControl::class, $name))->invoke($instance, ...$args);
}
function rejects(callable $call, string $message): void
{
    try { $call(); } catch (InvalidArgumentException | RuntimeException $e) { return; }
    throw new RuntimeException($message);
}
function editorRows($instance): array
{
    $form = json_decode($instance->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($form['elements'] as $element) {
        if (($element['name'] ?? '') === 'RoomConfigurationPopup') return $element['popup']['items'][1]['values'];
    }
    throw new RuntimeException('Room popup missing');
}

$instance = new HeatingControl();
$GLOBALS['instance'] = $instance;
$GLOBALS['commands'] = [];
$instance->Create();
$legacy = json_decode($instance->properties['Rooms'], true, 512, JSON_THROW_ON_ERROR);
$before = $instance->properties;
$editor = editorRows($instance);
check($instance->properties === $before && !$GLOBALS['commands'], 'Opening popup must not mutate configuration or actuators');
check(count($editor) === 4, 'All existing rooms must appear');
check($editor[1]['flapType'] === 0 && $editor[1]['open'] === 1 && $editor[1]['closed'] === 0, 'Boolean commands must appear as 1/0');
check(privateCall($instance, 'decodeRoomRows', json_encode($editor)) === $legacy, 'Legacy values changed during popup round trip');

// A second sensor and reversed Boolean actuator must survive saving and reopening.
$editor[1]['sensors'][] = ['id' => 60001];
$editor[1]['open'] = 0;
$editor[1]['closed'] = 1;
$instance->properties['Rooms'] = json_encode($editor);
$canonical = privateCall($instance, 'rooms');
check($canonical[1]['sensors'] === [44502, 60001], 'Multiple sensor IDs were lost');
check($canonical[1]['open'] === false && $canonical[1]['closed'] === true, 'Reversed Boolean commands were lost');
check(editorRows($instance) === $editor, 'Saved popup rows changed on reopening');

$variables = [];
foreach ($instance->properties as $name => $value) {
    if (str_ends_with($name, 'ID')) $variables[$value] = ['VariableType' => 2, 'VariableUpdated' => time(), 'value' => 25.0];
}
foreach ($canonical as $room) {
    foreach ($room['sensors'] as $id) $variables[$id] = ['VariableType' => 2, 'VariableUpdated' => time(), 'value' => 18.0];
    $variables[$room['targetID']] = ['VariableType' => 2, 'VariableUpdated' => time(), 'value' => 21.0];
    $variables[$room['flapID']] = ['VariableType' => is_bool($room['open']) ? 0 : 1, 'value' => $room['closed']];
}
foreach (['MasterDisableID', 'NightDisableID', 'HolidayID', 'AtHomeID', 'FanOnID', 'HeatPumpOnID', 'HeatPumpHeatModeID', 'GasPumpID'] as $name) {
    $variables[$instance->properties[$name]] = ['VariableType' => 0, 'value' => false];
}
$variables[$instance->properties['WinterID']] = ['VariableType' => 0, 'value' => true];
foreach (['OperatingModeID', 'FanSpeedID', 'FanDesiredSpeedID', 'HeatPumpPowerID', 'HeatPumpDesiredPowerID', 'GasMixerID'] as $name) {
    $variables[$instance->properties[$name]] = ['VariableType' => 1, 'value' => 1];
}
$variables[$instance->properties['HysteresisID']]['value'] = 0.5;
$variables[$instance->properties['ResidualDeltaID']]['value'] = 2.0;
$GLOBALS['variables'] = $variables;
$instance->properties['Enabled'] = true;
$instance->ApplyChanges();
check(isset($instance->messages[60001], $instance->messages[44502], $instance->messages[40975]), 'Watchers must use sensor and target IDs');
check(!isset($instance->messages[1]), 'Nested sensor arrays must not be cast to ID 1');
privateCall($instance, 'validateConfiguration', $canonical);

// Same heating decisions and typed commands for canonical and popup configurations.
$instance->ProcessHeating();
$editorDecision = $instance->values['DecisionStatus'];
$editorActions = explode("\n", $instance->values['ActionLog']);
array_shift($editorActions); // Exclude log timestamp.
check(str_contains($editorDecision, 'Heating demand:'), 'Dry-run heating failed with popup configuration');
check(str_contains(implode("\n", $editorActions), '22150 (Variable 22150) => false'), 'Flap command must remain Boolean');
check(!$GLOBALS['commands'], 'Dry-run must not send actuator commands');
$instance->properties['Rooms'] = json_encode($canonical);
$instance->attributes['DemandLatch'] = '{}';
$instance->ProcessHeating();
$canonicalActions = explode("\n", $instance->values['ActionLog']);
array_shift($canonicalActions);
check($instance->values['DecisionStatus'] === $editorDecision && $canonicalActions === $editorActions, 'Heating decisions changed between formats');

$instance->properties['Rooms'] = json_encode($editor);
$backup = $instance->ExportConfig();
$exported = json_decode($backup, true, 512, JSON_THROW_ON_ERROR);
check(json_decode($exported['config']['Rooms'], true) === $canonical, 'Backup must use compatible canonical room schema');
$instance->properties['Rooms'] = '[]';
$instance->ImportConfig($backup);
check(!$instance->properties['Enabled'] && $instance->properties['DryRun'], 'Restore must leave controller disabled and in dry-run');
check(privateCall($instance, 'rooms') === $canonical, 'Backup restore changed settings');
$exported['config']['Rooms'] = json_encode($editor);
$instance->ImportConfig(json_encode($exported));
check(privateCall($instance, 'rooms') === $canonical, 'Restore must also accept popup-format backups');

$bad = $editor;
$bad[1]['open'] = 100;
rejects(fn() => privateCall($instance, 'decodeRoomRows', json_encode($bad)), 'Boolean command outside 0/1 must be rejected');
$bad[1]['open'] = 0;
$bad[1]['sensors'][0]['id'] = '44502';
rejects(fn() => privateCall($instance, 'decodeRoomRows', json_encode($bad)), 'Noninteger sensor ID must be rejected');
$bad = $canonical;
$bad[1]['name'] = $bad[0]['name'];
rejects(fn() => privateCall($instance, 'validateConfiguration', $bad), 'Duplicate names must not share a demand latch');
$instance->properties['Rooms'] = '{invalid';
$form = json_decode($instance->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$popup = array_values(array_filter($form['elements'], fn($element) => ($element['name'] ?? '') === 'RoomConfigurationPopup'))[0];
check($popup['popup']['items'][1]['type'] === 'ScriptEditor' && $instance->properties['Rooms'] === '{invalid', 'Malformed configuration must remain available for repair');
echo "PASS: room editor, legacy migration, sensor watchers, dry-run decisions, backup/restore and invalid input\n";
