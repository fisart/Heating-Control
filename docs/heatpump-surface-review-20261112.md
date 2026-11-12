# Surface review outcome — 12 November 2026

The previous exact two-input surface is superseded by smooth intake-aware v6 in **Heating-Control 0.3.65 build 76**, published on consolidated **main**. Prior heating, ventilation and calibration work is retained.

The revised surface is suitable as the reviewed provisional model: it includes actual intake temperature and preserves every raw observation. The old weather bend is not treated as evidence of a physical dip. Absolute COP is still provisional because airflow is assumed, electrical scopes differ and only six COP runs are available.

| Complete-run validation error | Previous | Revised |
|---|---:|---:|
| COP RMSE | 0.582 | 0.399 |
| Air-rise RMSE | 2.807°C | 1.867°C |
| Electrical-input RMSE | 1.175 kW | 0.613 kW |

Each complete run is held out; regularisation is chosen only on the remaining runs. Maximum individual COP error remains 1.284. This is internal cross-validation, not an independent new field trial.

All 51 stored rows and 47 eligible observations are unchanged. Native counter reconstruction matches all 16 reviewed retained October/November intervals. Shelly and independent pulse-meter whole-window totals agree within 0.6%; November's 100% retained mean is 8.030/8.061 kW. No electrical correction is applied. The 5.95 kW catalogue reference still needs nameplate/circuit reconciliation.

The protected 3D tab now offers mesh intake slices at 10, 15, 20 and 25°C. Markers retain their recorded intake; runtime uses actual continuous intake. Original exclusions and stability warnings remain. No generic defrost or cycling loss is fabricated.

## Verification

- Offline definition, validation predictions and numeric matrix reproduce byte for byte.
- PHP matches all four independent Python matrix slices and passes 117,864 surface, method-selection, threshold and control assertions, plus measured-economics checks.
- Heating transfer/fan, calibration, meter, recovery, source-selection, web-security and ventilation checks pass. The transfer fixture uses prices economical under the revised model; actuator logic is unchanged.
- Local PHP workflow sweep: 79 of 80 commands pass after the transfer-fixture update. The RoomClimate source-settings command fails on the unchanged baseline too: this minimal standalone PHP runtime lacks `ctype_digit`. RoomClimate source was not altered.
- Heating, cost, consumption and chart DOM interaction tests pass, including intake/metric selection, unchanged observations, lazy loading, keyboard tabs and read-only chart requests.
- Chromium was attempted. Local launch is blocked by socket permissions; the cloud browser disallows local-file URLs. Local browser rendering/mobile/CSP checks are not claimed as passed. The repository retains and extends the Chromium CI suite.
- Generated scientific figures were visually reviewed.

Update Heating-Control from **main**, apply HeatingControl and refresh the heating page. Existing COP method, custom matrices, automatic/manual mode and manual desired command are preserved. Publication does not update the running installation or start equipment.

See [full model and reproduction](heatpump-cop-surface.md), [validation predictions](data/heatpump-surface-validation.json) and [electrical evidence](data/heatpump-power-validation-summary-20261112.json).
