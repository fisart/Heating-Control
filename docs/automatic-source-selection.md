# Automatic gas / heat-pump switchover — 0.3.26

Heating Control is the sole source/power decision maker. LichtBlick supplies a
verified `PriceQuoteJSON` containing electricity price, gas price, safety margin,
price hysteresis, enable state and expiry. Provisional weather/reference-price
calculations and daily threshold learning are retired.

## Setup and controls

1. In LichtBlick, open **Electricity tariff and price retrieval**; configure
   postcode/correction, confirm the actual tariff and enable price supply.
2. In **Gas / heat-pump switching settings**, configure gas price in ct/kWh,
   safety margin (%) and price hysteresis (ct/kWh). Default margin is 25%.
3. In Heating Control, open **Automatic gas / heat-pump switchover** and select
   the LichtBlick instance. Its current ON/OFF state is shown in the form.
4. Enable/disable automatic operation through the form’s buttons, the child
   `AutomaticSourceSelection` Boolean, or the protected page’s switch.
5. Review the measured matrix, gas efficiency and supply margins in Heating
   Control. Hardware mappings, operating permissions and transfer timers remain
   separate and keep their existing saved values.

`HC_SetAutomaticSourceSelection(instanceID, true/false)` and
`RequestAction(switchID, true/false)` remain valid. Automatic off retains the last
selected source and uses manual desired power. Master/summer/night/cooling gates,
room demand, recovery, dry run and controller enable still apply. Restore never
enables hardware control or web access automatically.

## Decision and validity

The controller checks provider identity, schema, verification, expiry and issue
age (maximum 180 seconds). It chooses the highest estimated COP among stable
local points meeting the current fan/airflow and active room-target requirements.
Gas price × system COP / gas efficiency gives break-even electricity price.
Safety margin is applied once; price hysteresis supplies entry/exit separation.
Missing/unsupported measurements or invalid/expired prices use gas. There is no
alternative provisional model to select. See [calibration and temperature tests](heatpump-capacity-test.md).

The Heating Control page separates actual selected source/power from price supply
and measured thresholds. LichtBlick’s heating tab shows the same measured
comparison, the current automatic/manual status and selected air source. Cross-page
links appear only for a registered, enabled target using the same passkey vault.
Multiple controllers referring to one provider are reported as ambiguous rather
than choosing one silently. Reading either page does not operate hardware.

## Exact Berlin command mapping

All IDs and open/closed positions are configurable. Verify these defaults before
live use. This integration controls the air-heating branch; it never switches off
the entire gas boiler or directly commands radiator/DHW services.

| Operation | Commands, in order after transfer readiness |
|---|---|
| Activate heat pump | Gas-air pump **32875=false**; gas mixer **14348=100** (closed); heat/cool **51011=true** (heating); physical percentage **19207=selected measured percentage**; heat-pump enable **35931=true** |
| Activate gas-air heating | Heat-pump enable **35931=false**; percentage **19207=0**; mixer **14348=0** (open); gas-air pump **32875=true**; flow command **14533=value from 46820** |
| Stop sources for transfer | **35931=false**, **19207=0**, **14348=100**, **32875=false**; fan remains governed by selected exchanger temperature and room demand |

From **0.3.43 build 54**, the module adds **no timed source-transfer pause** and **no heat-pump minimum-off delay**. After stopping the previous source, it permits the replacement source as soon as both source commands read OFF and the mixer command reads CLOSED. A pending command is rechecked promptly; there is no elapsed-time requirement. Configuration apply, re-enable, a brief demand interruption or completion of flap calibration does not create a protection countdown.

**SourceTransferDelaySeconds** and **HeatPumpMinimumOffSeconds** remain registered only for configuration/export compatibility, with factory values 0. They are hidden from the form and ignored everywhere, including old saved nonzero values and heat-pump capacity-test preparation. Importing an old backup cannot restore either wait.

These are **command-state acknowledgments**, not compressor-running or actual valve-position feedback. If a stop/closed command does not update, the replacement source does not start. Equipment defrost and internal limits remain equipment-controlled.

## Fan control independent of source transfer

From **0.3.41 build 52**, the fan uses the **selected heat-source exchanger temperature**, compared with the highest current target of the rooms requesting heat:

| Selected source | Fan reference sensor |
| --- | --- |
| Heat pump | **HeatPumpTempID** |
| Gas air heating | **HeatExchangerTempID** |

    fan on  when selected exchanger >= highest target of the rooms requesting heat
    fan off when selected exchanger <  highest target of the rooms requesting heat

Equality keeps the fan on. A closed room with no demand does not raise this threshold. The current selected source determines the reference sensor while awaiting source-command acknowledgement as well as during normal heating. Source transfer does not issue a fan stop; the applicable exchanger temperature determines the fan decision. Fan speed remains the desired value from **48646**; demand keeps the receiving-room flaps open.

