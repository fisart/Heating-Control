# HeatingControl for IP-Symcon

Version **0.3.47, build 58** adds **Dynamic electricity versus fixed price** to the protected consumption page's Strom tab. It weights interval prices by physical house-meter consumption across all hours and compares the same kWh with an editable gross fixed rate, initially 34 ct/kWh. Daily prices, euro differences, coverage and estimates are visible. Standing charges, bonuses, reference normalization and invoice adjustments are not added. A documented 123energie 35.479 ct/kWh preset is available. The fan-ON heating backtest remains separate. See [electricity comparison](docs/lichtblick-electricity-price.md#consumption-weighted-fixed-price-comparison--0347).

Version **0.3.44, build 55** adds **Heat-pump cost versus gas** to the protected heating page. Select Today, Yesterday, Last 7 days or custom dates (up to 31 days), then Calculate. Dedicated-meter electricity is valued at stored historical LichtBlick prices and compared with estimated gas for the same net air heat. Shared fan costs and fixed charges are excluded. The report shows matched coverage, daily chart/table and a CSV export; it changes no controls or logging. See [period cost comparison](docs/heatpump-period-costs.md).

Version **0.3.43, build 54** removes the module's two-minute source-transfer pause and five-minute heat-pump minimum-off delay, including before capacity-test preparation. Previously saved timing properties are inert. Source changes use OFF/CLOSED command acknowledgement only, with no added countdown; fan control remains based on the selected exchanger and current room targets. See [source handover without timed waits](docs/automatic-source-selection.md).

