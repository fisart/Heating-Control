<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function recoveryFixture(): HeatingControl {
    $m = heatingFixture();
    $m->properties['RecoveryRunOnSeconds'] = 0; // Exercise the original hysteresis independently.
    $GLOBALS['variables'][$m->properties['HysteresisID']]['value'] = 0.5;
    foreach (json_decode($m->properties['Rooms'], true) as $room) {
        foreach ($room['sensors'] as $id) $GLOBALS['variables'][$id]['value'] = 24.0;
    }
    return $m;
}
function outgoing(HeatingControl $m, float $value): void {
    $GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value'] = $value;
    $m->ProcessHeating();
    check(!str_starts_with($m->values['DecisionStatus'], 'ERROR:'), 'Evaluation must succeed');
}
function active(HeatingControl $m): bool { return $m->values['ResidualHeating']; }
$m = recoveryFixture(); // Target 23, delta 2: activation >25.5, deactivation <=24.5.
outgoing($m, 25.2); check(!active($m), 'Cold-start inside band stays off');
outgoing($m, 25.5); check(!active($m), 'Upper delta boundary retains strict start condition');
outgoing($m, 25.6); check(active($m), 'Above upper threshold starts recovery');
foreach ([25.1, 24.8, 25.2, 24.6] as $value) {
    outgoing($m, $value);
    check(active($m) && GetValue($m->properties['FanOnID']), 'Small fluctuations cannot stop the recovery fan');
    check(GetValue(27527) === 0, 'Recovering room flap stays open');
}
outgoing($m, 24.5); check(!active($m) && !GetValue($m->properties['FanOnID']), 'Lower threshold stops recovery and fan');
outgoing($m, 25.2); check(!active($m), 'Returning inside band cannot restart recovery');
outgoing($m, 25.6); check(active($m), 'Exceeding upper threshold restarts recovery');
check(GetValue(57844), 'Legacy recovery status follows hysteresis');
check(!GetValue($m->properties['HeatPumpOnID']) && !GetValue($m->properties['GasPumpID']), 'Recovery never enables a heat source');

// The second eligibility threshold (minimum outgoing air) uses the same band.
$m = recoveryFixture(); $m->properties['ResidualMinOutgoingTemp'] = 30.0;
outgoing($m, 30.4); check(!active($m), 'Below upper minimum stays off');
outgoing($m, 30.5); check(active($m), 'Inclusive minimum boundary starts recovery');
outgoing($m, 29.6); check(active($m), 'Minimum threshold retains recovery inside band');
outgoing($m, 29.4); check(!active($m), 'Below lower minimum stops recovery');

// Multiple rooms cut off together and remain recovering on subsequent evaluations.
$m = recoveryFixture();
$m->attributes['DemandLatch'] = json_encode(['Living and Dining Room'=>true, 'Blue Room and Kitchen'=>true]);
outgoing($m, 26.0);
$latch = json_decode($m->attributes['RecoveryLatch'], true);
check($latch['Living and Dining Room'] && $latch['Blue Room and Kitchen'], 'Each cutoff room gets its own recovery latch');
outgoing($m, 25.0);
$latch = json_decode($m->attributes['RecoveryLatch'], true);
check($latch['Living and Dining Room'] && $latch['Blue Room and Kitchen'], 'Both recovering rooms remain eligible after cutoff');
check(GetValue(36911) === 0 && GetValue(27527) === 0, 'Both recovering flaps remain open');
$m->ApplyChanges(); outgoing($m, 25.0);
check(json_decode($m->attributes['RecoveryLatch'], true)['Living and Dining Room'], 'Enabled ApplyChanges preserves recovery state');

