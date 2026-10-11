# Automatic selection of heat pump or gas — proposed design

Prepared 11 October 2026. Revised to include domestic hot water, the separate radiator circuit, holiday protection and the supplemental January–February 2025 radiator/eBUS validation. Status: controller/source-transfer proposal for agreement. Version 0.3.0 implements a separate price-based recommendation and initial holiday-day threshold learner; no live heating changes have been made.

## Recommendation

Use three principal IP-Symcon modules: a reusable electricity-price importer, an air-heating source selector, and an enhanced HeatingControl coordinating the building’s heat services. Keep radiator-pump, air-circuit and shared-boiler coordination as separate internal components. Local AVM/Shelly thermostats continue controlling their own radiator valves; HeatingControl owns shared pump and boiler/air-path requests after a controlled handover from existing scripts. Include data collection and model evaluation inside the selector initially, rather than introducing a fourth economic controller.

The selector requests a heat source and a heat-pump capacity command **for the air-heating circuit only**. HeatingControl evaluates the independent air, radiator and domestic-hot-water services, coordinates their shared boiler needs, and decides whether the air request can be applied. Selecting the heat pump for air heating never means disabling the gas boiler as a whole.

## First implementation: price recommendation

[LichtBlickElectricityPrice](lichtblick-electricity-price.md), added in library 0.3.0,
combines the reusable price feed with a provisional threshold recommendation and
an opt-in daily learner. Its **SafetyMarginPercent** is editable in the instance
configuration form, defaults to 25%, and is included in configuration backups.
Daily accepted updates change the original threshold, never the chosen margin.
Learning uses matched historical consumption, stable holiday targets and fixed
capacity; unsuitable days retain the threshold. Recommendations are not wired
to HeatingControl. Actual source transfer, capacity selection, comfort/moisture
protection and coordination of radiator/DHW loads remain to be implemented
under this design. The price module's hysteresis is only an economic band;
equipment minimum run/off times belong in the future controller.

## Existing implementation reviewed

Repository: https://github.com/fisart/Heating-Control

The original controller review used library version 0.2.4, build 9; the price-module addition raises the library to 0.3.0, build 10 without changing that controller. The reviewed `HeatingControl/module.php` blob SHA is `0d990b2922f4edcb28bee34e6976f31c58b594a4`.

- Existing source selection is `OperatingModeID` (Berlin object 41085): 0 cooling, 1 heat pump, 2 gas.
- Existing manual desired capacity is `HeatPumpDesiredPowerID` (59800). The physical capacity command is `HeatPumpPowerID` (19207).
- HeatingControl already evaluates room demand and heating hysteresis, drives heat-source and airflow outputs, and performs residual heat recovery.
- In the current code, winter=true permits heating. Master-disable=true or winter=false bypasses without changing external actuators. Cooling and applicable night-disable shut heating down. Automatic selection must respect these existing behaviors.
- Current source changes are sequential output commands in one evaluation. They do not wait for confirmed equipment stop or mixer travel. A controlled transfer state machine is part of the proposed enhancement.

## Building services and operating profiles

Artur’s gas boiler serves three loads: domestic hot water with built-in priority, a radiator circuit serving the basement/bathrooms with AVM/Shelly local thermostats and a controllable circuit pump, and the gas heat exchanger for duct air heating. Only the air-heating load can transfer to the heat pump.

| Service | Heat source | Home profile | Holiday profile |
|---|---|---|---|
| Domestic hot water | Gas boiler | Configured normal hot-water operation; boiler’s built-in priority remains authoritative | **Hot-water heating OFF**, as expressly specified by Artur |
| Basement/bathroom radiators | Gas boiler | Each thermostat’s configured comfort schedule; pump follows circuit demand | Local protection targets, normally based on 10°C with room-specific overrides; pump available independently |
| Duct air heating | Gas or heat pump | Configured room comfort targets | Base room target **10°C**, with temperature/moisture protection taking priority over price |

Use the existing Holiday state, not calendar dates, as the authoritative profile input. Artur is normally away from the end of October to the end of April, but travel dates can change. The current module default `HolidayID` is **46970**, verified as “Holiday Status Switch (Master)” in the supplied settings.

