<?php
declare(strict_types=1);
require __DIR__ . '/symcon-stub.php';

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
$GLOBALS['directWrites'] = [];
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
foreach ($canonical as $room) {
    $variables[$room['statusID']] = ['VariableType' => 0, 'VariableCustomAction' => 999, 'value' => false];
    $GLOBALS['categories'][$room['legacyGroupID']] = true;
}
$variables[57844] = ['VariableType' => 0, 'VariableCustomAction' => 999, 'value' => false];
$variables[52602] = ['VariableType' => 1, 'VariableCustomAction' => 999, 'value' => 25055];
$variables[21945] = ['VariableType' => 3, 'VariableCustomAction' => 999, 'value' => 'old log'];
$variables[29352] = ['VariableType' => 3, 'value' => 'old snapshot'];
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
check(!$GLOBALS['directWrites'], 'Dry-run must not write any legacy status or actuator variables');
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

// Old room schemas get known defaults only for the original Berlin wiring.
$oldSchema = $canonical;
foreach ($oldSchema as &$room) { unset($room['statusID'], $room['legacyGroupID']); }
unset($room);
check(privateCall($instance, 'decodeRoomRows', json_encode($oldSchema)) === $canonical, 'Existing room properties must acquire correct status defaults');
$oldSchema[0]['targetID'] = 60002;
$unmapped = privateCall($instance, 'decodeRoomRows', json_encode($oldSchema));
check($unmapped[0]['statusID'] === 0 && $unmapped[0]['legacyGroupID'] === 0, 'Different installations must not inherit Berlin room status IDs');

// Export remains read-only in dry-run and while disabled.
$instance->ExportConfig();
check(!$GLOBALS['directWrites'], 'Dry-run/disabled export must leave old snapshot untouched');
$instance->properties['Enabled'] = true;
$instance->properties['DryRun'] = false;
$instance->ApplyChanges();
foreach ($canonical as $room) check(!isset($instance->messages[$room['statusID']]), 'Status outputs must not trigger the controller');
$instance->attributes['DemandLatch'] = '{}';
$instance->ProcessHeating();
foreach ($canonical as $room) check(GetValue($room['statusID']) === true, 'Live heating must publish room demand');
check(GetValue(52602) === 36698, 'LastHeatedGroupID must use the original category, not the sensor, status ID or room index');
check(GetValue(57844) === false && str_starts_with(GetValue(21945), '<ul><li>'), 'Live statuses and HTML action log must update');
check(!array_filter($GLOBALS['commands'], fn($command) => in_array($command[0], [43898, 50623, 36744, 53400, 57844, 52602, 21945, 29352], true)), 'Status custom actions must never be executed');
$liveBackup = $instance->ExportConfig();
check(GetValue(29352) === $liveBackup, 'Live export must update the configured snapshot variable');

// Residual heat updates its Boolean while room demand becomes false; the last
// heated category remains remembered. Above-band temperatures create purge candidates.
foreach ($canonical as $room) foreach ($room['sensors'] as $id) $GLOBALS['variables'][$id]['value'] = 25.0;
$GLOBALS['variables'][$instance->properties['OutgoingAirTempID']]['value'] = 30.0;
$instance->ProcessHeating();
check(GetValue(57844) === true, 'Residual heat must be mirrored');
foreach ($canonical as $room) check(GetValue($room['statusID']) === false, 'Residual heat must not be reported as room heating demand');
check(GetValue(52602) === 36698, 'Residual heat must preserve last heated group');

// The external Boolean switch ends an active purge immediately via MessageSink,
// without disabling normal heating or its source-temperature fan control.
$instance->properties['RecoveryEnableID'] = 61003;
$GLOBALS['variables'][61003] = ['VariableType' => 0, 'value' => true];
$instance->ApplyChanges();
check(isset($instance->messages[61003]), 'Recovery switch must be monitored');
$GLOBALS['variables'][61003]['value'] = false;
$instance->MessageSink(1, 61003, VM_UPDATE, [false, true]);
check(!$instance->values['ResidualHeating'] && GetValue(57844) === false, 'Switching off must clear internal and legacy residual states');
foreach ($canonical as $room) check(GetValue($room['flapID']) === $room['closed'], 'Switching off recovery must close purge flaps');
check(GetValue($instance->properties['FanOnID']) === false && GetValue($instance->properties['FanSpeedID']) === 0, 'Switching off recovery must stop the purge fan');
check(str_contains($instance->values['DecisionStatus'], 'recovery disabled'), 'Disabled recovery must be visible in the decision');
$GLOBALS['variables'][61003]['value'] = true;
$instance->MessageSink(2, 61003, VM_UPDATE, [true, true]);
check($instance->values['ResidualHeating'] && GetValue(57844) === true, 'Switching on must resume eligible residual heat');
$instance->properties['DryRun'] = true;
$GLOBALS['commands'] = $GLOBALS['directWrites'] = [];
$GLOBALS['variables'][61003]['value'] = false;
$instance->MessageSink(3, 61003, VM_UPDATE, [false, true]);
check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'] && !$instance->values['ResidualHeating'], 'Switch must respect dry-run');
$instance->properties['DryRun'] = false;
foreach ($canonical as $room) foreach ($room['sensors'] as $id) $GLOBALS['variables'][$id]['value'] = 18.0;
$GLOBALS['variables'][$instance->properties['HeatPumpTempID']]['value'] = 40.0;
$GLOBALS['variables'][$instance->properties['IncomingAirTempID']]['value'] = 22.0;
$instance->ProcessHeating();
check(str_contains($instance->values['DecisionStatus'], 'Heating demand:') && GetValue($instance->properties['HeatPumpOnID']) === true, 'Recovery switch must not disable normal heating');
check(GetValue($instance->properties['FanOnID']) === true, 'Normal heating fan must remain active when its temperature condition is satisfied');
$GLOBALS['commands'] = $GLOBALS['directWrites'] = [];
$GLOBALS['variables'][61003]['VariableType'] = 1;
$instance->ProcessHeating();
check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'] && str_starts_with($instance->values['DecisionStatus'], 'ERROR:'), 'Non-Boolean switch must be rejected before commands');
$GLOBALS['variables'][61003]['VariableType'] = 0;
$instance->properties['RecoveryEnableID'] = $instance->properties['HeatPumpOnID'];
rejects(fn() => privateCall($instance, 'recoveryEnabled'), 'Recovery switch must not be an actuator command');
$instance->properties['RecoveryEnableID'] = $canonical[1]['flapID'];
rejects(fn() => privateCall($instance, 'validateConfiguration', $canonical), 'Recovery switch must not be a room flap command');
$instance->properties['RecoveryEnableID'] = 61003;
$switchConflict = $canonical;
$switchConflict[0]['statusID'] = 61003;
rejects(fn() => privateCall($instance, 'validateLegacyOutputs', $switchConflict), 'Status output must not overwrite the recovery switch');
$instance->properties['RecoveryEnableID'] = 1;
$instance->ApplyChanges();
check(privateCall($instance, 'recoveryEnabled') === true && !isset($instance->messages[61003]), 'Unselected switch must retain legacy behavior and remove old subscription');
$instance->properties['RecoveryEnableID'] = 61003;

