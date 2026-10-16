# Selectable COP method — 0.3.34, build 44

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
| Calculated COP surface | Reviewed v2 heat/electric interpolation, evaluated at actual outdoor temperature and 1% power steps from 30–100% | 5–16°C domain; matching modeled and observed reference inlet; observed within-run capacity brackets; sufficient modeled AND observed supply |

Both methods use the current verified LichtBlick quote and its actual safety
margin and hysteresis, without substituting factory defaults:

    break-even = gas price × selected COP / gas efficiency
    heat-pump exit ceiling = break-even × (1 − safety margin / 100)
    heat-pump entry = exit ceiling − price hysteresis

For a provider configured at 5%, the multiplier is 0.95, applied once. At the
recorded dedicated 50% point, gas 10.48 ct/kWh, 100% efficiency and 2 ct/kWh
hysteresis give entry below 29.93 ct/kWh and exit at 31.93 ct/kWh. These are
examples; module updates do not change live settings. Invalid/expired quotes
clear thresholds and select gas. Unsupported surface conditions select gas with
an explanation, without silently switching to measured mode.

## Mathematical surface

Version **provisional-heatpump-surface-2026-10-16-v2** reproduces the updated saved
analysis surface. Coefficients and diagnostics:
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

Air origin alone is not a separate model selector: calculated heat uses outlet
minus inlet. Actual inlet conditions remain relevant. The fitted surface pools
roughly 12.7–25.2°C inlet conditions and cannot independently identify a weather
or inlet-temperature effect from these four runs.

A candidate must match the surface's descriptive reference inlet within the
existing configured tolerance (maximum 3°C). Independently, the controller
interpolates full-period air rise and reference inlet **within each accepted
run's measured power bracket**, then between weather runs. It never extends a
run beyond its measured power range. Outside the available weather bracket,
only a nearest endpoint within the same configured tolerance is permitted.
The current inlet must match that observed-capacity reference too. Both the
modeled and observed-capacity rises must meet minimum rise and current room
target plus the supply margin. Fan command 90 (full physical fan speed),
2,000 m³/h airflow basis and excluded master-bedroom flap 40259 must match.

The rectangular analysis grid has 2,632 cells inside the accepted temperature/
power hull and 563 extrapolation cells. Runtime snapshots label that geometry;
extrapolation alone cannot bypass the independent capacity/intake checks.
Colder-inlet points can now qualify under the same tolerance when supported;
there is no unconditional holiday override.

For example, at 8°C outside / 13°C inlet / 23°C target plus 5°C supply margin,
exact mode selects the measured 50% point. Surface mode can select **45%**, whose
modeled and observed capacity both meet the requirement. Candidates are marked
`modeled: true`, `copMethod: surface`, version, source point IDs and separate
capacity-source IDs. They never acquire measured quality flags or enter history.

Future tests retain their raw reports and extend measured history normally.
They do not silently refit the saved runtime coefficients. A reviewed update
like this one is needed to regenerate and publish the next surface.

Validation includes all 3,195 saved cells and all accepted source observations,
full PHP regressions, both page DOM suites, automatic source commands, 5% quote
handling, authentication, CSRF, invalid conditions and gas fallback.