Holiday is a building-protection profile, not a whole-system shutdown. Existing night-disable must not suppress needed holiday protection; the current air module already bypasses night-disable when Holiday=true. Apply protection continuously, including weekends and nights.

On entering Holiday, preserve the normal comfort/hot-water configuration and apply separate protection targets to both the air rooms and radiator thermostats. Turning off hot-water heating means the verified boiler hot-water enable/service setting, not merely stopping a domestic-water circulation pump. On leaving Holiday, restore the configured home profile. A separately requested return/preheat mode may be added later; do not switch profiles from a guessed return date.

Use explicit per-service enable controls. Existing air master/cooling/summer controls must not accidentally shut down radiator or hot-water services. A gas-boiler-wide shutdown must be a distinct deliberate action, rather than a side effect of selecting heat-pump air heating.

## Independent radiator-circuit demand

Continue local AVM/Shelly valve regulation. Coordinate the radiator circuit pump from the aggregate of the radiator demands, independently of the selected air source:

- Start/maintain the radiator pump when an eligible radiator requests heat, subject to valid boiler/hydraulic availability.
- Stop when all demands have cleared, allowing the configured pump run-on and minimum on/off timings.
- Prefer an explicit valve/demand indication where available. Where a device lacks one, infer demand from its measured temperature and configured target with suitable hysteresis.
- Make the release threshold consistent with the local thermostat: a central pump controller must not wait for a temperature the closed TRV will never permit the radiator to reach.
- Retain the installed hydraulic bypass/flow safeguards. Confirm what feedback is real rather than emulated and avoid running the pump solely because a stale device reports demand.

The user’s current temperature-threshold pump policy can be retained as an initial compatibility mode, with separate home/holiday thresholds and hysteresis, before moving to aggregate local demand.

Relevant objects found in the supplied settings:

| Role | Object |
|---|---|
| Existing radiator-pump script | **33563** — Steuerung Pumpe Heizkörper Keller |
| Radiator-pump command variable | **44860**, child of KNX instance 57327 |
| Separate radiator-pump status variable | **49252**, child of KNX instance 45159 |
| Radiator-pump lock variable | **33182**, child of KNX instance 33970; polarity must be verified |
| Air heat-exchanger pump command | **32875** — separate from the radiator pump |
| Radiator return temperature | **40054** — Heizkörper Rücklauf |
| Boiler measured supply / return | **25861** / **48809** — Vorlauftemperatur / Rücklauftemperatur |
| Air heat-exchanger supply / return | **12393** / **34254** — separate circuit; not automatically a radiator sensor pair |

These are settings references, not confirmed live equipment responses. KNX command/status instances include status emulation settings. Before implementation, audit existing script/events, TRV demand mappings and the lock’s semantics. Transfer pump ownership deliberately so the legacy script and new coordinator cannot both command it.

## Shared boiler coordination and hot-water priority

Keep three independent request states: `HotWaterEnabled/HotWaterActive`, `RadiatorDemand`, and `GasAirDemand`. A heat-pump air request does not clear either of the first two.

The boiler retains native domestic-hot-water priority while that service is enabled. Gas air heating/radiator delivery may be interrupted during hot-water charging according to the installed hydraulics. Publish the interruption so the temperature-recovery watchdog does not mistake an expected priority pause for a failed gas circuit. The heat pump can continue serving air demand during hot-water operation if it is otherwise permitted; no intentional gas-air/heat-pump overlap is required.

Central-heating temperature/availability requests must reflect **both** hydronic loads. Stopping air heating must close/stop only its own gas heat-exchanger path, not remove a radiator request or impose an inappropriate global idle setting. While radiators are calling, preserve a suitable central-heating supply configuration.

An implementation issue to audit: HeatingControl currently writes its `GasFlowTargetID` **14533**, including an idle setting when air demand ends. In the settings this object is named **d.71 Max value flow temp**. A maximum flow-temperature limit is not necessarily an ordinary requested flow temperature. Verify the boiler command semantics and the radiator’s needs before changing or aggregating it. Shared boiler settings need one owner; neither air nor radiator logic should independently overwrite them.

For holiday mode, hot-water heating is explicitly disabled while space-heating availability remains enabled. Native boiler protective functions remain under the manufacturer/controller’s control. Avoid using a global boiler-off command to implement hot-water holiday disable.