// Night shutdown clears legacy demand/residual indicators. Summer and the
// master bypass leave every external variable untouched.
$GLOBALS['variables'][$instance->properties['NightDisableID']]['value'] = true;
foreach ($canonical as $room) $GLOBALS['variables'][$room['statusID']]['value'] = true;
$instance->ProcessHeating();
check(GetValue(57844) === false, 'Night shutdown must clear residual status');
foreach ($canonical as $room) check(GetValue($room['statusID']) === false, 'Night shutdown must clear demand status');
$GLOBALS['variables'][$instance->properties['WinterID']]['value'] = false;
$GLOBALS['commands'] = $GLOBALS['directWrites'] = [];
$instance->ProcessHeating();
check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Summer bypass must not write external variables');
$GLOBALS['variables'][$instance->properties['WinterID']]['value'] = true;
$GLOBALS['variables'][$instance->properties['MasterDisableID']]['value'] = true;
$instance->ProcessHeating();
check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Master bypass must not write external variables');
$GLOBALS['variables'][$instance->properties['MasterDisableID']]['value'] = false;
$GLOBALS['variables'][43898]['VariableType'] = 3;
$instance->ProcessHeating();
check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'] && str_starts_with($instance->values['DecisionStatus'], 'ERROR:'), 'Invalid status types must be rejected before any external writes');
$GLOBALS['variables'][43898]['VariableType'] = 0;
$conflicting = $canonical;
$conflicting[0]['statusID'] = $instance->properties['HeatPumpOnID'];
rejects(fn() => privateCall($instance, 'validateLegacyOutputs', $conflicting), 'Status outputs must not overwrite actuators');
$conflicting[0]['statusID'] = $canonical[1]['statusID'];
rejects(fn() => privateCall($instance, 'validateLegacyOutputs', $conflicting), 'Room status outputs must not share the same variable');
$instance->properties['Enabled'] = false;
$instance->ProcessHeating();
check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Disabled controller must not publish external statuses');
$unselected = $canonical;
foreach ($unselected as &$room) { $room['statusID'] = 1; $room['legacyGroupID'] = 1; }
unset($room);
foreach (['LegacyActionLogID', 'LegacyResidualHeatingID', 'LegacyLastHeatedGroupID', 'LegacyConfigSnapshotID'] as $property) $instance->properties[$property] = 1;
privateCall($instance, 'validateLegacyOutputs', privateCall($instance, 'decodeRoomRows', json_encode($unselected)));
$instance->properties['Enabled'] = true;
privateCall($instance, 'mirrorLegacyStatus', privateCall($instance, 'decodeRoomRows', json_encode($unselected)), [], false, null, 'Idle', []);
check(!$GLOBALS['directWrites'], 'Unselected status outputs must produce no writes');
$custom = $canonical;
$custom[0]['statusID'] = 61001;
$custom[0]['legacyGroupID'] = 61002;
$instance->properties['Rooms'] = json_encode($custom);
$instance->properties['LegacyActionLogID'] = 61000;
$instance->properties['Enabled'] = false;
$customBackup = $instance->ExportConfig();
$instance->properties['Rooms'] = json_encode($canonical);
$instance->properties['LegacyActionLogID'] = 21945;
$instance->properties['RecoveryEnableID'] = 0;
$instance->ImportConfig($customBackup);
check(privateCall($instance, 'rooms') === $custom && $instance->properties['LegacyActionLogID'] === 61000, 'Custom status mappings must survive backup/restore');
check($instance->properties['RecoveryEnableID'] === 61003, 'Recovery switch ID must survive backup/restore');
check(!$GLOBALS['directWrites'] && !$instance->properties['Enabled'] && $instance->properties['DryRun'], 'Restore must not publish status outputs');

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
echo "PASS: room editor, legacy migration, sensor watchers, recovery switch, dry-run decisions, backup/restore, status outputs and invalid input\n";

