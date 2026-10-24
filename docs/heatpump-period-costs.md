# Heat-pump cost versus gas

Available from **0.3.44, build 55**, on the existing passkey-protected Heating
Control page. Select **Today**, **Yesterday**, **Last 7 days** (including today),
or **Custom dates**, then **Calculate**. Custom periods cover up to 31 inclusive
Berlin calendar days. Today stops at calculation time; future dates are rejected.
Normal ten-second status refresh does not repeatedly query historical archives.

The three main figures compare the **same covered intervals**:

* Heat-pump cost from the dedicated consumed-energy counter and the historical
  electricity price for each interval.
* Estimated gas cost for the same net delivered air heat, using the configured
  useful gas-to-air efficiency and the applicable stored gas unit tariff.
* Saving versus gas, or additional heat-pump cost, in euros and percent. The
  percentage denominator is the equivalent gas cost; it is unavailable if zero.

The shared fan operates with either heat source, so **fan electricity is
excluded**. Fixed connection/standing charges are also excluded. The heat-pump
counter is not reduced by a household baseline or a standby allowance. Actual
startup, suspected defrost and standby electricity stay in the consumption.
Switching safety margin and hysteresis do not enter retrospective costs.

## Existing links and assumptions

No additional form inputs are required. The report reuses:

| Existing setting/link | Use |
| --- | --- |
| `HeatPumpTestArchiveID` | Read-only Archive Control access |
| `HeatPumpTestDedicatedCounterID` / `HeatPumpTestDedicatedCounterScale` | Dedicated heat-pump kWh; default counter 22365, scale 1 |
| `ElectricityPriceInstanceID` | `LBEP_GetPriceHistory`, stored `GasHistoryTariffs`, configured `GasPriceCt` |
| `IncomingAirTempID` / `OutgoingAirTempID` | Archived inlet/outlet air temperatures |
| `HeatPumpOnID` / `HeatPumpHeatModeID` / `GasPumpID` | Attribution to heat-pump or gas air heating |
| `FanOnID` / `FanSpeedID` | Whether air moves and the configured airflow is applicable; **not fan electricity cost** |
| `HeatPumpTestFanSpeed` / `HeatPumpTestAirflowM3h` | Existing airflow assumption at the recorded matching fan command |
| `HeatPumpGasEfficiencyPercent` | Existing useful gas-to-air efficiency assumption |
| `MaxSensorAgeSeconds` | Positive configured temperature-age limit, or 0 for legitimate held change-only archive values |

The report always uses the dedicated meter, independently of a legacy test's
selected meter mode. Missing dedicated-meter history does not silently fall
back to a whole-house estimate. The current configured airflow and efficiency
are applied to the selected dates and shown explicitly. At the default 90%
fan command, the 2,000 m³/h assumption is **not multiplied by 0.9**: the label
selects the full-speed transformer tap. Other recorded fan commands are marked
as unknown airflow instead of inventing a linear airflow curve.

If a date has no stored gas tariff, the current configured gas unit price can
be used, explicitly marked as an assumption/provisional result. A missing
historical electricity price is never replaced by today's price. Unverified
stored retail prices retain their provisional label. Missing/invalid gas
configuration cannot silently invent a rate. The page shows the gas rate/range.

## Heat estimate and defrost

For identified heat-pump air delivery, integrate
`0.000335 × airflow m³/h × (outlet − inlet) °C × hours`.
Temperature and state changes are held until the next archived change, following
Symcon's change-only archive semantics. Heating input while the fan is off
delivers zero duct-air heat during that interval. Source flags and fan history,
with up to two hours of preceding context, retain identifiable heat-pump fan
run-on. Gas heat and gas run-on are excluded. Unclear overlapping/missing source
states cannot be treated as heat-pump heat. This is an air-side estimate; it does
not use the COP surface or claim a heat-meter measurement.

Signed temperature differences retain cooling during suspected defrost.
Calibration's clean-window exclusions do not apply to this actual-cost report.
The gas equivalent is `net delivered heat / gas efficiency × gas unit price`.
A matched period with negative net heat does not show a gas saving. Source
attribution and configured airflow remain estimates, especially around handover.

## Coverage, graph and export

Counter readings must form monotonic brackets. Linear interpolation is limited
to 180 seconds for nonzero consumption; a long equal-counter bracket establishes
zero energy. Resets, implausible increments, absent boundaries and unreadable
logging are not reconstructed. Only actual archived values are used, with no
current-value substitution for missing historical data. A final meter poll can
lag the current time, making Today's last moments incomplete.

When data is incomplete, the cards and saving compare only matching intervals,
with **Partial comparison**, matched-time percentage and named reasons. The
separate full-period measured electricity cost or known subtotal remains visible.
Missing amounts display as unavailable, not zero. Achieved COP is the ratio of
matched net heat to matched electricity; ratios are not averaged across days.

The daily bar chart has separate heat-pump/gas series toggles and values on
hover, keyboard focus or tap. The daily table and **Download CSV** contain costs,
matched energy/heat, savings, coverage, provisional status and data remarks.
The CSV also exposes the known full-period electricity cost and its completeness.
The gas cost remains an estimate throughout. The report does not mix whole-house
gas used for radiators or hot water into the air-heating comparison.

## Read-only API and verification

`HC_GetHeatPumpCostAnalysis($instanceID, '2026-10-20', '2026-10-23')` returns JSON.
The page uses authenticated `view=heatpump-costs` and `view=heatpump-costs-csv`
requests with `from` and `through` dates. Access follows the existing passkey and
HTTPS-origin checks. There are no control, property, archive-writing or logging
commands; the report also works when heating is disabled or in dry run.

Run `php tests/heatpump-period-cost.php` and
`node tests/heatpump-cost-page-dom.cjs` (with jsdom). Tests cover independently
calculated tariff weighting, gas efficiency, signed defrost heat, standby and
residual heat, matched subsets, meter gaps/resets, missing/negative/zero prices,
gas fallback labels, changed-value temperatures, DST and date bounds, bounded
archive pagination, passkey/CSV access, immutable controls, charts/toggles,
escaping, stale request replies and session expiry.