## Holiday moisture and temperature protection

Use **10°C as the user’s normal base target**, not as a proven guarantee against mould. Moisture and cold surface temperatures determine mould risk. Room-air temperature alone cannot confirm that cold corners, bathroom walls or basement surfaces are sufficiently dry.

Add protection using individual room temperature and relative-humidity data, with dew point/surface-temperature information where available. Validate sensor location and freshness before relying on an existing humidity variable. A whole-house average must not hide a cold or damp room.

A proposed first policy is:

1. Maintain the configured 10°C room protection targets, with suitable thermostat hysteresis. Do not delay needed heating for a cheaper future slot once a room reaches its protection trigger.
2. If a room shows sustained excess humidity or surface-moisture risk, temporarily raise that room’s minimum target and raise a protection status. Make the humidity threshold, persistence, boost and release hysteresis configurable.
3. Select the least-cost source capable of meeting the elevated protection demand, with gas fallback if heat-pump capacity is inadequate. Price does not veto necessary protection.
4. If moisture stays elevated, escalate the condition and use a verified ventilation/dehumidification strategy. Heating can lower relative humidity and warm surfaces, but does not remove the underlying water.
5. Permit bounded price-based scheduling only inside the protection band. Retain existing frost/critical-temperature protection separately.

Do not claim mould protection from 10°C when humidity is missing or unreliable. Report the moisture assessment as unavailable while continuing temperature protection. A room-air RH threshold is a warning/control trigger, not a universal surface-mould limit; colder surfaces can have much higher humidity than the room sensor indicates. Condensation avoidance alone is also insufficient to prove absence of mould risk.

The numerical humidity/boost settings should be selected after inspecting representative winter humidity and the coldest rooms; no universal RH threshold is approved in this proposal. This is independent of the 25% economic uncertainty margin.

Primary guidance: Umweltbundesamt, “Richtiges Heizen schützt das Klima und den Geldbeutel” and the updated Schimmelleitfaden:

- https://www.umweltbundesamt.de/umwelttipps-fuer-den-alltag/richtiges-heizen-schuetzt-das-klima-den-geldbeutel
- https://www.umweltbundesamt.de/sites/default/files/medien/4031/publikationen/240513_uba_fb_schimmelleitfaden_0.pdf

## Consequence for the historical cost model

The main gas meter covers **air heating, radiators and hot water together**. The original export did not include radiator-pump command/status or Holiday/hot-water operating context. The supplemental export now provides this evidence for 1 January–12 February 2025; results are recorded below. Small background gas consumption alone is not an energy allocation between services.

If radiator use differed between heat-pump and gas-air reference periods, attributing the entire avoided gas to air heating can overstate the heat pump’s advantage. The supplemental check found no sustained commanded radiator circulation or normal hot-water charging in the reference periods. This reduces the mixed-load concern for these specific experiments; the 19/26/36 ct examples remain provisional because capacity, weather and stored heat are still confounded. A percentage discount cannot reliably correct unknown mixed loads in future operating profiles.

The settings contain radiator-pump and temperature variables and mark **44860, 49252, 40054, 25861, 48809, 12393, 34254 and 39898** as active in Archive Control. The supplemental export confirms retained baseline/change/aggregate coverage for these variables except 39898, which was not requested. Configured logging alone would not have established historical coverage.

Use pump state, a verified radiator supply temperature and radiator return **40054** to classify periods as inactive, delivering heat, or uncertain. Validate sensor placement, time alignment, transport lag, pump run-on and stale data. Boiler supply **25861** is a candidate proxy only if it represents the radiator inlet under the installed hydraulic arrangement. Boiler return **48809** can mix circuit returns. The existing difference **39898** is associated with air heat-exchanger supply/return **12393/34254**, not the radiator return; do not reuse it as radiator delta-T.

At unchanged water flow, a larger positive supply-minus-return difference means greater thermal power removal. Quantification requires flow: `P = mass_flow × specific_heat × delta_T`, integrated over time for energy. Local TRV movement changes flow, so delta-T or delta-T multiplied by pump runtime alone is not an established kWh or gas-allocation calculation. Initially use these signals to select matched or radiator-inactive comparisons, with uncertainty remaining for mixed-load periods. Reference: https://www.belimo.com/mam/general-documents/datasheets/en-gb/belimo_22PE-1UHH_datasheet_en-gb.pdf

