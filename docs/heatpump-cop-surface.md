# Selectable COP method — 0.3.35, build 45

The bundled surface now uses the seven valid dedicated-meter measurements from
15–16 October, together with the twenty accepted historical house-meter points.
There are **27 fitted observations across four runs**. The exact measured matrix
preserves all 30 original/reviewed rows, including three exclusions. The latest
90% defrost-like transient is not used to fit normal-heating performance.

Under **COP method, operating matrix and electricity break-even price**, select
**Measured COP points** or **Calculated COP surface**. The protected heating page
has the same selector. Existing method choice, automatic/manual selection,
temperature tolerance and price-provider settings survive the update. Selecting
a method does not enable automatic heating or overwrite manual desired power.
Active/paused tests lock method changes; passkey/CSRF/read-only gates remain.

| Method | COP used | Qualification |
| --- | --- | --- |
| Measured points (default) | Exact recorded COP; latest dedicated points are bundled alongside historical points | Existing outside AND inlet tolerance; stable/usable flags; fan, airflow, flap and supply checks |
| Calculated COP surface | Reviewed heat/electric interpolation, evaluated at actual outdoor temperature and 1% power steps from 30–100% | 5–16°C domain; observed within-run capacity brackets; modeled AND observed air rise must meet target + supply margin − actual inlet and minimum rise |

Both methods use the current verified LichtBlick quote and its actual safety
margin and hysteresis, without substituting factory defaults:

    break-even = gas price × selected COP / gas efficiency
    heat-pump exit ceiling = break-even × (1 − safety margin / 100)
    heat-pump entry = exit ceiling − price hysteresis

For a provider configured at 5%, the multiplier is 0.95, applied once. At the
recorded dedicated 50% point, gas 10.48 ct/kWh, 100% efficiency and 1 ct/kWh
hysteresis give entry below 30.93 ct/kWh and exit at 31.93 ct/kWh. These are
examples; module updates do not change live settings. Invalid/expired quotes
clear thresholds and select gas. Unsupported surface conditions select gas with
an explanation, without silently switching to measured mode.

## Mathematical surface

Version **provisional-heatpump-surface-2026-10-16-v3** corrects runtime eligibility.
The v2 fitted coefficients, observations, charts and saved analysis matrix are unchanged. Coefficients and diagnostics:
[data/heatpump-cop-surface.json](data/heatpump-cop-surface.json). Exact input rows
and exclusion flags:
[data/heatpump-cop-surface-observations.json](data/heatpump-cop-surface-observations.json).
Their SHA-256 is verified before evaluation. The
[dense reference matrix](data/heatpump-surface-matrix.json) contains 45 weather
rows × 71 power columns: 3,195 estimates per field, including COP, air rise,
electrical/thermal kW, reference inlet, support and illustrative 5% prices.

The controller interpolates full-period air rise and electrical kW using a
minimum-curvature thin-plate radial basis plus an affine trend. Coordinates:

    x = (outside − 10.5) / 5.5
    y = (power − 65) / 35
    phi(distance) = distance² × ln(distance); phi(0) = 0
    F(x,y) = sum(weight_i × phi(distance_i)) + a0 + a1×x + a2×y
    estimated heat kW = 0.000335 × 2000 × interpolated air rise
    estimated COP = estimated heat kW / interpolated electrical kW

The same basis interpolates a descriptive reference inlet. Zero smoothing
retains all accepted measurements exactly; it does not establish predictive
accuracy. The old imposed positive weather slope and fixed 40–50% efficiency
peak are removed. Whole-run holdout RMSE is approximately **0.449 COP** and
**2.762°C air rise**. The surface remains provisional, with four runs and shared
airflow/meter uncertainties outside those diagnostics. It is not a seasonal or
complete-defrost-cycle COP model.

Historical measurements keep **house-counter-minus-pretest-baseline** provenance;
new measurements keep **dedicated-heatpump-import-counter**, counter 22365 and
power 28681 with no household/standby subtraction. A modeled candidate is labelled
**reviewed-house-increment-and-dedicated-heatpump-fit**, not a dedicated reading.

## Capacity and operating safeguards

Air origin alone is not a separate selector: heat uses outlet minus inlet.
For surface eligibility, actual inlet determines the **required rise**, rather
than having to match an absolute fitted reference inlet:

    required rise = max(configured minimum rise, room target + supply margin − actual inlet)

Both modeled rise and the independent observed-capacity rise must meet this
requirement. The old two absolute-inlet matching gates are removed. The fitted
reference inlet and observed reference inlet remain descriptive diagnostics;
they cannot reject a surface candidate. Exact measured mode retains its existing
outside AND inlet tolerance unchanged. This is a control-model assumption;
these four runs cannot independently establish inlet-temperature effects.

The controller interpolates full-period rise within each accepted run's measured
power bracket, then between weather runs. It never extends a run beyond its
measured power range. Outside an available weather bracket, a nearest endpoint
must be within the configured tolerance, at most 3°C. Both modeled and observed
capacity must meet the required rise. Fan command 90, configured airflow
2,000 m³/h (within 1 m³/h), and excluded-flap setting 40259 must match the
calibration settings. These are configuration checks, not physical airflow or
flap-position verification.

The runtime domain remains 5–16°C outside and 30–100% power. The analysis grid has
2,632 cells inside the two-dimensional observation hull and 563 extrapolation
cells. A cell's geometry label alone does not authorize operation: observed
capacity coverage and all other requirements must also pass. Nonfinite results,
nonpositive air rise, electrical input at or below 0.05 kW, and COP outside
0.5–10 are rejected. Invalid sensor inputs or an enabled sensor-age limit can
also prevent evaluation. Quotes must be enabled, valid, at most 180 seconds old,
and not expired. Invalid conditions clear the threshold and select gas.

For the reported page snapshot (8.6°C outside, 20.7°C intake, 23°C target,
5°C supply margin and 5°C minimum rise), required rise is **7.3°C**. Surface
mode selects **34%**, COP approximately **4.014**, modeled rise **10.563°C**.
At gas 10.48 ct/kWh, assumed gas efficiency 100%, actual safety 5% and hysteresis
1 ct/kWh, break-even is **42.07 ct/kWh**, entry **38.96**, exit **39.96**.
At electricity 46.98 ct/kWh the decision is gas, with visible thresholds.
These estimates use the rounded snapshot, not live readings. No demand may
keep equipment off independently of the economic comparison.

At 8°C outside / 13°C intake / 23°C target plus 5°C supply margin, exact mode
still selects the measured 50% point; surface mode can select 45%. Candidates
retain modeled status, version, source IDs and capacity-source IDs. They never
acquire measured quality flags or enter measured history.

Future tests retain their raw reports and extend measured history normally.
They do not silently refit the saved runtime coefficients. A reviewed update
like this one is needed to regenerate and publish the next surface.

Validation includes all 3,195 saved cells and all accepted source observations,
full PHP regressions, both page DOM suites, automatic source commands, 5% quote
handling, authentication, CSRF, invalid conditions and gas fallback.
