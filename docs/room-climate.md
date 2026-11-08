# Room climate — RoomClimate v1 / heating library 0.3.52

RoomClimate is an independent, read-only room-climate module in the Heating-Control library. It displays every mapped measured temperature, target temperature and relative-humidity source separately, grouped by floor and room. It does not average away differences, write targets, operate heating, or alter archive logging.

## Install and open

1. Update Heating-Control to 0.3.52, build 63, then create a **RoomClimate / Room Climate** instance.
2. Select the **new native SecretsBackend** in `BackendInstanceID`. Automatic selection works when exactly one eligible backend/frontend pair exists. Legacy SecretsManager authentication is not accepted. The backend must expose `SECB_IsNativePortalAuthenticated` and `SECB_GetNativePortalLoginURL`; these are supplied by the native backend's LegacyRuntime integration bridge. No old vault is accessed.
3. Apply changes. Open the child **Room climate page** path `/hook/room_climate_<instanceID>` using the native Secrets HTTPS address. Anonymous access redirects to the new SecretsFrontend passkey login. Primary and approved backup-origin/transport checks are delegated to the native backend. Sessions are checked on every request, including cached history. No custom passkey store or browser token is created.
4. Update **Portal to 1.6** for automatic discovery under **Smart Home → Room climate**. The page offers Portal and Documentation return links. If multiple instances exist, select the intended Portal/Documentation in the module form.
5. Review **Floors, rooms and sources**. All source variables are selected through the object tree. Each room has a stable key, floor and room name, and a list of sources with variable ID, type and readable source name. Add, rename or remove rooms/sources in this graphical editor, then Apply.

The supplied Berlin template has **21 rooms and 103 source mappings**, derived from settings(4).json (10 October 2026) and Produktion.ipsView (9 October). It includes HomeMatic room temperature/humidity, KNX presence-sensor temperatures, Shelly and FRITZ radiator actuals/targets, HomeMatic wall targets, four shared air targets, the explicitly faulty Shelly H&T and identified FRITZ socket thermometers. Shared targets appear in both constituent rooms. Device-mounted thermometer labels distinguish them from reference room sensors. These are mappings, not saved temperature readings. Current values are read locally at request time. Other installations must replace Berlin-specific IDs.

## Reading the page

The three floors are Basement, Ground floor and First floor. Search filters room, floor and source names. Every source shows its variable ID and update timestamp. Missing, invalid, unavailable and stale measurements remain visible with a status. Default stale threshold is one hour (editable). Targets retain their update timestamp but are not marked stale solely because an unchanged setpoint is old. Temperature validity is −50 to 100°C; humidity is 0–100%. No invalid value is converted to zero.

Click any room to open two history plots: measured/target temperatures in °C, and humidity in %. Each source has its own colour; targets are dashed steps. Toggle individual series in the legend. Pointer/touch tooltips show each nearest recorded reading and its actual timestamp, so different sensor sampling times remain visible. Large gaps in measured temperature/humidity are left disconnected. Hovered times are in the browser timezone. This is a recorded-history comparison, not interpolation or an average of multiple sensors.

Choose 1 hour, 6 hours, 24 hours, 7 days or 30 days. Measurements use recorded readings up to 24 hours and hourly means for 7/30 days. Targets use recorded steps, with a labelled carry-in from the last prior archived setpoint when available. The carry-in keeps its original measurement timestamp in tooltips. If a series exceeds 1,000 points, only the latest 1,000 are returned and truncation is explicitly shown; a truncated target series gets no assumed earlier carry-in. Counter archives, absent logging, ambiguous archives and failed/missing APIs are reported separately per source. The module never enables logging automatically; historical data exists only if it was previously recorded.

## Traffic and responsiveness

The first state request includes room/source metadata and current readings. Subsequent requests carry an opaque cursor and return only changed values/status/timestamps. Unchanged responses are typically around 120 bytes of JSON (plus HTTP and native session validation overhead). Default refresh is every two seconds, editable from 1–60 seconds. This is polling, not guaranteed instantaneous push delivery. Timestamp updates count as changes even if the reading is numerically unchanged. No full page is reloaded.

Polling runs only while the tab is visible, uses no overlapping requests, pauses/aborts when hidden, and tolerates up to 90 seconds for a slow request. A restart/configuration change invalidates the cursor and causes a fresh metadata snapshot. History loads only for the opened room, is cached for 30 seconds and refreshes every 60 seconds while the dialog is visible. Closing it stops history requests. A disconnected page retains its last values and displays a connection warning. History requests cannot select arbitrary unmapped variables.

## Debug, import/export and central configuration backup

`DebugEnabled` writes a bounded 32,000-byte **Debug log** string variable below the instance. **Status** reports setup issues; **Room climate page** holds the hook path. No cookies, credentials or session material are logged or exported.

**Export configuration** fills the form's configuration field. **Import configuration** validates the versioned `RoomClimate.v1` document, property types, complete mapping structure and size limits before mutation. Import always disables web access until the native Secrets binding and local IDs are reviewed and the page is enabled again. It does not import current readings, history, debug logs or sessions.

The module implements `RCL_ExportConfigurationBackup` and `RCL_ValidateConfigurationBackup` using the existing **Symcon.ConfigurationBackup.v1** protocol. Current Configuration Backup automatically discovers these module-owned capabilities; no hard-coded adapter or additional source fingerprint approval is needed. Capture includes all configuration properties, verifies sourceHash against saved properties, and runs the native importer-format validator. Native manual restore uses `RCL_ImportConfig(instanceID, payloadJSON)`. A passing format validation is not an executed restore test. Include this instance under Configuration Backup's ordinary instance-selection rules.

## Validation and deployment limits

`php tests/room-climate.php` checks native-only authentication, failed/expired authentication, origin rejection, protected history cache, state deltas, stale/invalid measurements, duplicate archive timestamps, schema validation, import safety and debug output. `node tests/room-climate-ui.cjs` checks desktop/mobile layout, real CSP, room search, history modal, series toggles and tooltips with synthetic fixture values. Existing heating tests remain required. Live passkey login, archive coverage, actual source mappings and high-latency performance require checking after installation on Symcon.