For calibration, either select periods with hot water and radiators inactive, or compare periods with matched/identifiable non-air gas use. Add Holiday status, radiator-pump command/status, local radiator demand/targets, verified hot-water enabled/active state and shared boiler settings to future logs. The earlier export contains only an old baseline and no in-period changes for hot-water demand 46544 and valve-mode 59474. That does not by itself prove stale data: changed-value archives can represent a genuinely constant state. Cross-check freshness, polling, the physical meaning and other service signals before relying on these as evidence of hot-water inactivity.

Also record whether the gas boiler is already serving another load. Its incremental cost for air heating may differ from a gas-only air experiment; shared warm-up/standby losses and available capacity can affect the comparison. Boiler activity does not make gas heat free and does not automatically force gas air heating. Initially use a conservative/manual model for unresolved shared-load states, then calibrate them separately.

The economic decision is therefore: **which source is cheapest for the additional air-heating requirement while preserving all independent services and building protection?** It is not a whole-boiler on/off decision.

## Additional eBUS evidence below instance 30019

The settings identify instance **30019** as the ebusd MQTT Device. The following candidates are marked active in Archive Control, but retained historical coverage must be checked through export.

| Purpose | Variables | Interpretation and limits |
|---|---|---|
| Boiler measured temperatures | **42375** FlowTemp; **28805** ReturnTemp; alternative Status01 values **25861 / 48809** | 42375/28805 already have historical data in the detailed export. Boiler return can mix several loads; validate the supply proxy for the radiator branch. |
| Actual burner indication | **46594** Flame; **13465** Actual fan speed | Settings profile maps Flame **15=on, 240=off**. Fan speed corroborates combustion but can include purge/post-run; it is not the house-air fan. |
| Boiler pump operation | **35048** Central heating pump; **19456** external pump | Already included in the export; boiler pumps are distinct from radiator-circuit pump 44860/49252. Do not assume external pump means radiator pump. |
| Hot-water activity | **20574** storage-load pump; **46544** DHW demand; **36897** Position TWV | Combine these with storage temperature and operating-hour changes; verify valve codes and plumbing before classifying load. |
| Hot-water availability/profile | **26822** DHW demand enabled; **36726** Betriebsart | 36726 profile: 0 disabled, 1 on, 2 off, 3 auto. Mode/permission does not establish actual charging. 26822 is StoragereleaseClock; do not presume it is the sole holiday disable command. |
| Storage temperature/target | **15709** / **34157** measured storage temperatures; **23698** Storage setpoint | Validate alternative readings and time coverage; storage warming can corroborate charging. |
| Heating/hot-water counters | **28605 / 43372** operating hours; **25153 / 47542** burner starts | Useful independent evidence of service use. Coarse hours and start counts are not energy meters; validate units, rollover and reset. |
| Heating request/configuration | **39285** flow/return setpoint; **14533** maximum heating flow limit | Demand/setpoint is distinct from measured temperature; shared-boiler ownership remains necessary. |

Treat **59474** d.70 ValveMode as a configurable valve mode, not established physical-position feedback solely from its label. **36897** PositionValveSet is a more relevant valve-state candidate; its codes still require verification. The SetMode children **51354** disablehwcload and **41081** disablehwctapping have lastUpdate=0 in the supplied snapshot; their default values cannot establish applied holiday control.

The device exposes **44398 / 30754** heating energy-count/sum fields and **23198 / 18954** hot-water energy-count/sum fields. These are diagnostic candidates, not validated consumption measurements. Units, firmware support, scaling, counter behavior and agreement with the gas meter must be established before using them in the economic model; 30754 is close to the unsigned 32-bit maximum in this snapshot. **55586** MaxWaterFlowCH is a maximum/diagnostic field with value zero here, and **44112** is DHW water flow; neither is an established measured radiator-circuit volume flow. They do not resolve radiator calorimetry without further validation.

The supplemental export for **1 January–12 February 2025** has now been analysed, including preceding baselines. Continue combining pump activity, temperature response, burner operation and hot-water evidence for future comparisons. Maintain an explicit unknown class where signals disagree or lack historical coverage; do not invent an energy split.