// Manual off and all shutdown paths must clear the hysteresis memory.
$m = recoveryFixture(); outgoing($m, 26.0);
$GLOBALS['variables'][61003]['value'] = false; outgoing($m, 25.0);
check(!active($m), 'Manual recovery off overrides the lower threshold immediately');
$GLOBALS['variables'][61003]['value'] = true; outgoing($m, 25.0);
check(!active($m), 'Manual re-enable inside band requires a fresh upper-threshold crossing');
foreach (['NightDisableID', 'MasterDisableID', 'WinterID', 'OperatingModeID'] as $key) {
    $m = recoveryFixture(); outgoing($m, 26.0);
    $GLOBALS['variables'][$m->properties[$key]]['value'] = ['NightDisableID'=>true, 'MasterDisableID'=>true, 'WinterID'=>false, 'OperatingModeID'=>0][$key];
    $GLOBALS['commands'] = $GLOBALS['directWrites'] = [];
    $m->ProcessHeating();
    check($m->attributes['RecoveryLatch'] === '{}', 'Shutdown clears recovery memory: '.$key);
    if (in_array($key, ['MasterDisableID', 'WinterID'], true)) check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Bypass sends no external commands');
    $GLOBALS['variables'][$m->properties[$key]]['value'] = ['NightDisableID'=>false, 'MasterDisableID'=>false, 'WinterID'=>true, 'OperatingModeID'=>2][$key];
    outgoing($m, 25.0); check(!active($m), 'Resume inside band starts off: '.$key);
}
$m = recoveryFixture(); outgoing($m, 26.0);
$m->properties['Enabled'] = false; $m->ApplyChanges();
check($m->attributes['RecoveryLatch'] === '{}', 'Disabled controller clears recovery memory');
$m->properties['Enabled'] = true; $m->ApplyChanges(); outgoing($m, 25.0);
check(!active($m), 'Re-enabled controller starts off inside band');

// Normal heating takes precedence and starts a fresh recovery cycle afterward.
$m = recoveryFixture(); outgoing($m, 26.0);
$GLOBALS['variables'][57255]['value'] = 22.0;
$m->ProcessHeating();
check(str_starts_with($m->values['DecisionStatus'], 'Heating demand:') && $m->attributes['RecoveryLatch'] === '{}', 'Normal room heating clears recovery memory');
$GLOBALS['variables'][57255]['value'] = 24.0; outgoing($m, 25.0);
check(!active($m), 'Heating cutoff inside recovery band does not inherit old recovery state');

// Changes to the existing shared variable take effect without a second setting.
$m = recoveryFixture(); outgoing($m, 26.0); outgoing($m, 24.8);
check(active($m), 'Recovery initially holds with shared hysteresis 0.5');
$GLOBALS['variables'][$m->properties['HysteresisID']]['value'] = 0.1;
$m->MessageSink(time(), $m->properties['HysteresisID'], VM_UPDATE, [0.1, true]);
check(!active($m), 'Updating shared heating hysteresis immediately changes the recovery band');
$m = recoveryFixture(); outgoing($m, 26.0);
$backup = $m->ExportConfig(); $m->ImportConfig($backup);
check($m->attributes['RecoveryLatch'] === '{}' && !$m->properties['Enabled'], 'Restore clears runtime recovery memory and remains disabled');
$m = recoveryFixture(); $m->attributes['RecoveryLatch'] = 'invalid'; outgoing($m, 25.0);
check(!active($m), 'Unreadable recovery memory initializes off inside band');

