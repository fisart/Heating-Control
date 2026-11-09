# Ventilation & moisture

This independent module monitors room moisture and coordinates bounded ventilation cycles with the existing HeatingControl instance. It contains no Home Assistant or third-party runtime dependency. HeatingControl, RoomClimate and existing scripts are not modified.

## First setup

Create a VentilationControl instance from this library. Open /hook/ventilation_<instanceID> using the HTTPS address of the native Secrets backend. A valid passkey session is required on every page and API request. Select the native backend, Portal, Dokumenten and Configuration Backup instances in the console or Admin tab. Register Portal link adds a Smart Home card through the Portal's existing AdditionalPages setting; its software is unchanged. The Dokumenten link opens the installed documentation server; this operating guide is included in the new module itself.

The module starts disabled, automatic mode off, and live outputs off. Enabling the module with live outputs off produces previews only. Review the Admin tab before enabling live equipment outputs. The installation-specific air selector is confirmed: true means internal air, false means external air. The command variable defaults to 44949 and feedback to 53295. A true air-intake lock at 11048 blocks a cycle; the module never unlocks it.

Verify the actual fresh-air supply and exhaust route, the return-air route, fan speed, room damper positions and cooling interlock. Fresh-air supply needs a suitable exhaust path. Confirm the cooling zoning controller uses the same fan and ventilation-active flag (24607). AirPathVerified deliberately starts false; software/configuration inspection is not proof of airflow. Lower floor rooms without a known central duct branch are monitored but are not directly ventilated by this module.

## Selecting room sensors

Initial cold-sensor defaults were selected once from the 8 November 2026 settings snapshot. The lowest valid temperature in each room was used; sensors older than one hour and explicitly faulty sensors were excluded. Rooms with a required temperature, humidity or cold-point sensor set to 0 are removed from the room list. This also cleans existing saved configurations when Apply Changes runs; optional wall-sensor and air-branch IDs may remain 0. The selection is a saved configuration value and does not change as temperatures change. Choose another sensor on Admin, or deliberately repeat Select lowest valid sensors now. Ties select the lower object ID. Stale, faulty and unavailable inputs cannot win the live selection.

Dew point and vapour pressure use a matching temperature/humidity sensor pair. Relative humidity belongs to the temperature where it was measured. The selected colder sensor is used to calculate humidity at that colder point. It is not blindly paired with a humidity measurement from a warmer sensor. An optional surface-temperature variable improves the cold-point estimate. Without one, a cold air reading is a proxy and cannot prove the coldest wall is safe.

Kitchen lacks its own humidity sensor in the mapped inventory. Its default explicitly uses the matching temperature/humidity pair from blue room, on the same central air branch. Review this proxy mapping in Admin. Heating room, basement hallway, entrance hall, dining room, storeroom and dressing room were removed because a required sensor was 0. Fifteen rooms remain.

## Automatic and manual controls

Enable module permits evaluation. Automatic enable permits automatic cycles. Manual start requests one cycle with an automatic, internal or external air choice and a finite duration. Stop cancels the cycle AND disables automatic mode so it does not immediately restart. Disabling Automatic also stops a current cycle. Every stop finishes the equipment handover to heating. Disabling the module allows only cleanup of equipment already borrowed; it never starts another cycle.

Initial automatic defaults: humidity at least 60%, moisture excess at least 2 g/m3 for 15 continuous minutes, five-minute cycle, ten-minute maximum, and 30-minute cooldown. The room cold-point estimate must remain below the configurable 75% limit after outdoor air reaches that temperature. The start decision also checks a positive moisture advantage normalized to the same indoor temperature, avoiding misleading comparisons of cold and warm cubic metres. All rooms on every opened duct branch are checked, including monitoring-only rooms in those branches. Missing/stale connected-room inputs block operation rather than treating a missing value as zero.

During a cycle, stop early for heating demand, loss of drying advantage, stale input, a cold-point humidity limit, the room minimum, or a two-degree temperature drop. Defaults include an 8 C building-protection minimum and -5 C outside-air cutoff; these are commissioning parameters, not a validated mould-protection guarantee. The humidity stop target is 55%, with the configured cold-point limit also checked. Automatic operating hours default to 07:00–22:00 in the Symcon installation timezone; holiday mode can allow all-day operation.

Automatic internal-air balancing is off by default. If enabled, it requires at least a two-degree spread and checks the most humid connected return air against every cold point. It stops when the spread falls to one degree. Internal circulation redistributes heat and moisture: it does not remove water from the building. Manual internal circulation is bounded by the same temperature and humidity protections. CO2, smoke, outdoor pollutants and a measured air-change rate are not inferred from temperature/humidity; their future inputs would need separate configuration.

## Interaction with HeatingControl

HeatingControl remains the heating controller and sole heat-source controller. Ventilation never starts the heat pump or gas heating, and never changes room targets. It reads current targets, room temperatures, hysteresis, mode, physical on/off variables, residual recovery, tests and calibration. Heating or residual heat always takes priority; a cycle cannot start during cooling mode, master bypass, summer bypass or an active heating test/calibration. The shared fan must initially be off.

At an idle handover, the new module records a persistent recovery journal and calls the existing HC_SetModuleEnabled API to pause HeatingControl. Its source files and linked IDs remain unchanged. It opens the mapped room branches, selects the required air path, raises the existing ventilation-active flag to block the cooling controller, and waits for feedback plus damper travel before starting the fan. New module evaluation is every five seconds during ownership, with no blocking sleep.

Any new heating demand starts ventilation shutdown. Fan stop must be acknowledged, the original selector, speed, flap positions and ventilation flag are restored, and damper travel completes before HeatingControl is re-enabled through its existing API. This handover can take the configured damper/feedback time; it is not an instantaneous heat call. Gas/heat-pump selection, heating temperatures and residual heat remain HeatingControl decisions.

The journal is persisted before pausing heating. Restart or configuration apply cancels the former cycle and tries to restore equipment. No cycle automatically resumes from the journal. If device acknowledgements fail, heating remains paused and the status explains the pending recovery; the timer retries. If another operator re-enables heating, shared fan/flap commands stop to avoid interrupting it. If the heating configuration changes while borrowed, equipment recovery uses the saved original IDs and heating must be resumed manually. A process crash while the new module is permanently removed cannot execute cleanup: always Stop and finish the handover before deleting the instance or removing its library.

## Backups and security

Export/import includes settings and fixed sensor selections. Native import validates the schema and types, then disables the module, live outputs, automatic mode, web access and verified air-path setting. Review the restored IDs and backend in the console before enabling. The central Configuration Backup module discovers VMC_ExportConfigurationBackup and VMC_ValidateConfigurationBackup through the standard module protocol. Export and validation are read-only and do not claim a live restore test.

Canonical JSON preserves zero fractions, Unicode and slashes for compatibility with the central backup checker. Browser downloads preserve the exact exported JSON text, including float types. Sessions, CSRF keys, actuator ownership journals, manual requests, qualification timers and logs are intentionally excluded from configuration backups. The optional Debug variable retains the latest 32,000 bytes and contains decisions and errors, not passkeys or secrets.

The HTML page uses native Secrets passkey authentication, backend-validated HTTPS transport, same-origin JSON POSTs, a session-bound expiring CSRF token and a nonce Content Security Policy. It loads no external scripts. Missing native authentication blocks access. Existing portal URLs use /hook/portal.