## Validation using the supplemental radiator/eBUS history

Source: `Heating_radiator_ebusd_20250212_000000_c97d5a.txt`, supplied 11 October 2026, combined with the earlier detailed export. Both cover 1 January 00:00 to 12 February 00:00, Europe/Berlin, exclusive end. The supplement contains **358,822 raw changed-value records**, **21,168 hourly records** and preceding baselines for all 21 requested variables, with zero export warnings.

### Service-state findings

- **Holiday 46970 is true throughout all 1,008 hours.** A preceding true baseline and no changes agree with all hourly aggregates. These remain holiday/protection observations; they do not validate comfort-temperature use.
- **Radiator command 44860 has exactly one 30-second pulse per day, 00:00:00–00:00:30**, on every one of the 42 days. Total commanded on-time is **21 minutes**, or **0.35 hours**. There are no sustained radiator-pump command runs. Separate status 49252 agrees apart from one off-edge delayed by one second (28 January). The pattern suggests scheduled pump exercise; the script/hardware purpose has not independently been confirmed. KNX emulation means this is not proof of actual measured circulation.
- **Normal domestic-hot-water heating is not evident.** Storage setpoint 23698 is 15°C throughout; measured storage temperature 15709 spans 11.69–15.12°C. DHW operating-hours counter 43372 and burner-start counter 47542 do not increase. A storage-load-pump signal has fifteen short approximately one-minute pulses, totaling 15 minutes, and the valve-position candidate has one 60-second excursion. These fit brief maintenance/protection activity, rather than sustained normal-temperature storage charging, but their exact purpose is not established.
- **Service permission is distinct from actual heating.** Hot-water operating mode 36726 remains `on` and demand-enabled candidate 26822 remains 1, while the low storage target and unchanged DHW counters indicate normal hot-water production was suppressed. Do not claim an explicit boiler-service OFF bit from these records. For the new holiday strategy, verify how the installed boiler achieves the user’s intended hot-water disable while retaining native protective functions.
- **Boiler activity is independently corroborated.** Flame 46594, decoded with the supplied profile (15 on, 240 off), integrates to **84.95 indicated burner hours** over the complete period. Heating operating hours 28605 rise by **85 hours**, while DHW hours remain unchanged. This agreement supports the burner decoding and service interpretation. Burner data are sampled bus states, not an independently calibrated runtime meter.

### Comparison-block checks

Dates in this table are inclusive calendar dates. Radiator durations are commands; burner durations are time-weighted archived flame indications.

| Earlier comparison | Radiator-pump on-time in block | Indicated burner on-time | Storage-load-pump indication | Metered gas |
|---|---:|---:|---:|---:|
| HP about 100%, 1–10 January | 5 minutes | 1.08 hours | 4.02 minutes | 1.37 m³ |
| Gas reference, 12–17 January | 3 minutes | 26.07 hours | 2.00 minutes | 35.49 m³ |
| HP mixed 35%/50%, 2–3 February | 1 minute | 0.30 hours | 0 minutes | 0.32 m³ |
| Gas reference, 5–11 February | 3.5 minutes | 23.02 hours | 2.00 minutes | 34.25 m³ |

All four blocks have the same **30 seconds/day** radiator-pump policy. Neither sustained commanded radiator circulation nor normal hot-water production explains the large gas difference between HP and gas-air blocks. Most extra burner activity appears during gas-air operation. The previous HP-period background gas remains **0.137 m³/day** for the January reference and **0.160 m³/day** for the February reference; its precise physical cause is not established.

### Return-temperature interpretation and quality

The boiler-supply candidate minus radiator-return difference remains substantial even when the radiator pump is commanded off. Across valid observations in all 42 days it averages about **17.42°C with the pump off**, and **17.43°C during the brief pump commands**. These are not matched steady-flow experiments and the supply sensor is not independently confirmed to be at the radiator branch inlet. The result demonstrates why an unqualified delta-T calculation would be misleading here: temperature difference can persist in idle pipes and does not establish a measured water flow or delivered radiator heat. Residual warmth, conduction or passive circulation cannot be separated with the available variables.

