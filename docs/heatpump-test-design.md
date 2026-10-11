# Proposed controlled heat-pump capacity test

This is a proposed experiment, not an active automation or an actuator script.
It would help separate commanded capacity, weather and fan energy, which are
confounded in the existing archive. The first run should be manually started
and supervised; later scheduling can repeat accepted runs in weather bands.

## First sequence

Hold the fan speed and the same adequately open room flaps constant. Keep existing
room targets unchanged. Test **50% → 35% → 65% → 50%**, with **30 minutes per
block**: initially allow 15 minutes for settling and measure the next 15 minutes.
The repeated 50% block helps reveal weather/building drift. This gives a two-hour
session, plus start/finish overhead. These are trial points and timing proposals,
not manufacturer optimum values. Add higher settings only if useful demand exists.

Keep the heat pump enabled between percentage blocks rather than deliberately
creating four compressor restarts. Its own ramp/defrost/temperature protection
remains authoritative. A 15-minute settling period can still be insufficient;
record actual operation and reject/extend an unsettled block, with a configured
maximum total run time. Record transient energy as well as settled energy.

Log every 30–60 seconds and preserve actual timestamped meter changes:

- Heat-pump circuit **35702 W / 12254 cumulative pulses**.
- Fan/heating circuit **47831 W / 10054 cumulative pulses**.
- Actual percentage command **19207**, enable **35931**, heat mode **51011**.
- Fan command **46921**, fan speed **29718**, flap commands.
- Outside **25911**, incoming air **15315**, outgoing air **40843**,
  HP heat-exchanger sensor **58696**.
- All room temperatures and targets; gas-air pump/mixer states.
- If accessible, actual compressor capacity/running, defrost, wait and error status.

Calculate kWh from counter differences. Compare air temperature rise at comparable
fan/flap conditions, room temperature trends and total HP-plus-auxiliary energy.
Without calibrated airflow, this estimates relative useful heating and cannot
claim an absolute measured COP. A room warming more slowly is not an equal-heating
comparison even if its electrical rate is smaller. Include fan run-on and start-up
costs when comparing whole heating strategies.

## Ownership and stop conditions

Implement a dedicated, expiring **test mode inside HeatingControl**, or an equivalent
exclusive ownership mechanism. Simply disabling automatic price selection is
insufficient: the normal demand controller could still overwrite percentages,
flaps or fan settings. A blocking PHP script or an unsupervised external loop must
not compete with normal heating evaluations. Use a persistent nonblocking timer
state machine with explicit start/cancel and a maximum-duration watchdog.

Before a run, verify sensor/counter validity, useful heating demand, gas-air path
stopped/isolated, adequate airflow, and the equipment-specific temperature/airflow
limits. Do not invent an acceptable heat-exchanger maximum from the archive. Refuse
a run if required limits/feedback are unconfigured or controller ownership is
uncertain. Preserve boiler/radiator/DHW operation outside the air path.

Cancel on user stop, expired lease/time limit, cooling/manual shutdown, stale or
invalid sensors, invalid counters, inadequate airflow, excessive temperature,
equipment fault, room overtemperature or loss of useful test demand. Record why;
stop test output and return control through the normal protected source transfer.
Preserve room targets and stored manual settings. Restart/recovery must clear the
test lease and stop a test-owned source before normal control resumes. Hardware
protection remains essential if the automation host itself fails.

## Weather repeats

Possible initial analysis bins: **below 0°C**, **0–5°C**, **5–10°C**, **above 10°C**.
Only run when heating demand exists; warm weather may be unsuitable, especially
for a 10°C holiday target. Prefer night/low-solar periods, similar target/room
conditions and outside drift within roughly 1°C over a session. These are proposed
eligibility limits. Repeat each bin on multiple days and vary block order to reduce
warm-up/order bias. Mark defrost and changed airflow; separate their settled
performance effects while retaining their energy in complete-cycle comparisons.

Automation should initially notify/prepare a suitable window; after supervised
validation it can run within explicitly configured eligibility and operating
limits. It must not chase outside temperature indefinitely or run merely to gather
data when the house does not need heat. Only quality-controlled results should
update a capacity/weather model or price crossover, retaining the configurable
25% safety margin.
