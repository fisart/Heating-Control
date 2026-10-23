# COP surface — expanded calibration, 0.3.39 build 50

The existing measured matrix is expanded, preserving every original measured field in its 30 historical rows. Thirteen reviewed rows bring the stored total to 43; 39 counter-backed normal-heating observations fit the surface. Two earlier thermal-only observations contribute independent capacity evidence. See the [new tables, event windows and expansion audit](heatpump-calibration-20261023.md).

Select **Measured COP points** or **Calculated COP surface** in the instance form or protected page. Existing method selection, custom matrix, automatic/manual mode, desired power and price-provider settings are preserved. An empty custom measured matrix uses the expanded bundled default. No calibration or equipment action starts on update.

| Method | Data and support |
| --- | --- |
| Measured COP points | Exact retained measurements; current outside AND inlet tolerance, fan and supply checks. Original exclusions stay visible; reviewed additions and filtered replacements are selectable. |
| Calculated COP surface | Established thin-plate/affine zero-smoothing fit, now expanded to 39 observations. Outdoor domain 3.25–16°C, powers 30–100% at 1% steps. Both modeled and independently observed rise must qualify. |

## Unchanged mathematical method

Version **provisional-heatpump-surface-2026-10-23-v4** uses the same normalized coordinates and basis:

    x = (outside − 10.5) / 5.5
    y = (power − 65) / 35
    phi(distance) = distance² × ln(distance); phi(0) = 0
    F(x,y) = sum(weight_i × phi(distance_i)) + a0 + a1×x + a2×y
    estimated heat kW = 0.000335 × 2000 × interpolated air rise
    estimated COP = estimated heat kW / interpolated electrical kW

Full-period air rise, electrical input and descriptive reference inlet are fitted separately with the same centers. Zero smoothing preserves fitted observations exactly. Refitting an expanded set can change calculated values throughout the grid; it does not alter historical measurements. Coefficients and SHA-256 input provenance are in [the definition](data/heatpump-cop-surface.json). The [3,692-cell reference matrix](data/heatpump-surface-matrix.json) contains 52 temperatures at 0.25°C spacing and 71 power levels. Whole-run holdout RMSE is 0.680 COP and 3.697°C air rise; the model remains provisional.

## Capacity and price safeguards

Actual inlet determines required rise:

    required rise = max(configured minimum rise, room target + supply margin − actual inlet)

Both modeled and observed-capacity rise must meet this requirement. Air origin does not form a separate categorical model; actual outlet minus inlet determines measured air heat. Capacity is interpolated within each recorded run's power bracket, then across weather. No unmeasured high-power extension of a run is allowed. A nearest weather endpoint must meet the configured tolerance, at most 3°C. Thermal-only early readings supply capacity evidence without invented electricity or COP. Fan command 90 remains required. Configured test airflow and excluded-flap matching remain calibration metadata rather than normal-operation exclusions. The saved airflow basis is 2,000 m³/h.

The current verified LichtBlick quote supplies the actual gas price, safety margin and hysteresis:

    break-even = gas price × selected COP / gas efficiency
    exit ceiling = break-even × (1 − safety margin / 100)
    entry ceiling = exit ceiling − hysteresis

Safety margin is applied once. Invalid/expired quotes, unsupported temperature/capacity, nonfinite/nonphysical estimates or other existing equipment safeguards select gas or prevent heating. Surface mode never silently switches to measured mode. Room demand and break-before-make controls remain independent of the economic comparison.

At 8.6°C outside, 20.7°C inlet, 23°C target, 5°C supply margin and 5°C minimum rise, the expanded fit selects **35%**, estimated COP **4.024**. At gas 10.48 ct/kWh, 100% assumed efficiency, 5% safety and 1 ct hysteresis, break-even is **42.17**, entry **39.06**, exit **40.06 ct/kWh**. At 46.98 ct/kWh, the source decision is gas. This example is not a live reading.

Historical household increments and dedicated heat-pump circuit measurements retain distinct provenance; modeled inputs are labelled as a reviewed mixed fit. Dedicated-circuit COP excludes equipment outside that circuit. Airflow and mixed scopes remain limitations. This event-filtered surface does not measure full-cycle or seasonal COP.

Future tests append raw measurements and measured history normally. They do not silently refit the saved surface. This reviewed update preserves historical rows and adds evidence; the old records remain available for comparison.