Four radiator-return records and four air-heat-exchanger-return records equal −127°C. Each series spends **44 seconds** in invalid readings. Exclude those intervals from temperature metrics and avoid using them to trigger heating/protection decisions. A zero-warning export indicates successful queries, not universally valid sensor measurements.

### Consequence for economics and control

**No additional numerical radiator/DHW correction to the previous crossovers is justified from these data.** Retain approximately **19 ct/kWh** for the January near-100% pattern and **31 ct/kWh** for the mixed February pattern at the previously supplied gas tariff, as provisional historical operating-pattern estimates. This check reduces the specific concern about mismatched active radiator/hot-water loads; it neither supplies a radiator heat meter nor proves zero passive heat delivery. The 35% and 50% split, outside-temperature dependence, humidifier load, thermal storage and scarce independent experiments remain unresolved.

Keep the proposed 25% economic margin and switching hysteresis for review. Do not promote the mixed 38% average to a fixed hardware setting. Future calibration should log these service states alongside consumption and distinguish holiday trials from occupied-house trials, with explicit exclusions/unknown classifications when radiators or hot water are active. No control implementation or live heating settings have been changed.

## Module 1: ElectricityPrice

A provider adapter imports LichtBlick data and publishes a common tariff interface. The rest of the system must not depend on LichtBlick response field names, authentication or endpoint structure.

Publish:

- Current full variable consumer electricity price in ct/kWh, including VAT and all applicable per-kWh charges.
- A timestamped schedule for today and tomorrow when available, with each interval’s start, exclusive end and price.
- Retrieval status, last successful fetch, price origin, tariff/configuration version, and whether the consumer-price calculation is verified.
- Coverage status for the current interval and the next decision horizon.

Keep fixed monthly charges separate from the per-kWh comparison. Preserve negative prices if the verified customer tariff allows them; do not silently clip to zero.

LichtBlick’s official site displays regional prices on a 15-minute basis and says tomorrow’s prices can be viewed in the app. A documented customer price API has **not been verified** in this research. An app price display is not proof of an accessible, stable customer API.

Prefer a supported provider interface if available. Otherwise a separately verified tariff calculation can combine an appropriate market-price feed with the customer’s contractual charges. Reconstructed prices must be checked against the customer’s tariff/display before enabling automatic decisions. A public postcode quote must not silently be treated as the existing customer’s exact tariff.

Cache intervals. A failed refresh does not invalidate already obtained, verified prices while their interval coverage remains valid. If the required current/future intervals are missing, invalid or unverified, report unavailable; do not invent a forecast. Store absolute interval timestamps so daylight-saving changes do not require assuming 96 intervals every day.

Official reference: https://www.lichtblick.de/oekostrom/dynamic/

## Module 2: HeatingSourceSelector

Inputs:

- Verified electricity schedule; configured variable gas price and billed kWh/m³ conversion factor.
- Outside temperature, room temperatures and targets, room air-heating demand, temperature trends and air-heating availability from HeatingControl.
- Home/holiday profile, moisture-protection overrides, radiator demand and hot-water active/enabled state for shared-load eligibility and calibration.
- Applied source/power, transfer status and device/temperature response from HeatingControl.
- Existing energy/gas meters and fan/source commands for recording and later calibration.

Use recent smoothed outside temperature and room demand to identify an appropriate capacity candidate. A configurable empirical model estimates its electricity-versus-gas trade-off for those conditions. Include electricity from both measured circuits, including extra fan runtime. Do not assume a capacity command is a linear electrical or thermal percentage.

Choose the lowest permitted candidate that is expected to maintain room temperatures and still passes the economic criterion. The first candidates are 35%, 50% and 100%; these are configured operating points, not manufacturer minimum/modulation guarantees.

Publish one coherent plan:

- Source: gas or heat pump.
- Heat-pump capacity: validated integer percentage; zero/unused for gas.
- Issued timestamp, expiry, sequence and model revision.
- Decision reason, price inputs, estimated crossover, conservative ceiling, confidence/eligibility, and any fallback reason.

Suggested integration method: `SetAutomaticPlan(source, capacity, validUntil, sequence, reason)` on HeatingControl. This is a **proposed new method**, not an existing API. Validate and store the whole plan before triggering evaluation. Avoid writing source and power independently to two watched variables.

