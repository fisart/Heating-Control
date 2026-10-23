# Expanded heat-pump calibration — 23 October 2026

Heating-Control **0.3.39, build 50** expands the existing matrix. Every original field in the 30 historical rows is preserved. Thirteen reviewed rows are appended; 39 counter-backed observations are eligible for the existing COP surface. Two additional thermal-only observations from the earliest interrupted run support independent capacity checks. All six archived runs are represented.

## What changed

- Added the eight 23 October power steps, with only evidenced joint-collapse windows removed.
- Added a reviewed copy of the historical morning 40% point, preserving its measured values; the old stability flags do not reject valid temperatures.
- Added clean derivatives for cold-night 80%, dedicated-meter 90%, and dedicated-meter 100%. The recovery bracket crosses into 100%, so its original row is retained with a link to the filtered replacement.
- Added the recorded, non-defrost part of the cold-night unfinished 90% step. No missing duration or power step is invented.
- The earliest interrupted run retains its complete 30% and partial 40% temperatures. Its original overlapping instantaneous meter signals cannot establish matched counter energy; these two observations support thermal capacity, without assigning a COP.

Historical exclusions remain visible as original records. Filtered derivatives have new IDs; the controller skips a superseded original. Custom measured matrices are preserved; clear the custom matrix to use the expanded default. Stored copies of the exact superseded 100% row retain its supersession annotation.

## Evidenced event windows

These are temperature patterns consistent with defrost, not confirmation from a reversing-valve/defrost status input. The same published detector requires simultaneous drops in air rise, outlet and exchanger temperature. The intake-source change is valid and is retained. Timestamp age is not grounds for rejecting a held, unchanged temperature. Replay compares continuously across power boundaries and matches the PHP detector event fields.

The bracket starts 60 seconds before the last healthy reference; recovery requires 120 seconds of stable recovery, followed by 120 seconds of post-recovery exclusion. No complete power step is dropped when a usable interval remains. Times below are Europe/Berlin.

| Run | Exclusion start | Drop detected | Exclusion end | Seconds |
| --- | --- | --- | --- | ---: |
| 20261014-225748-bbb31f50d001 | 15 Oct 02:45:41 | 15 Oct 02:47:53 | 15 Oct 02:59:11 | 810 |
| 20261015-211706-139c48ad9dc5 | 16 Oct 00:58:46 | 16 Oct 01:00:51 | 16 Oct 01:13:45 | 899 |
| 20261023-035751-5129d11e966f | 23 Oct 07:03:19 | 23 Oct 07:05:39 | 23 Oct 07:16:38 | 799 |
| 20261023-035751-5129d11e966f | 23 Oct 07:50:23 | 23 Oct 07:52:19 | 23 Oct 08:03:18 | 775 |

## Latest run: retained measurements

Heat and electricity use exactly the same retained intervals. Air heat remains an estimate based on 2,000 m³/h and fan command 90%. Dedicated counter 22365 (kWh), power 28681, export counter 57639; no household or standby subtraction.

| Power | Outside °C | Inlet °C | Outlet °C | Retained s | Excluded s | Electric kW | Estimated COP |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 30% | 3.60 | 10.99 | 19.23 | 901 | 0 | 1.272 | 4.339 |
| 40% | 3.60 | 9.52 | 22.25 | 901 | 0 | 2.132 | 4.001 |
| 50% | 3.60 | 9.52 | 25.05 | 901 | 0 | 3.143 | 3.311 |
| 60% | 3.59 | 9.58 | 26.81 | 795 | 108 | 3.896 | 2.963 |
| 70% | 3.78 | 9.57 | 29.97 | 210 | 691 | 4.677 | 2.923 |
| 80% | 3.61 | 9.73 | 30.82 | 901 | 0 | 5.424 | 2.605 |
| 90% | 3.34 | 9.62 | 26.07 | 901 | 0 | 5.048 | 2.183 |
| 100% | 3.28 | 9.78 | 34.95 | 907 | 775 | 7.788 | 2.165 |

## Expanded surface and source selection

The established thin-plate radial basis plus affine trend and **zero smoothing** are retained. The expanded surface is refitted from the original accepted observations and the reviewed additions. This changes calculated estimates between observations; it does not recalculate or replace historical measured values.

The domain expands to **3.25–16°C outdoors**, at **30–100% power**. The reference grid has **52 × 71 = 3,692 cells**: 3,227 interpolation and 465 explicitly labelled extrapolation cells. Runtime still requires independent observed-capacity support, sufficient air rise/supply, tested fan command, and a valid current price quote. Rectangular grid coverage alone never authorizes the heat pump.

The model uses 23 household-counter points and 16 dedicated-meter points with their original scope labels. Whole-run holdout RMSE is approximately **0.680 COP** and **3.697°C air rise**. These diagnostics do not establish seasonal efficiency or a confidence interval; the model remains provisional. It models normal heating after removing event brackets, not complete-defrost-cycle consumption.

At 3.6°C outside, 9.6°C actual inlet, room target 23°C, supply margin 5°C and minimum rise 5°C, the expanded surface selects a qualified **64%** candidate, estimated COP **2.956**. At gas 10.48 ct/kWh, assumed 100% gas efficiency, 5% safety margin and 1 ct hysteresis, entry is below **28.43 ct/kWh** and exit is **29.43 ct/kWh**. These are examples; live tariff, margin, hysteresis and control settings remain unchanged.

## Data and reproduction

- [Expanded measured matrix](data/heatpump-calibration.json): 43 stored rows.
- [Unmodified prior matrix](data/heatpump-calibration-20261016.json): the 30 historical records.
- [Reviewed additions and source checksums](data/heatpump-calibration-additions-20261023.json).
- [Surface observations](data/heatpump-cop-surface-observations.json), [coefficients](data/heatpump-cop-surface.json) and [dense reference matrix](data/heatpump-surface-matrix.json).

Original raw files remain in the private archive and are never rewritten. The offline replay tool `libs/tools/replay-heatpump-raw.py` reconstructs candidate intervals from those originals; `libs/tools/expand-heatpump-surface.py` appends reviewed additions, asserts every historical field is unchanged, and verifies its coefficients against SciPy. The prior surface observations can be retrieved from Heating-Control commit `c83951e13e13934021b73c7fc2d0a26e6944b992`.

Future tests keep their existing safety and automatic-learning checks; this is a reviewed expansion of the archived calibration, not a global removal of future fault checks. Updating this library does not start a test, enable automatic control, change the chosen COP method or alter live settings.
