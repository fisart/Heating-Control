# HeatingControl for IP-Symcon

This module migrates the Berlin **Heating Control in Produktion** script (ID 30716).
The 36 linked objects in the inventory captured on 26 September 2026 are
preconfigured as target variable IDs. Link IDs are not used as actuator IDs.
The current setpoints remain external IP-Symcon variables: for example,
hysteresis 58771 (then 1 °C), residual delta 28538 (then 6 K), desired fan
speed 48646 (then 90), desired heat pump power 59800 (then 83), and the four
room target variables. Their current values are read during each evaluation.

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
- Residual heat uses outgoing air at least 22 °C and above the room target
  by more than the configured delta. The mixer positions are explicitly
  configured as 0 open and 100 closed.
- **Residual heat recovery enable** (German: **Freigabe Restwärmenutzung**,
  added in 0.1.3) selects an optional external Boolean variable. `true` permits
  residual heat recovery when its normal conditions are met; `false` stops it.
  When no room requests heating, switching it off closes purge flaps, stops
  the fan and clears internal/external residual status on the next evaluation.
  Normal room heating and its fan-temperature condition are unaffected.
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
switch affects residual heat only; the delta also affects the normal source fan
threshold. Select `RecoveryEnableID` to control recovery through the page.

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