Re-evaluate locally every minute and at price/sensor/status changes. Renew a five-minute plan lease even when its source stays unchanged. Network retrieval is separate and should not run on every local evaluation.

## Module 3: HeatingControl enhancement

Add automatic source selection, disabled by default. Existing manual operation remains available. The selector never directly writes to pumps, fan, mixer, flaps, heat-pump on/cooling or physical percentage outputs. Its source request applies only to the air circuit; radiator and hot-water requests remain independent.

In automatic mode, use a valid plan as the effective source and power, only after the existing heating permissions and demand evaluation. The existing user heating/cooling selection remains authoritative: cooling cannot be converted to heating by a price recommendation.

Keep manual desired power 59800 separate from automatic requested power. Publish `EffectiveSource`, `EffectiveHeatPumpPower`, `PlanStatus`, `TransferState`, `HeatDemand` and override/fallback reason. Distinguish selected source from a source actually running. Explicit physical feedback should be labelled separately from a successfully sent command.

Dashboard controls should offer Automatic, Manual gas and Manual heat pump. Choosing a manual source disables automatic selection and restores the normal manual source/power path. The independent cooling, winter, night, holiday and master controls retain their meaning. A valid automatic plan cannot undo a manual shutdown or cooling selection.

If the selector stops or its lease expires, automatic air heating falls back to its gas path **only while air heating is enabled, air demand exists and that gas path is available**. If the air-heating controller’s own critical inputs or gas air path are invalid, show a fault and apply the agreed equipment fault policy rather than blindly starting gas. Preserve the current summer/master bypass semantics.

## Proposed economic margin and hysteresis

Start with a **25% reduction of the estimated break-even electricity price**:

`conservative price ceiling = estimated crossover × 0.75`

Allow a gas-to-heat-pump transfer only below a further **2 ct/kWh entry margin**. Once using the heat pump, allow it to stay until the conservative ceiling is reached. Between the entry threshold and ceiling, retain the currently selected source.

These are two different protections: the percentage margin covers uncertainty in the consumption estimate; the 2 ct band avoids repeated transfers near a price boundary. The entire staying band remains below the conservative ceiling.

Illustrative thresholds using the previously measured patterns and gas at 10.48 ct/kWh. The supplemental shared-load check reduces one concern for these periods; remaining model and temperature-stability checks are still required before adoption:

| Historical pattern | Estimated crossover | Conservative ceiling | Gas → heat pump below | Heat pump → gas at/above |
|---|---:|---:|---:|---:|
| Mainly 35%, mean outside +1.3°C | 36 ct/kWh | 27.0 ct/kWh | 25.0 ct/kWh | 27.0 ct/kWh |
| Mainly 50%, mean outside −0.5°C | 26 ct/kWh | 19.5 ct/kWh | 17.5 ct/kWh | 19.5 ct/kWh |
| About 100%, mean outside +2.4°C | 19 ct/kWh | 14.25 ct/kWh | 12.25 ct/kWh | 14.25 ct/kWh |

This table illustrates the rule. It is **not a validated universal percentage table**, an outside-temperature curve or approved production settings. The 35% period partly cooled the building, and the percentage/temperature effects are not separately identified. A margin reduces uncertainty; it does not prove accuracy or guarantee savings.

For initial commissioning, mark the historical profiles as provisional and make eligibility explicit. Start at the comparable low indoor targets (roughly 11–12°C) and overlapping weather conditions. Do not silently extrapolate their full economic thresholds to 21–23°C occupied-house heating or unsupported weather. Unsupported conditions default to gas; a separately selected measurement/test mode may exercise the heat pump to obtain more data.

Higher required capacity must pass its own economic test. For example, a price cheap enough for 35% is not automatically cheap enough for 50%. If the house needs 50% and that candidate fails, choose gas rather than leaving an inadequate 35% setting running.

## Time horizon and source-transfer stability

Suggested starting values, all configurable:

| Parameter | Proposal |
|---|---|
| Decision evaluation | Every minute and on relevant changes |
| Economic planning horizon | Next 60 minutes |
| Minimum time holding a selected heat source | 60 minutes |
| Plan renewal/expiry | Renew each minute; expire after five minutes |
| Outside-temperature smoothing | Approximately 10 minutes |
| Price uncertainty margin | 25% |
| Entry price hysteresis | 2 ct/kWh below conservative ceiling |

