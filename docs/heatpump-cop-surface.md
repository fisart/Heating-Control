# Selectable COP method — 0.3.33, build 43

After the library update, apply Heating Control. Under **COP method, operating
matrix and electricity break-even price**, select **Measured COP points** or
**Calculated COP surface**. The protected heating page has the same **COP method
for price threshold** selector and **Apply COP method** button. The selection
persists in configuration backups. The automatic-source switch stays separate;
selecting a method never enables automatic mode or changes manual desired power.
Active/paused tests lock the page selector. The page respects its existing
passkey, CSRF and read-only protections.

| Method | COP used for the threshold | Support |
| --- | --- | --- |
| Measured points (default) | Original recorded COP from the selected qualified point, without smoothing | Current matching tolerance on both outside and intake temperature; existing fan/airflow/flap and supply requirements |
| Calculated COP surface | The exact saved cubic spline + temperature correction, evaluated at the actual outdoor temperature and integer powers 30–100% in 1% steps | 5–16°C outdoor range; tested fan command 90%, assumed airflow 2,000 m³/h and excluded flap 40259; compatible intake and sufficient supply air |

Both methods choose the highest eligible COP and use the same verified current
LichtBlick price, gas tariff, gas efficiency, safety margin and price hysteresis:

    break-even = gas price × selected COP / gas efficiency
    gas exit ceiling = break-even × (1 − safety margin / 100)
    heat-pump entry = gas exit ceiling − price hysteresis

No additional percentage margin is silently applied to surface mode. Invalid or
expired price quotes clear thresholds and choose gas. Surface mode outside its
support also chooses gas, with an explicit reason; it does not silently switch
to exact-point mode. To use recorded points beyond the surface's range, select
Measured points explicitly.

## Surface definition and capacity qualification

The bundled surface is **provisional-cop-surface-2026-10-15-v1**, exactly the fit
created from the three October 14–15 test runs (20 accepted observations). Its
coefficients, reference basis, fit and withheld-run errors are recorded in
[data/heatpump-cop-surface.json](data/heatpump-cop-surface.json). The exact source
observations and quality flags are preserved in
[data/heatpump-cop-surface-observations.json](data/heatpump-cop-surface-observations.json),
whose SHA-256 is checked before evaluating supported candidates. Electrical input
for this fit was **house-counter-minus-pretest-baseline**. Installing the dedicated
meter does not remeasure old tests. Later dedicated-meter points remain in the
measured matrix and do **not** automatically change these frozen coefficients.
A reviewed refit is required to update the surface with a consistent meter basis.

The COP equation is:

    COP(T,P) = f(P) + ((T−12)/10) × [b0(1−x)² + 2b1x(1−x) + b2x²]
    x = (P−30)/70

Here f(P) is the saved natural cubic power spline. It has a broad efficiency peak
near 48–50%; the positive outdoor-temperature response weakens with power. These
are fitted modeling assumptions. Withholding each whole test run gives RMSE
about **0.214 COP** (cold-night holdout about 0.344). Shared airflow and meter
systematic errors are not included; these are not certified or seasonal COPs.

COP alone does not prove that supply air is warm enough. For each candidate
power, the controller first interpolates full-period air rise and reference
intake **within each accepted test run**, then interpolates these quantities
between runs at the actual outside temperature. It never extends a run beyond
its tested power range (the cold run ends at 70%). If weather is outside that
candidate's measured capacity bracket, the existing temperature tolerance allows
only the nearest endpoint; no further capacity extrapolation is allowed. The
current intake must match the resulting reference intake within the configured
tolerance. Finally, outgoing air must exceed the active room target by the
configured margin and air rise must meet the existing minimum.

This preserves the air-supply requirement while allowing modeled COP and
intermediate powers between the discrete test points. A 10°C intake holiday
regime remains unsupported by the warm-intake tests. Surface candidates include
`modeled: true`, `copMethod: surface`, surface version and capacity source point
IDs. They never enter the measured matrix or acquire measured stability flags.

The heating page and LichtBlick comparison identify the selected method. An old
price snapshot from the other method is hidden until a fresh evaluation arrives.
Manual controls, source-transfer/off delays, reboot guard and test recording
continue to operate normally.

Validation: `php tests/heatpump-surface.php`, the full PHP workflow, and both
page DOM suites. The runtime surface is also compared with all 3,195 cells of the
previously saved dense matrix (four-decimal matrix precision).