Outgoing air is not the fan reference: without airflow that sensor can remain cold while the exchanger is already hot. The selected exchanger can therefore start the fan from rest. There is no extra temperature margin or intake-rise gate for this fan rule. The configured supply margin still qualifies economic heat-pump capacity. Debug records the selected exchanger sensor, measured temperature, applicable current room target and fan decision.

The heat sources retain OFF/CLOSED command acknowledgement and break-before-make, without a mixer travel timer or compressor off-time timer. No-demand residual recovery/run-on, explicit shutdown, master/summer bypass and supervised calibration keep their existing controls.

The supplied 23 October log switches from HP selection to gas fallback at 10:30:02 when a quote expires, then back to HP at 10:30:42 after the next quote. This release lets warm-air delivery continue through those source waits. It does not extend an expired price quote or change economic source-selection rules.

The gas flow target may be shared with other boiler services and still needs installation verification.

## Outputs and validation

HeatingControl publishes **EffectiveSource** (0 off, 1 heat pump, 2 gas),
**SelectedHeatPumpPower**, **SourceSelectionStatus** and **SourceTransferStatus**.
**LearningSourceMode** retains the selected heating mode (1/2) through normal
no-demand periods and is zero for cooling/shutdown/bypass/disabled control. If
using the daily learner with automatic selection, enable Archive logging on this
child and set LichtBlick **SourceModeID** to its ID. Do not leave it pointing at
**41085**: it now mirrors the intended source, but lacks the learner's shutdown/bypass state. The learner also rejects more
than two minutes of opposite air-source activity, guarding incorrectly mapped or
mixed-source histories. Initial daily learning remains disabled until configured.

A selected source is an intended choice, not evidence that the equipment is
physically running; inspect device command variables and measured response too.

Fixture tests check default manual control, snapshot validation/fallback, stop
acknowledgment, delayed starts, command order, restarts, demand interruption,
shutdown priority, source-selector mirroring (including configurable mode values),
DryRun, legacy-action bypass, unchanged-value suppression, handback, configuration restore and authenticated web controls.
Existing heating/recovery, archive/security and DOM checks also run. No live
heating equipment was operated during implementation.

## Module enable and air-flap calibration

From **0.3.42 build 53**, the protected HTML page has an **Enable/Disable heating module** button. It changes the existing **Enabled** setting. Disable releases all linked inputs/outputs and legacy status variables: no timer, event, normal heating, active calibration, heat-pump test or page setting can write to them. Current device states are retained; disabling is not a shutdown command. Internal module status/checkpoints may still update. The page stays accessible so the module can be enabled again. Dry run remains a separate existing setting.

**Calibrate air flaps** runs a nonblocking service sequence for **every configured room flap**, including the master-bedroom flap excluded from heat-pump capacity tests:

| Stage | Action | Wait |
| --- | --- | --- |
| Preparation | Turn both heat sources and fan off; close mixer; wait for command-state acknowledgement | Until OFF acknowledged |
| 1 | Command every flap OPEN, including commands whose stored value already matches | FlapCalibrationWaitSeconds |
| 2 | Command every flap CLOSED | FlapCalibrationWaitSeconds |
| 3 | Command every flap OPEN | FlapCalibrationWaitSeconds |
| Complete | Return control to normal heating rules | OFF/CLOSED command acknowledgement only; no timed delay |

**Wait at each position (seconds)** is editable on the page and in the instance form, default **120 seconds**, range **1–3600**. With defaults the three dwell stages take **six minutes**, plus any initial stop-acknowledgement delay. The setting is retained in configuration backup/export; it cannot change mid-run. A delayed timer never skips the required wait at a position. The page shows active stage, open/closed command, remaining seconds and waiting/error status. These are command states, not independent physical end-stop feedback.

During calibration normal heating and other page controls are suspended. Both heat sources and fan stay off; loss of OFF acknowledgement pauses the sequence and restarts the current dwell when recovered. A heat-pump capacity test cannot overlap. **Cancel calibration** returns control to normal heating. **Disable heating module** interrupts calibration immediately and leaves all current device values untouched. Master bypass or a change to dry run interrupts without further output commands. A configuration apply or restart interrupts a flap sequence; it never silently resumes an uncertain position. Output faults retain exclusive calibration ownership and a visible error until cancel/disable.

Disabling during a heat-pump capacity test retains raw readings and an interrupted-state checkpoint, ends test ownership and sends no stop/restore commands. Use normal test Cancel while enabled when a controlled test shutdown is wanted. Re-enable does not resume interrupted tests. APIs: **HC_SetModuleEnabled**, **HC_StartFlapCalibration**, **HC_CancelFlapCalibration**, **HC_SetFlapCalibrationWaitSeconds**.
