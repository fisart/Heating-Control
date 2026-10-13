# Controlled heat-pump test — Heating-Control 0.3.23

Start/cancel from the configuration form or the existing passkey-protected heating
page. The default sequence is **30, 40, 50, 60, 70, 80, 90, 100%**, with **15 minutes
per step**: two hours plus preparation. The final third of each step, up to five
minutes, is averaged. Timing is editable in the form and through **Apply time** on
the page; changes are refused during a running test.

## Configure and run

In **Heat-pump capacity test**, verify Archive Control (default 14817), HP-circuit
power (35702) and fan/heating-circuit power (47831), both in watts. Sensor/flap
mappings come from the current Heating Control configuration. The owner's live
outgoing-air mapping is 16146; an existing saved mapping is not overwritten with
the old default 40843. Missing logging is enabled for selected test inputs, without
changing aggregation/compaction or fabricating earlier records.

The configured master-bedroom flap (default 40259) stays **closed**. All other
configured room flaps open; fan speed stays fixed (default 90%). The test owns
the source, fan and flap decisions, so normal demand/recovery/price selection cannot
interrupt steps. It leaves stored room targets, manual desired settings, source
mode and the automatic-selection switch unchanged. Original flap positions are
restored after test heat is confirmed off. An external cooling/shutdown request
is preserved. Apply settings before starting from the form.

Preparation stops both air sources, closes the gas mixer, observes configured
transfer/minimum-off delays, and obtains a two-minute electrical baseline with
sources/fan off. The fan then remains at the test speed through ascending power
steps, without intentional compressor restarts. All device observations are
command-state acknowledgements, not independent physical feedback.

Default software stop settings are **28°C room temperature** and **55°C heat-pump
exchanger temperature**. These editable experiment cutoffs are not manufacturer
operating-limit certification: verify them for the equipment. Invalid/stale sensors,
power-meter failures, unconfirmed/fluctuating commands, gas air-pump activation,
external shutdown, controller disable/dry run, timer interruption or maximum
duration stop the test. Apply/restart never resumes it. If a source cannot be
confirmed stopped, the test retains control and retries rather than handing a
running experiment to normal heating. No blocking sleeps or automatic scheduling.

Radiator/DHW pumps are not operated by the test. Shared-circuit loads, including
humidifier/boiler activity, can contaminate power measurements; stable power alone
does not prove isolated compressor input. View/download raw readings to review it.

## Results and calculations

Raw samples approximately every ten seconds include outside/intake/outgoing/
exchanger temperatures, power, room temperatures/targets/flaps and input update
timestamps. Archive change-only history remains distinct. Each step stores averages,
air rise, exchanger lift, baseline-adjusted HP input, gross system input, estimated
heat output/COP and stability flags.

```
estimated heat kW = 0.000335 × airflow m³/h × (outgoing − incoming °C)
HP input kW = (HP circuit watts − off-baseline watts) / 1000
estimated system COP = estimated heat kW / gross (HP + fan circuit kW)
```

Airflow is editable, initially **2,000 m³/h** from the fan's nameplate. Installed
duct resistance, fan speed and the closed bedroom flap affect actual airflow;
COP is explicitly **estimated**. Confirm physical intake/outgoing sensor placement.
Neither percentage-to-watts proportionality nor weather-independent power is assumed.

Final-window halves must agree within **0.5°C air rise**, **1°C exchanger** and
**10% HP-circuit power**, with adequate coverage, gaps and plausible input/COP.
Unsettled points are saved/displayed but excluded from the operative model.
Adjacent usable points calculate changes per ten percentage points in input,
air rise, exchanger lift and estimated COP. At least two usable points are required to replace the model. A completed run
or a normal configured temperature-limit stop can supply its completed stable
steps; the interrupted step is excluded. Faulted/cancelled runs preserve the
preceding model, and an early finish never claims that all eight steps completed.

Files persist under `<kernel>/media/heatpump-tests/<instanceID>/`: per-run raw
`.jsonl` and `.summary.json`, plus the latest qualified `model.json`. Include the
directory in normal backup/standby replication. Child variables show test active,
status, latest results JSON, local model JSON and performance status. The page
shows a results table and offers authenticated result/raw downloads. These records
are separate from configuration backups.

## Comfort and holiday use

Normal heat-pump fan admission requires the occupied exchanger minimum, initially
**40°C**, as well as the existing source/intake difference. Existing heating
hysteresis provides a small fan-retention band. Test airflow bypasses normal fan
admission because the experiment requires constant airflow.

Holiday exchanger minimum is **max(20°C floor, highest configured room target +
10°C)** by default. With all room targets at **10°C**, **20°C** exchanger operation
is permitted. Floor and offset are editable. This reads room targets, not rewrites
them. Model selection also requires outgoing air at least **5°C above the highest
room target** and at least **5°C above intake**; both margins are editable.

Automatic operation can select a stable test point only within **±3°C outside and
±3°C intake** of its measurement, at the same requested fan speed and room mapping,
with a model less than 90 days old. Temperature lifts provide only this small
local correction. No weather/COP extrapolation: a warm occupied-house test does
not validate a 10°C holiday house. Repeat under those conditions. No local point
satisfying the requirements means gas fallback rather than an unproved heat output.

A point demanding higher power than LichtBlick requested triggers a conservative
price recheck: empirical reference threshold, current/reference gas price and
estimated-system/reference fan-adjusted COP; safety margin applied once, stricter
provider ceiling retained, hysteresis retained. Missing/invalid inputs or an
uneconomic increase use gas. The saved LichtBlick weather/COP table is not silently
rewritten. Threshold and COP retain model/airflow uncertainty. Manual settings
remain user-controlled.

APIs: `HC_StartHeatPumpTest(instanceID, minutes=0)`,
`HC_CancelHeatPumpTest(instanceID)`, `HC_SetHeatPumpTestMinutes(instanceID, minutes)`.
Timer: `HC_HeatPumpTestTick(instanceID)`. Downloads:
`?view=heatpump-test-results` and `?view=heatpump-test-readings`.

Validation: `php tests/heatpump-test.php` exercises estimates, stability, local
scope, all eight steps, excluded flap, logging, persistent results, cancellation,
disable/restart/temperature/timer stops and protected commands/downloads. Existing
room/source/recovery/archive/web tests remain in the workflow.