// Zero hysteresis retains the former switching conditions. Dry run still never writes devices.
$m = recoveryFixture(); $GLOBALS['variables'][$m->properties['HysteresisID']]['value'] = 0.0;
outgoing($m, 25.01); check(active($m), 'Zero hysteresis starts above original delta');
outgoing($m, 25.0); check(!active($m), 'Zero hysteresis stops at original delta');
$m = recoveryFixture(); $m->properties['DryRun'] = true;
$GLOBALS['commands'] = $GLOBALS['directWrites'] = [];
outgoing($m, 26.0); outgoing($m, 25.0);
check(active($m) && !$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Dry-run decisions retain hysteresis without external writes');
$m = recoveryFixture(); $m->properties['DebugEnabled'] = true;
outgoing($m, 26.0);
check(str_contains($m->values['DebugLog'], 'hysteresis=0.50') && str_contains($m->values['DebugLog'], 'previous=0 active=1'), 'Debug shows shared hysteresis and recovery transition');

// Timed recuperation: use a deterministic clock, with a regular evaluation
// interval longer than the delay, and inspect the dedicated timer requests.
class TimedRecovery extends HeatingControl {
    public int $clock = 2000000000;
    public array $timers = [];
    protected function sourceClock(): int { return $this->clock; }
    public function SetTimerInterval(...$args) { $this->timers[$args[0]] = $args[1]; }
}
function timedRecovery(): TimedRecovery {
    $m = heatingFixture(TimedRecovery::class);
    $m->properties['IntervalSeconds'] = 300;
    $GLOBALS['variables'][$m->properties['HysteresisID']]['value'] = 0.5;
    foreach (json_decode($m->properties['Rooms'], true) as $room) {
        foreach ($room['sensors'] as $id) $GLOBALS['variables'][$id]['value'] = 24.0;
    }
    return $m;
}
$m = timedRecovery();
check($m->properties['RecoveryRunOnSeconds'] === 60, 'New and upgraded instances default to a one-minute run-on');
outgoing($m, 26.0); outgoing($m, 24.5);
check(active($m) && GetValue($m->properties['FanOnID']) && GetValue(27527) === 0, 'Stop threshold retains fan and recovering flap during run-on');
check($m->timers['RecoveryRunOnTimer'] === 60000, 'Dedicated timer schedules exact default deadline independently of five-minute polling');
check(str_contains($m->values['DecisionStatus'], 'fan run-on:'), 'Decision status exposes pending run-on');
$m->clock += 20; outgoing($m, 24.4);
check($m->timers['RecoveryRunOnTimer'] === 40000, 'Repeated low readings count down without extending the deadline');
$m->ApplyChanges(); outgoing($m, 24.4);
check($m->timers['RecoveryRunOnTimer'] === 40000, 'Enabled ApplyChanges retains the original pending deadline');
$m->clock += 39; outgoing($m, 24.3);
check(active($m) && $m->timers['RecoveryRunOnTimer'] === 1000, 'Fan remains active until the last second');
$m->clock++; outgoing($m, 24.3);
check(!active($m) && !GetValue($m->properties['FanOnID']) && GetValue(27527) === 100, 'At expiry fan stops and room flap closes');
check($m->timers['RecoveryRunOnTimer'] === 0 && $m->attributes['RecoveryBelowSince'] === '{}', 'Completed deadline leaves no active timer or countdown');
check(!GetValue(57844) && !$m->values['ResidualHeating'], 'Internal and legacy recovery flags follow expiry');
outgoing($m, 25.0); check(!active($m), 'Post-expiry restart still needs the upper hysteresis threshold');
check(!GetValue($m->properties['HeatPumpOnID']) && !GetValue($m->properties['GasPumpID']), 'Run-on never enables either heat source');

$m = timedRecovery(); outgoing($m, 26.0); outgoing($m, 24.5);
$m->clock += 30; outgoing($m, 24.8);
check(active($m) && $m->attributes['RecoveryBelowSince'] === '{}' && $m->timers['RecoveryRunOnTimer'] === 0, 'Recovery above the lower threshold cancels the countdown');
$m->clock += 50; outgoing($m, 24.5);
check($m->timers['RecoveryRunOnTimer'] === 60000, 'A later dip starts a fresh full delay');
$m->clock += 59; outgoing($m, 24.5); check(active($m), 'Fresh delay survives the former expired deadline');
$m->clock++; outgoing($m, 24.5); check(!active($m), 'Fresh delay ends after its own 60 seconds');

$m = timedRecovery(); $m->properties['RecoveryRunOnSeconds'] = 15;
outgoing($m, 26.0); outgoing($m, 24.5);
check($m->timers['RecoveryRunOnTimer'] === 15000, 'Custom delay is applied');
$m->clock += 15; outgoing($m, 24.5); check(!active($m), 'Custom delay expires');
$m = timedRecovery(); outgoing($m, 26.0); outgoing($m, 24.5);
$m->properties['RecoveryRunOnSeconds'] = 0; outgoing($m, 24.5);
check(!active($m) && $m->timers['RecoveryRunOnTimer'] === 0, 'Setting zero bypasses an already pending run-on');

// Cold air cancels the delay at the measured room average, not the setpoint.
$m = timedRecovery(); outgoing($m, 26.0); outgoing($m, 24.5);
outgoing($m, 24.0);
check(!active($m) && !GetValue($m->properties['FanOnID']) && $m->timers['RecoveryRunOnTimer'] === 0, 'Air at room temperature ends run-on immediately');
$m = timedRecovery(); outgoing($m, 26.0); outgoing($m, 10.0);
check(!active($m), 'A cold collapse never receives a one-minute fan override');

// Each room keeps its own route and deadline; the shared fan runs until the
// last eligible room finishes. Blue target is 23; living target is raised to 24.
$m = timedRecovery(); $rooms = json_decode($m->properties['Rooms'], true);
foreach ($rooms as $room) if ($room['name'] === 'Living and Dining Room') {
    $GLOBALS['variables'][$room['targetID']]['value'] = 24.0;
    foreach ($room['sensors'] as $id) $GLOBALS['variables'][$id]['value'] = 25.0;
}
$m->attributes['DemandLatch'] = json_encode(['Living and Dining Room'=>true, 'Blue Room and Kitchen'=>true]);
outgoing($m, 28.0); outgoing($m, 25.4);
$m->clock += 30; outgoing($m, 24.5); // Living is colder than its room and stops immediately.
check(GetValue(36911) === 100 && GetValue(27527) === 0 && active($m), 'Cold-air stop closes only the affected room while another runs on');
check($m->timers['RecoveryRunOnTimer'] === 60000, 'Remaining room has its own new deadline');
$m->clock += 60; outgoing($m, 24.5); check(!active($m), 'Shared fan stops after the final room expires');

// All existing override paths clear a pending delay, without changing bypass semantics.
foreach (['RecoveryEnableID', 'NightDisableID', 'MasterDisableID', 'WinterID', 'OperatingModeID'] as $key) {
    $m = timedRecovery(); outgoing($m, 26.0); outgoing($m, 24.5);
    $GLOBALS['variables'][$m->properties[$key]]['value'] = ['RecoveryEnableID'=>false, 'NightDisableID'=>true, 'MasterDisableID'=>true, 'WinterID'=>false, 'OperatingModeID'=>0][$key];
    $GLOBALS['commands'] = $GLOBALS['directWrites'] = [];
    $m->ProcessHeating();
    check($m->attributes['RecoveryBelowSince'] === '{}' && $m->timers['RecoveryRunOnTimer'] === 0, 'Override clears pending timer: '.$key);
    if (in_array($key, ['MasterDisableID','WinterID'], true)) check(!$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Timed bypass still sends no external commands');
    else check(!GetValue($m->properties['FanOnID']), 'Shutdown or manual off stops the running fan: '.$key);
}
$m = timedRecovery(); outgoing($m, 26.0); outgoing($m, 24.5);
$GLOBALS['variables'][57255]['value'] = 22.0; $m->ProcessHeating();
check($m->attributes['RecoveryBelowSince'] === '{}' && $m->timers['RecoveryRunOnTimer'] === 0 && str_starts_with($m->values['DecisionStatus'], 'Heating demand:'), 'New heating demand takes over and clears pending run-on');
$m = timedRecovery(); outgoing($m, 26.0); outgoing($m, 24.5);
$m->properties['Enabled'] = false; $m->ApplyChanges();
check($m->attributes['RecoveryBelowSince'] === '{}' && $m->timers['RecoveryRunOnTimer'] === 0, 'Disabling clears pending countdown');
$m = timedRecovery(); $m->properties['RecoveryRunOnSeconds'] = 90;
outgoing($m, 26.0); outgoing($m, 24.5); $backup = $m->ExportConfig(); $m->ImportConfig($backup);
check($m->properties['RecoveryRunOnSeconds'] === 90 && $m->attributes['RecoveryBelowSince'] === '{}' && !$m->properties['Enabled'], 'Backup preserves configured duration but never restores a runtime countdown');
foreach ([-1, 601, 1.5, '60', null] as $bad) {
    $m = timedRecovery(); $backup = json_decode($m->ExportConfig(), true); $backup['config']['RecoveryRunOnSeconds'] = $bad;
    try { $m->ImportConfig(json_encode($backup)); check(false, 'Invalid duration must be rejected'); }
    catch (InvalidArgumentException $e) { check($m->properties['RecoveryRunOnSeconds'] === 60, 'Invalid restore is rejected before mutation'); }
}
$m = timedRecovery(); $m->properties['RecoveryRunOnSeconds'] = -1;
$GLOBALS['commands'] = $GLOBALS['directWrites'] = []; $m->ProcessHeating();
check(str_starts_with($m->values['DecisionStatus'], 'ERROR:') && !$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Invalid live duration sends no actuator commands');
$m = timedRecovery(); $m->properties['DryRun'] = true;
$GLOBALS['commands'] = $GLOBALS['directWrites'] = []; outgoing($m, 26.0); outgoing($m, 24.5);
check(active($m) && $m->timers['RecoveryRunOnTimer'] === 60000 && !$GLOBALS['commands'] && !$GLOBALS['directWrites'], 'Dry-run simulates timer without actuator writes');
$m->clock += 60; outgoing($m, 24.5); check(!active($m), 'Dry-run expires at the same deadline');
$view = webInvoke($m, 'webState');
check($view['recoveryRunOnSeconds'] === 60, 'Protected page exposes the configured run-on');
echo "Recovery hysteresis and run-on: $checks assertions passed.\n";