Version **0.3.42, build 53** adds **Enable/Disable heating module** and **Air-flap calibration** to the protected page. Disable releases every linked variable without shutdown/restoration commands, including from an active heat-pump test. Calibration holds heat sources and fan off, commands every configured flap open → close → open, waits the configurable **FlapCalibrationWaitSeconds** (default 120 seconds) at each position, and returns control to heating. See [module enable and flap calibration](docs/automatic-source-selection.md#module-enable-and-air-flap-calibration).

Version **0.3.41, build 52** controls the heating fan from the selected source's heat-exchanger temperature: **HeatPumpTempID** for heat pump, **HeatExchangerTempID** for gas. While rooms demand heat, the fan runs at or above the highest current target among those rooms and stops below it. A hot exchanger can start airflow even while the outgoing-air sensor is cold. Source transfer and compressor off-time protections still govern the heat sources independently. See [fan operation during transfer](docs/automatic-source-selection.md#fan-control-independent-of-source-transfer).

Version **0.3.39, build 50** expands the existing heat-pump calibration while preserving all 30 historical measured rows. Thirteen reviewed additions provide 39 eligible COP observations; the existing surface method now covers 3.25–16°C. Only evidenced defrost-like intervals are removed. See [the updated tables and surface](docs/heatpump-calibration-20261023.md). Existing settings and the chosen COP method remain unchanged.

Version **0.3.37, build 47** adds configurable fan run-on during residual heat
recovery (recuperation). The default is 60 seconds after a stop threshold is
crossed; the recovering room flaps remain open. A warm recovery cancels the
countdown. Manual recovery off, shutdown and air at or below the current room
temperature stop the run-on immediately. Configure **Recovery fan run-on** under
**Residual heat recovery** in the instance form (0–600 seconds; 0 disables it).
The dedicated timer ends the delay independently of the regular polling interval.

Version **0.3.36, build 46** adds opt-out temperature-transient handling to new
heat-pump calibration tests. Hold the same power through a suspected defrost-like
drop, quarantine the drop and recovery brackets, then collect the missing clean
measurement time. Counter energy and air-side heat cover identical retained
intervals. Raw data and a separate unfiltered full-step COP remain available.
See [transient detection and recovery](docs/heatpump-transient-tests.md).
Normal-operation fan control and the bundled COP surface are unchanged.

Version **0.3.35, build 45** corrects surface-mode eligibility: required rise is
`max(minimum rise, target + supply margin − actual inlet)`. Absolute inlet no
longer has to match a fitted reference. The existing COP surface, observed
capacity checks and exact measured COP values remain. Airflow and excluded-flap
matching no longer exclude normal operation in either COP mode. The reported 8.6°C outside /
20.7°C inlet case now produces a threshold. See [rules and worked example](docs/heatpump-cop-surface.md).

Version **0.3.34, build 44** bundles the latest dedicated-meter test and the
reviewed **v2 COP surface**: 27 accepted observations across four runs. Both
exact measured mode and surface mode can use the new calibration. Seven new
normal-heating points are accepted; the 90% defrost-like transient stays
excluded. The PHP surface reproduces the saved 3,195-cell matrix and uses
current provider settings, including a configured 5% safety margin, without
resetting them. See [COP surface selection](docs/heatpump-cop-surface.md).

Version **0.3.33, build 43** added a selectable **COP method** for the gas /
heat-pump price threshold. Keep **Measured points** (default, unchanged temperature
tolerance) or choose the fitted **COP surface** in the form or protected heating
page. The surface uses 1% power increments within 5–16°C outside; supply-air,
intake, fan/airflow and price safeguards still apply. The reviewed v2 refit includes both dedicated-meter and historical
household increments, with explicit provenance. See
[COP surface selection](docs/heatpump-cop-surface.md).

Version **0.3.32, build 42** adds the dedicated Shelly Pro 3EM heat-pump meter.
New tests use total active power **28681** and consumed energy **22365** (kWh),
without household/standby subtraction. Counter **57639** guards against new
export and **37456** verifies reachability. The form and protected page label
each result’s electrical basis; historical house-meter tests remain intact.
Meter mapping, scope, commissioning checks and setup:
[dedicated meter and capacity tests](docs/heatpump-capacity-test.md).

Version **0.3.31, build 41** adds a daily-reboot safeguard for heat-pump tests.
Select the reboot time in **Heat-pump capacity test** or on the protected page
(default 03:00, Symcon local time). The test pauses before a step would cross
the shutdown, confirms sources/fan off, and preserves completed steps.
After an actual restart and startup settling, it takes a fresh 15-minute
household baseline and repeats the pending step. Only a deliberately stopped,
configuration-matching pause can resume; other restarts and safety stops cancel.
The setting protects a test around an existing reboot; it does not trigger one.
The reviewed 22-point calibration from all three temperature runs is retained.

The measured outside/intake/power matrix is seeded with the reviewed
14 October house-meter test. Automatic Heating Control computes
EUR/kWh break-even/entry/exit thresholds from the selected point's estimated
system COP, live gas tariff and adjustable gas efficiency. Verified LichtBlick
price snapshots replace the provisional curve as the price input. The provisional weather/reference model and daily threshold
learner are retired; the measured matrix is the sole automatic calculation. Future tests use a 15-minute house-counter baseline, matched full-step heat
and electricity, and preserve prior weather measurements. The 40°C exchanger
rule is retired in favour of active room targets and useful air rise. Both forms
and pages separate current price settings, automatic controls, calibration,
consumption histories and diagnostics. See
[calibration, setup and further temperature tests](docs/heatpump-capacity-test.md).


This module migrates the Berlin **Heating Control in Produktion** script (ID 30716).
The 36 linked objects in the inventory captured on 26 September 2026 are
preconfigured as target variable IDs. Link IDs are not used as actuator IDs.
The current setpoints remain external IP-Symcon variables: for example,
hysteresis 58771 (then 1 °C), residual delta 28538 (then 6 K), desired fan
speed 48646 (then 90), desired heat pump power 59800 (then 83), and the four
room target variables. Their current values are read during each evaluation.

## Daily report heating-use percentages — 0.3.38

The daily Verbrauch & Kosten email now shows heat-pump, gas air-heating and idle time, with each share of the full day. It uses the connected Heating Control’s archived on/off states for the report’s Berlin day, including idle time; the three percentages total 100%. Missing or conflicting history is labelled unavailable. Gas radiator/hot-water use and energy/cost shares are outside this runtime comparison. See [the consumption report guide](docs/consumption-and-costs.md#daily-heating-use-split--0338-build-49). Existing report content, scheduling, controls and settings remain in place.

## LichtBlick prices and source recommendation

Version **0.3.0** adds a separate **LichtBlickElectricityPrice** instance with
postcode price retrieval, configurable threshold, editable safety margin
(default 25%), hysteresis, source/status outputs, configuration import/export,
a debug string and opt-in daily threshold learning from matched consumption.
See [setup and calculation rules](docs/lichtblick-electricity-price.md).
It publishes recommendations for air heating; it does not operate equipment.

## Automatic air-heating source selection

See the [heating source selection design](docs/heating-source-selection-design.md)
for the proposed LichtBlick price importer, gas/heat-pump decision module,
independent hot-water and radiator coordination, holiday protection,
conservative switching criteria and January–February 2025 validation.
Version **0.3.1**, build 11 connects the price recommendation to HeatingControl.
The **AutomaticSourceSelection** switch appears below the heating instance and
on its protected web page; it defaults to off. Select the LichtBlick instance in
the heating configuration. On uses its valid source and published power;
off uses the current source selector and retained manual desired power. Gas is the fallback
for missing, unverified or expired recommendations, subject to existing demand
and heating permissions. Source changes stop both air sources before starting
the other after OFF/CLOSED command acknowledgement. The former 120-second transfer
pause and 300-second observed off-time requirement were removed in 0.3.43; old
saved timing values are ignored. Command states are not physical stop feedback.

See [exact commands and setup](docs/automatic-source-selection.md) and
[heat-pump/fan research](docs/heatpump-capacity-research.md). Version **0.3.2**, build 12 adds an opt-in editable outside-temperature power/COP
curve, a weather-adjusted threshold and a SecretsManager-protected read-only
LichtBlick dashboard at `/hook/lichtblick_<instanceID>`. Enable its WebEnabled
setting and update MyAlarmSystem for automatic **Smart Home** portal discovery.
The curve is provisional; capacity optimisation from tests and wider
radiator/DHW/moisture coordination remain design work.

Version **0.3.4**, build 14 makes the heating dashboard's automatic/manual switch
a dedicated section above room climate. It shows the connected LichtBlick
recommendation and power alongside the controller selection, device command
values and transfer status, with a link to the protected price page. Select the
LichtBlick instance in the HeatingControl form and use **AutomaticSourceSelection**
below the instance or **Turn automatic on / off** on the page. Running heat-pump
power follows valid provider changes. Version **0.3.5**, build 15 also updates
the existing source selector **OperatingModeID** (default **41085**) to the
automatic selection: **1 = heat pump**, **2 = gas** by default. Automatic off
keeps the last selected source and uses the retained manual desired percentage. See [setup and behavior](docs/automatic-source-selection.md).

Version **0.3.6**, build 16 corrects **Back to portal** on the heating page.
It links to the single registered, enabled AlarmPortal using the heating page's
SecretsManager vault, at `/hook/alarm_portal_<instanceID>`. If no unambiguous
matching portal is available, a browser **Back** button is shown. The
SecretsManager login redirect remains separate from this navigation link.

Version **0.3.10**, build 20 retains live LichtBlick schedules and the protected
history chart. Earlier prices are maintained by standalone imports; historical
SMARD downloading and tariff reconstruction controls have been removed. Imported
app-normalized estimates are labelled separately from captured LichtBlick quotes.
The chart displays retail prices only; the published-interval table opens and closes by clicking its heading. Existing stored history remains intact. See [history and manual imports](docs/lichtblick-electricity-price.md#persistent-price-history-and-manual-import--039).

Version 0.3.11 adds protected electricity consumption/cost history from a reviewed
one-time archive import, separate cleaned/reference kWh, dated tariff costs and
explicit invoice reconciliation. Later closed quarter hours use the recorded live
meter and stored retail prices; missing data remain gaps. The connection is opt-in
and read-only. See [consumption history](docs/lichtblick-electricity-price.md#reviewed-electricity-consumption-and-costs--0311).

Version **0.3.12**, build 22 names the protected page **Verbrauch & Kosten** and separates **Strom**, **Gas** and **Wasser** tabs. The existing hook and electricity functions remain compatible. Gas reads a reviewed snapshot, sparse invoice references and later raw-counter events without changing the prepared archives. Daily kWh/costs and missing reset consumption are not invented. Water awaits its source review. See [gas history and page setup](docs/consumption-and-costs.md).

Version **0.3.13**, build 23 adds independently calculated gas costs from dated gross tariffs, basic charges and documented conversion factors. Current-year costs appear even when invoice periods cross the year boundary. Missing data and provisional later conversion factors remain marked. Confirmed outage periods show zero energy without rewriting archives; documented basic charges remain separate. See [gas cost calculation](docs/consumption-and-costs.md).

Version **0.3.14**, build 24 moves the gas/heat-pump threshold and associated recommendation, weather/power model, learning status and diagnostics into a separate **Heizung** tab. Strom retains electricity prices, consumption and costs. State refresh continues every 60 seconds and preserves the selected tab.

Version **0.3.24**, build 34 makes the capacity test follow the controller's `MaxSensorAgeSeconds` policy, including 0 to disable age-based stops for change-triggered temperature inputs. Raw readings and completed points retain temperature-age diagnostics; the electrical-meter update guard remains active.

Version **0.3.22**, build 32 fixes the email-preview download by supplying the explicit date argument required by Symcon’s generated function wrapper. The actual form action is covered by a strict-wrapper regression check.

Version **0.3.21**, build 31 adds **Send yesterday’s report as test email** in the instance form. It uses the selected SMTP instance, stores the identical HTML under the instance, and leaves daily scheduling unchanged.

Version **0.3.20**, build 30 adds a disabled-by-default daily consumption email at Berlin midnight. Select an SMTP instance from the object tree, download yesterday’s preview, and enable after review. The report lists electricity, gas, main water and free well water with known costs and a total; garden is not a separate row. See [daily reports](docs/consumption-and-costs.md#daily-consumption-email--0320).

Version **0.3.19**, build 29 adds immediate cursor tooltips and selectable datasets to all utility charts. Each dataset can be inspected on its own; chart scales follow the selection, while full period totals stay unchanged.

Version **0.3.17**, build 27 connects verified water imports and subsequent electronic readings. Main water (including garden) leads the water tab with calculated dated costs; well water shows consumption without charges. Manual garden periods are included references and adjust documented charges once. The total chart counts main plus well once. See [water and combined totals](docs/consumption-and-costs.md).

Version **0.3.15**, build 25 adds **Total consumption** with one selectable date range, quantities and costs for all five streams, and stacked charts for EUR, energy kWh or water m³. The water reader supports separate house/garden tariffs and zero-cost well water, subtracts nested garden volumes, and leaves missing meters/tariffs incomplete. See [water and combined totals](docs/consumption-and-costs.md).

## Installation and safe handover

1. Add `https://github.com/fisart/Heating-Control` in IP-Symcon Module Control.
   Create one **HeatingControl** instance. `Enabled` defaults
   to **false** and `DryRun` defaults to **true**; no actuator command is sent
   by a newly created instance.
2. Check each variable selector and open **Configure rooms...**. The four room flaps are
   seeded with their observed semantics: the two KNX percentage flaps use
   **open=0, closed=100**; the two Boolean flaps use **open=true,
   closed=false**. Confirm this on the installed hardware.
3. For a safe preview, leave `DryRun=true` and set `Enabled=true`. The module
   evaluates live inputs but writes only its own status and attributes. The
   legacy script may still be active during this preview. Inspect the
   `WOULD SEND` entries in `Heating action log` and the debug log.
4. Export a configuration backup. To hand over control, deactivate all events
   and any timer on the old script, then set `DryRun=false`. The new timer and
   change subscriptions are already active. Run **Evaluate now** and inspect
   real actuator feedback. Keep the legacy script for rollback but never run
   both controllers with physical outputs enabled at once.

## Behavior

- A true master disable, or a false winter state, means the module leaves all
  external actuators untouched. A true night-disable state shuts heating down
  unless Holiday is true. Cooling operating mode shuts heating down. Operating
  mode values are configurable and default to 0 cooling, 1 heat pump, 2 gas.
- Each room uses its configured sensor IDs, target and hysteresis; the demand
  latch is held in an instance attribute. On the first run inside the band,
  demand is initialized from actual temperature below the target. This may
  differ from the old script's persisted `DemandActive` flags.
- Residual heat reuses the **heating hysteresis variable** (`HysteresisID`,
  default object 58771) from version 0.2.4. Each eligible room remembers its
  recovery state. With hysteresis `h`, recovery starts when outgoing air is at
  least `ResidualMinOutgoingTemp + h` and strictly above `target + delta + h`.
  Once active, it begins the fan run-on when outgoing air falls below
  `ResidualMinOutgoingTemp - h` or reaches/breaches `target + delta - h`.
  Between the start/stop thresholds it retains its state, keeping the fan and
  recovering room flaps stable. Example: target 23 °C, delta 2 °C, hysteresis
  0.5 °C gives a start above 25.5 °C and a stop at/below 24.5 °C, provided the
  minimum outgoing temperature also permits recovery. Hysteresis 0 preserves
  the former threshold comparisons; set `RecoveryRunOnSeconds` to 0 as well
  for immediate switching. With the default 60 seconds, continuously low readings
  do not extend the deadline, and useful warmth cancels it. Each recovering room
  keeps its flap open until its own deadline. Air at or below that room’s current
  average temperature bypasses the delay to avoid cooling it. The run-on setting
  is included in backups; pending runtime countdowns are cleared on restore.
  Recovery memory is cleared by manual recovery off, normal heating, night/cooling
  shutdown, summer/master bypass, and disabling/restoring the instance. Bypass
  still leaves external devices untouched. The mixer positions are explicitly
  configured as 0 open and 100 closed.
- **Residual heat recovery enable** (German: **Freigabe Restwärmenutzung**,
  added in 0.1.3) selects an optional external Boolean variable. `true` permits
  residual heat recovery when its normal conditions are met; `false` stops it.
  When no room requests heating, switching it off closes purge flaps, stops
  the fan and clears internal/external residual status on the next evaluation.
  Normal room heating uses the selected-exchanger/room-target fan rule independently of the recovery switch.
  Changes to the variable trigger evaluation immediately when the controller
  is enabled. DryRun still suppresses all external writes; summer and master
  disable retain their bypass behavior. No variable selected (ID 0 or 1)
  preserves the existing behavior, with recovery permitted. The selected ID
  is included in configuration backups; automatic heating evaluation never writes
  to the switch. The authenticated web control page can change it explicitly.
- Temperature freshness validation is optional and defaults to 0 (off) for
  compatibility with the script. A configured age limit prevents decisions
  from stale temperature readings.
- Every output batch validates its destinations before issuing commands.
  Variables with a custom or standard action receive `RequestAction`;
  otherwise the matching `SetValue` method is used. The action log reports
  requested commands, not independently verified physical feedback.

## Secured heating dashboard (0.2.0)

Update both **Heating-Control** and **MyAlarmSystem** (for portal discovery).
In the HeatingControl instance, expand **Heating web page**:

1. Enable **Enable passkey-protected heating web page**.
2. Select the existing **SecretsManager** used by your portal. With no selection,
   exactly one enabled SecretsManager with a valid HTTPS `PortalOrigin` is required.
3. Apply changes. The module registers `/hook/heating_<instanceID>` after the
   instance lifecycle completes. **Register / repair heating webhook** retries
   registration if needed. The child **Heating web page** variable shows the path
   or registration error. Existing WebhookControl entries and pending edits are
   preserved; conflicting ownership is never replaced.
4. Refresh the existing portal. A **Heating Control** card appears in **Smart Home**.
   Open it using the SecretsManager HTTPS origin; the configured backup HTTPS
   origin is also supported. Anonymous visitors are sent to the existing
   SecretsManager passkey login with a fixed return path.

The responsive page groups room temperatures and targets, air/heat-source
measurements, equipment feedback and residual recovery, operating switches,
heating parameters and the latest evaluation. State refreshes every ten seconds.
Room demand is the last evaluated latch; dry-run recovery/demand are explicitly
marked as simulated. Equipment values always reflect the linked variables.
The freshness warning uses `MaxSensorAgeSeconds`; 0 disables age checks.

Controls update only configured room targets and the allowlisted mode, winter,
master-disable, night, holiday, recovery, hysteresis, delta, desired fan/power
and gas flow setpoints. There are no direct fan, pump, mixer or flap overrides.
They retain `RequestAction` when the variable has an action, otherwise use typed
`SetValue`. Values are type/range checked, including native numeric profile
bounds. A confirmed variable value is not a guarantee of physical device response;
commands awaiting feedback are shown as pending. Inputs aliased to sensors or
actuator/status outputs are unavailable as web controls.

**Disabled controller and dry run make all web controls read-only.** Web access
is independent of the controller switch, so you can inspect a disabled instance.
Existing winter/master bypass and holiday/night behavior still apply. True winter
permits heating; false winter retains the existing summer bypass. The recovery
switch and residual delta affect residual heat recovery only. During room demand, the fan follows the selected exchanger temperature versus the demanding-room target. Select `RecoveryEnableID` to control recovery through the page.

Every HTML/state/control request rechecks `SEC_IsPortalAuthenticated`; authentication,
passkey enrollment and revocation remain in SecretsManager. The page follows that
vault's portal-session policy. Writes require the exact configured HTTPS origin,
a session-bound CSRF token and JSON POST. No secret, cookie or passkey is placed
in a URL or configuration backup. HTML uses a nonce Content Security Policy;
labels and logs are rendered as text, and sensitive responses cannot be cached.

Configuration backups include `WebEnabled` and `VaultInstanceID`, but exclude
CSRF key material. Restoring a backup leaves web access and the controller disabled,
with dry run on, so review the vault and object IDs before enabling them.

Local checks: `php tests/room-editor.php`, `php tests/webhook.php`,
`php tests/webhook.php --no-auth-api`. The optional UI suite needs Playwright and
Chromium: `PHP_BIN=/path/to/php node tests/webhook-ui.cjs`. It uses mocked fixture
responses and never connects to your installed system or physical equipment.

### Web control compatibility (0.2.3)

JSON commands accept both PHP's `CONTENT_TYPE` and Symcon's `HTTP_CONTENT_TYPE`
header representation. This fixes “JSON body required” when applying room target
temperatures or other page controls. Requests still require a JSON content type,
valid JSON, an authenticated portal session, the matching origin and CSRF token.

### Device status and sensor charts (0.2.2)

**Controlled device status** separates three logical groups:

- **Heat pump:** activation, heating/cooling selection, current power and requested power.
- **Gas heating:** circulation pump, mixer open/closed/intermediate position,
  current flow target and heating/idle flow settings. Mixer interpretation uses
  the configured `MixerOpen` and `MixerClosed` values.
- **Airflow & room flaps:** fan activation, current/requested fan speed, and
  each room's flap open/closed/intermediate state and percentage where applicable.

These are the current linked variable values; viewing them never sends commands.

Underlined temperature readings open a sensor-history popup. Charts are available
only for configured heating temperature sensors whose logging is currently enabled
in exactly one available **Archive Control** with standard aggregation. Unrecorded
values stay plain text; the module does not enable logging. Missing archive APIs,
archive errors, ambiguous archives and temperature sensors configured as counters
make charts unavailable without disabling the rest of the page.

A room with one sensor links its displayed actual temperature to that sensor.
For multiple sensors, the calculated average stays plain text and individual sensor
values appear below it. Only the individual recorded sensors are clickable.
Calculated source-minus-incoming differences are not archived variables and stay
plain text. Setpoints and actuator values do not expose temperature-history links.

The popup names the sensor in its heading and chart caption, with the room or
environmental role to distinguish generic variable names such as “Wert”.
The period selector offers **Last hour**, 6 hours, 24 hours, 7 days and 30 days.
A separate **Resolution** selector offers Automatic, Recorded readings, Hourly
averages and Daily averages for each period. Automatic uses recorded readings for
Last hour, hourly averages for 6 hours/24 hours/7 days, and daily averages for 30 days.
The chart displays the selected rolling time window. Averaged edge intervals may
extend beyond it because Symcon supplies whole hour/day intervals; their original
timestamps remain visible in tooltips and the interval table.
The shaded area in averaged views shows the recorded minimum/maximum range.
Timestamps use the browser timezone. Empty periods are shown explicitly; no live
readings are inserted into history, and missing intervals are not connected across gaps.
Archive reads and responses are bounded (at most 800 records), including raw
readings. This accommodates the full 30 days at hourly resolution. More densely
recorded histories show the latest 800 readings and explicitly label truncation.
Archive eligibility is rechecked on every
chart request; stale links cannot bypass disabled logging. The same SecretsManager
session and HTTPS-origin checks protect chart requests, including during dry run
or with the controller disabled.

Additional local checks: `php tests/archive.php`,
`php tests/archive.php --no-archive-api`. `node tests/page-dom.cjs` requires jsdom
and checks page interactions without a browser; it does not verify browser layout
or CSP enforcement. The optional Playwright suite also exercises the chart popup.

## Debug and backup

### Room editor (0.1.1)

Open **Configure rooms...** (German: **Räume konfigurieren ...**) and use the
pencil icon to edit a room or **Add** to create one. Each room dialog provides:

- Room name.
- A list of temperature sensors selected from the object tree; multiple sensors
  are averaged. Add/remove sensor rows as needed.
- Variable selectors for the target temperature and the air flap.
- Flap command type: Boolean or percentage.
- Open and closed commands. For a Boolean flap enter 0=off or 1=on; for a
  percentage flap enter values from 0 to 100. The two commands must differ.
- Optional existing Boolean demand-status variable and the original room group
  category used by `LastHeatedGroupID` (added in 0.1.2).

Confirm the room dialog, close the room list, then **Apply changes** in the
instance editor. Opening or editing the popup does not apply configuration or
send actuator commands. Applied changes follow the current Enabled/DryRun
settings. Existing room JSON loads automatically, including both Boolean
directions and arbitrary sensor counts. Old backups remain importable; exports
retain scalar sensor IDs and typed Boolean commands, plus the optional status
and original group IDs. Invalid JSON is preserved and displayed for repair instead of being
silently replaced with defaults.

The form checkbox `DebugEnabled` controls writes to the **Heating debug log**
string variable beneath the instance. The log includes input states, room
decisions, residual/fan calculations, skipped equal values, commands and
errors. It retains the most recent 32,000 bytes. Turning debug off stops new
debug writes; it does not erase earlier entries. `DryRun=true` suppresses all
external commands and marks the decision `[DRY RUN]`. Internal status and
demand-latch variables still update. Every restore forces `Enabled=false` and
`DryRun=true`.

**Export configuration** places portable JSON in the form. **Restore
configuration** validates its schema and property types, imports the IDs and
room list, then forces `Enabled=false` so an import cannot start the heater.
It does not restore runtime demand latches, actuator states or debug history.
Object IDs are specific to the source installation and must be checked before
using the backup elsewhere.

## Existing status outputs (0.1.2)

Open **Existing status outputs** (German: **Vorhandene Statusausgänge**) for
the shared outputs. Per-room mappings are in **Configure rooms...**. The
default mappings verified against the installation screenshots are:

| Room | Boolean demand status | Original room group category |
| --- | --- | --- |
| Living and Dining Room | 43898 | 25055 |
| Guest Bedrooms | 50623 | 38782 |
| Master Bedroom | 36744 | 16188 |
| Blue Room and Kitchen | 53400 | 36698 |

| Shared output | Default ID | Updated when |
| --- | --- | --- |
| Residual Heating | 57844 | After a successful active evaluation |
| LastHeatedGroupID | 52602 | There is heating demand; stores the original category ID of the last demanding room |
| Heating Action Log | 21945 | After a successful active evaluation; HTML list for existing IPSView displays |
| Configuration snapshot | 29352 | **Export configuration** is pressed; contains the portable module backup, replacing the one-time installation inventory |

All external status writes require `Enabled=true` and `DryRun=false`. A preview
updates only the module's own variables and attributes, so it can coexist with
the original script. Summer and master-disable bypasses leave external statuses
untouched. Night/cooling shutdown clears room-demand and residual indicators;
the last heated group is retained. Room Booleans mean **heating demand**, not
flap position: during residual heat they are false even if a flap is open.

Clear an optional selector (0 or 1 means unselected) to disable its output. If
the last demanding room has no original group category selected, the legacy
last-group value is retained. Status variables are written directly with
`SetValue`, even if they have custom actions; they do not receive `RequestAction`
and are not subscribed as controller inputs. Missing variables, wrong types,
duplicate status destinations and collisions with control inputs/actuators are
reported before any heating command is sent. Debug logging includes status writes.

Existing room configurations acquire the above defaults only when their target
and flap IDs still match the original Berlin wiring; other rooms default to no
external status mapping. Explicit mappings, including unselected outputs, are
preserved in backups. The old `DemandActive`, `PreviousTemp` and `PurgeActive`
variables beneath the room groups are not updated by this compatibility feature.

## Important differences from the old script

- Missing room sensors and invalid flap values are reported as errors instead
  of allowing a division by zero or guessing values from profile text.
- Only the module's own change subscriptions are managed. Unrelated events
  beneath the old script are never deleted.
- In heat pump mode the gas mixer is commanded to configured `MixerClosed`
  (100), not PHP Boolean `true` coerced to 1 by the old script. Confirm this
  position on the real mixer before activating the instance.
- The former `IS_AT_HOME` and outside temperature links are retained in the
  form as reference IDs but do not participate in heating decisions, matching
  the source script.

The module has not been executed against the live Berlin IP-Symcon system.
See [MIGRATION.md](MIGRATION.md) for the cross-check of external object IDs,
legacy status variables, charts, helper scripts and script events.

## Room editor checks

Run `php tests/room-editor.php` with PHP 8.1 or newer. The test uses a small
Symcon stub to check legacy conversion, multiple sensor subscriptions, typed
flap commands, recovery switch transitions, normal fan control, unchanged dry-run decisions, backup/restore, external status
publishing, shutdown/bypass behavior and invalid input.
It does not replace a visual check of the native popup in the Symcon console.



## Controlled heat-pump test — 0.3.23

Start/cancel an eight-step 30–100% capacity test from the form or protected heating page; default 15 minutes per step. Master-bedroom flap stays closed. Raw readings, stable-point relationships and estimated COP persist. Occupied exchanger minimum is 40°C; holiday requirements follow configured room targets with an adjustable lower floor. See [test setup and local model limits](docs/heatpump-capacity-test.md).

