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
- Temperature freshness validation is optional and defaults to 0 (off) for
  compatibility with the script. A configured age limit prevents decisions
  from stale temperature readings.
- Every output batch validates its destinations before issuing commands.
  Variables with a custom or standard action receive `RequestAction`;
  otherwise the matching `SetValue` method is used. The action log reports
  requested commands, not independently verified physical feedback.

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

Confirm the room dialog, close the room list, then **Apply changes** in the
instance editor. Opening or editing the popup does not apply configuration or
send actuator commands. Applied changes follow the current Enabled/DryRun
settings. Existing room JSON loads automatically, including both Boolean
directions and arbitrary sensor counts. Old backups remain importable; exports
retain the original room schema with scalar sensor IDs and typed Boolean
commands. Invalid JSON is preserved and displayed for repair instead of being
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
flap commands, unchanged dry-run decisions, backup/restore and invalid input.
It does not replace a visual check of the native popup in the Symcon console.