Evaluate the expected electricity cost over the holding horizon, using all covered price intervals. Weight prices by expected incremental electricity use; a uniform-runtime approximation can be the documented first version. Also apply the current-interval conservative ceiling before an economic switch. Require valid coverage for the planning horizon.

The 60-minute source hold is **not a command to run the compressor continuously for 60 minutes**. Normal room-demand control can stop heating. Heat-pump minimum on/off periods, cooldown and mixer travel are separate equipment parameters to verify from the installed equipment documentation; do not invent manufacturer timings.

At a normal heat-call boundary, or through the explicit transfer state machine if a transfer is needed during demand:

1. Request the old heating path off.
2. Respect equipment restart/cooldown and hydraulic/mixer transition requirements.
3. Check available feedback, with a bounded timeout. Do not equate a written Boolean to independently confirmed physical shutdown.
4. Configure and activate the new source, then use the existing fan-temperature and room-flap logic.
5. Monitor heating response and publish transfer success, command-only status or fault.

Preserve useful residual heat when appropriate. Do not intentionally command both heating paths active during transfer. Temperature protection or device failure may bypass the economic source hold, while retaining the physical transfer interlocks.

## Temperature and performance fallback

Under automatic heat-pump operation, monitor demanding rooms individually; a whole-house mean must not hide one cold room.

A proposed initial escalation criterion is a demanding room remaining more than **0.5°C below the lower boundary of its existing heating hysteresis** for **30 minutes**, with inadequate temperature recovery. This avoids treating ordinary thermostat hysteresis as a failure. Define the trend window and reset behavior in implementation.

When 35% is insufficient, try 50% only if it passes the price/availability criterion. Otherwise transfer to gas. Persistent insufficient heating at the permitted higher setting also transfers to gas. Keep a timeout before retrying the same failed heat-pump regime so the system does not bounce back immediately because electricity is still cheap.

Building frost, moisture and critical-temperature protection have priority over economics. Preserve its configured threshold; do not replace it with the economic margin. If gas is unavailable, report the condition and use an explicitly agreed backup policy rather than assuming fallback succeeded.

## Recording and improving the model

Archive applied source, heat-pump percentage, control policy, current consumer price, model revision, threshold, confidence, overrides and fallback events. Continue recording the existing meters, temperatures and fan/source commands. Record room targets and major configuration changes so later comparisons can identify changed conditions.

The selector can produce daily summaries of stable, exclusive-source blocks. Exclude source transitions, large indoor-temperature drift, invalid readings, configuration changes and ambiguous mixed operation. Preserve known limitations such as humidifier electricity and unseparated background gas.

Initially propose updated model coefficients for review, with their data count, operating profile, other boiler-load activity and conditions. Do not automatically replace the active model from one favourable day. A future bounded learning mode can use enough matched multi-day experiments, constrain coefficient changes and preserve rollback/version history.

Useful future comparisons deliberately exercise different percentages under overlapping outdoor conditions. Simply accumulating many gas days does not resolve the lack of heat-pump observations.

## Dashboard and delivery order

Recommended first rollout: automatic air-source selection in **Holiday mode only**, beginning with observation mode while existing manual control remains authoritative. Keep occupied-house operation manual until comparable comfort-temperature experiments support its model. This is a proposed commissioning scope, not an implemented or approved automatic policy.

Extend the existing passkey-protected heating page with separate domestic-hot-water, radiator and air-heating sections; home/holiday profile, base/effective room targets and humidity-protection status; price/current schedule, manual/automatic air policy, requested versus effective air source, automatic percentage, conservative threshold, reason, confidence and transfer/fallback state. Use the existing SecretsManager and portal integration.

1. Agree the decision, uncertainty, transfer and fallback rules.
2. Verify the actual LichtBlick tariff and supported price-access route; implement and validate the importer.
3. Add the selector in observation mode, recording what it would request while manual heating continues.
4. Add the HeatingControl plan interface and transfer handling; test dry-run, expiration, restart, invalid inputs and priority conflicts.
5. Activate automatic selection for comparable conditions with conservative thresholds, then widen the model as measured data improves.

This structure keeps price-source changes, economic calibration and equipment control independently maintainable while giving HeatingControl final authority.