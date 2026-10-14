# Measured heat-pump economics — 0.3.26, build 36

Update the Symcon library, apply the LichtBlick instance and Heating Control
(instance 11543 in the owner's installation). Keep the existing LichtBlick
instance selected. The measured matrix is the only automatic decision model; applying the update
populates the matrix without rerunning the October test. The controller's
existing automatic/manual switch still determines whether it chooses the source.
No test starts automatically. The passkey-protected page shows the matrix,
selected point, price comparison and start/cancel controls.

## Why the matrix changed

The previous provisional weather curve had one power/COP row per outside
temperature. It could not represent several powers at the same weather, intake
conditions or the different electrical meter. Its reference-price calculation
could veto a measured result before the measured power was considered.

The matrix now has multiple rows for **outside temperature × intake temperature
× commanded power**, retaining fan speed, estimated airflow, baseline, duration,
matched heat/electrical energy, temperature rise and stability. Targets are
runtime requirements, rather than an extra holiday/occupied performance axis.
The fixed 40°C exchanger requirement is retired; its saved properties remain
inert for backup compatibility. The existing fan start rule still waits until
the exchanger is warmer than intake by the controller's configured difference.

At runtime, the controller selects the highest estimated system COP among
stable nearby rows meeting supply >= highest room target + 5°C and air rise >=
5°C (both margins editable). Room demand/hysteresis still determines whether it
actually heats and which flaps open. Temperature lift is only locally corrected.
Default support is ±3°C on BOTH outside and intake temperatures; the tolerance
can be reduced. Requested fan speed and configured airflow must match.
Measurements outside this support, including a warm-house test applied to a
10°C holiday house, do not authorize a heat-pump choice: use gas until tested.
No invented weather extrapolation/interpolation is used with one weather run.
Multiple future weather/intake runs are retained up to 512 rows.

The control criterion does not measure building heat loss or prove that a power
can maintain every room on a cold day. Review individual room temperatures and
performance at different flap configurations; the calibration used all other
flaps open and the master bedroom closed. Actual installed airflow remains the
main uncertainty in heat output/COP, so thresholds are explicitly estimates.

## Economic calculation and integration

LichtBlick publishes `PriceQuoteJSON`: verified current electricity price,
current gas tariff, safety margin, hysteresis, enable state and expiry. This
snapshot is independent of the retired provisional weather curve. The controller
never bypasses price verification or expiry. The measured matrix is mandatory
for automatic operation; missing support uses gas. The old model switch, weather
curve and daily threshold learner have been retired.

```
System COP = estimated useful air heat energy / (house electricity − baseline energy)
Break-even electricity EUR/kWh = gas EUR/kWh × system COP / gas heating efficiency
Exit threshold = break-even × (1 − safety margin / 100)
Entry threshold = exit threshold − hysteresis EUR/kWh
```

Gas efficiency is editable in Heating Control. Its default **100% is a
conservative assumption, not a measured boiler efficiency**: it avoids claiming
extra heat-pump savings from unmeasured gas losses. At 90% efficiency the computed
threshold is higher. No gas fan/pump electricity saving is credited; this is a
conservative comparison of HP-plus-fan against gas fuel. Fixed tariff charges
are excluded because source switching does not avoid them. Actual gas-to-air
efficiency and matched gas auxiliary electricity would improve the comparison.

Safety margin is applied once. Enter HP strictly below entry; while HP is
selected, retain it below exit; equality at exit selects gas. This hysteresis
state persists independently of brief no-demand periods. Invalid quote or
unsupported conditions reset it to gas. Existing break-before-make and minimum
HP-off protections remain. These variables publish the actual measured decision:
`HeatPumpEconomicsJSON`, `HeatPumpBreakEvenEuro`, `HeatPumpEntryEuro`,
`HeatPumpExitEuro`, plus `SelectedHeatPumpPower` and source/status variables.
LichtBlick displays the connected controller’s measured comparison and mirrors
its current thresholds for compatible status references. Its heating tab links
to the registered Heating Control page using the same passkey vault.

## Reviewed 14 October calibration

Source: the owner’s original test report, readings and archive export.
Meter **38023**, scale **0.001
kWh/unit**. Baseline is the 15 minutes before test activation, with sources/fan off.
Each row uses exactly the
first 900 seconds of its step. Short archive counter boundaries are linearly
interpolated between bracketing readings no more than 90 seconds apart. Air-side
heat uses original ten-second telemetry held over those SAME 900 seconds.
**No lighting correction; HP submeter disregarded.**

| Power | Outside mean °C | Intake mean °C | Net system kW | Estimated COP | Break-even EUR/kWh* | Eligible |
|---:|---:|---:|---:|---:|---:|---|
| 30% | 12.12 | 22.62 | 1.477 | 2.866 | 0.3004 | Yes |
| 40% | 12.07 | 22.79 | 2.291 | 3.633 | 0.3808 | No: unsettled |
| 50% | 12.00 | 23.13 | 3.058 | 3.612 | 0.3786 | Yes |
| 60% | 11.92 | 23.43 | 3.653 | 3.412 | 0.3575 | Yes |
| 70% | 11.82 | 23.67 | 4.429 | 3.070 | 0.3218 | Yes |
| 80% | 11.69 | 23.88 | 5.280 | 2.687 | 0.2816 | Yes |
| 90% | 11.51 | 24.07 | 6.189 | 2.428 | 0.2544 | Yes |
| 100% | 11.42 | 24.27 | 6.326 | 2.406 | 0.2522 | Yes |

*Gas 0.1048 EUR/kWh, assumed 100% useful gas efficiency; BEFORE safety margin.
Airflow assumed 2,000 m³/h. At the currently best stable point (50%), margin
25% and hysteresis 0.02 EUR give **entry <0.2639; exit >=0.2839 EUR/kWh**.
These values track live gas price/settings and chosen power, not a fixed tariff.
The 30% interval includes the initial warm-up; later steps started with a warm
system. This sequence therefore does not establish a universal steady-state or
whole-season optimum. Longer matched normal-operation observations will refine it.
Changing other house loads, including lights, changes the measured net input.

Only the derived equipment-performance rows are published in
[data/heatpump-calibration.json](data/heatpump-calibration.json).
Household timestamps, counter totals, baseline consumption and room histories
are excluded from the repository. Full provenance remains in the owner exports.
Reproduce them without equipment access:

```bash
python3 libs/tools/recalculate-heatpump-house-test.py archive.jsonl.gz results.json readings.jsonl output.json
```

## Future tests

Verify Archive Control (14817), **house power 13869 W**, **house counter 38023**
and its 0.001 kWh/unit scaling. Missing logging is enabled without changing
aggregation/compaction. Existing temperature/flap mappings are respected.

Start from the form or protected page. Default 30–100% in 10% increments,
15 minutes per step, remains adjustable; **20 minutes per step is recommended
for the next run** because 40% did not settle in 15 minutes. Preparation shuts
both air sources/fan off, closes the mixer and observes existing transfer/off
delays. It then measures a clean **15-minute baseline with sources/fan off**.
All other flaps open and the master bedroom remains closed; fan speed stays
fixed (90% default). The test exclusively owns outputs until stopping/restoring.

Heat temperatures are integrated over each full step. Electrical energy is
counter energy over that same interval minus baseline power × interval duration.
The final third, capped at five minutes, checks stability: half-window changes
<=0.5°C air rise, <=1°C exchanger and <=10% house power, with adequate coverage.
After source stop/flap restoration, finalization waits 75 seconds for the last
counter boundary; gaps/resets prevent learning. Unsettled rows are stored in
reports but only valid stable new rows enter the accumulated matrix.
Cancellation/fault never replaces the operating matrix. A normal configured
temperature-limit stop can add previously completed stable points.

Temperature limits remain 28°C rooms / 55°C exchanger, editable. Temperature age
uses `MaxSensorAgeSeconds` (0 disables age stops); house power must update within
180 seconds. Source/fan/flap acknowledgement, timer interruptions, external
shutdown/cooling, disable/dry run and restart stop the experiment. It never
resumes automatically. Radiator/DHW and other house loads should be comparable
between baseline and test; this remains a whole-house subtraction, not isolated
compressor measurement. Full-cycle economics near freezing additionally needs
longer runs including any defrost, which a short steady test may miss.

| Outside target (aim ±1°C; record actual temperature) | Priority / purpose |
|---:|---|
| +12°C | Existing warm-intake calibration; optional 20-minute repeat for 40% |
| +7°C | Next: moderate weather |
| +2°C | High priority: colder weather and possible frosting |
| 0°C | High priority: near-freezing performance |
| −3°C | High priority: first subzero calibration |
| −7°C | Cold-weather capacity/economics |
| −12°C | Lower winter range |
| −15°C | Optional if encountered; colder extension |
| +15°C | Optional warm-weather extension |

At the same weather targets, add intake **9–12°C** measurements when the house
naturally reaches holiday conditions; retain warm intake **20–24°C** points too.
There is no need to cool the house just to test. Prioritize +7, +2, 0 and −3°C.
At +2/0/−3°C, supplement the sweep with a longer operating observation at the
promising power level (ideally a complete defrost cycle, if one occurs).

Sinclair publishes a heating outside range **−20 to +24°C** for ASGE-48BI(3)
with SCMI-01.4: https://www.sinclair.pl/oferta/agregaty-skraplajace/dc-inverter/asge-48bi3--scmi-014.html.
That is an operating limit, not the economic crossover. The economics matrix
may select gas at much warmer temperatures; never infer COP from the limit.

Reports/raw files persist under `<kernel>/media/heatpump-tests/<instance>/`.
Include them in backup/standby replication. Form backup retains settings/custom
matrix; runtime accumulated rows are in the separate model file/attributes.
APIs remain `HC_StartHeatPumpTest`, `HC_CancelHeatPumpTest`,
`HC_SetHeatPumpTestMinutes`, `HC_HeatPumpTestTick`.

Validation: `tests/heatpump-economics.php`, `tests/heatpump-test.php`, existing
source/room/recovery/archive/price/web security and DOM tests.

### Unavailable comparison diagnostics (0.3.28)

When no measured point qualifies, the protected pages state which requirement
failed and include the current and tested values: fan speed, assumed airflow,
outside temperature, intake temperature, excluded flap, or useful supply air.
Temperature failures state the configured matching tolerance. Stable test points
remain available in the matrix, but usability alone does not mean they match
current conditions. Fresh environmental readings remain visible on LichtBlick
without publishing a threshold or COP for unsupported conditions. Expired
readings are hidden. The controller retains its gas fallback; no room demand
still leaves the heat sources off.
