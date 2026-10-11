# Automatic air-heating source selection — 0.3.5

The child **AutomaticSourceSelection** Boolean (with action) is an external switch
below HeatingControl and appears on its protected HTML page. It defaults to off.
It can also be changed with `RequestAction(switchID, true/false)` or
`HC_SetAutomaticSourceSelection(heatingInstanceID, true/false)`.

Select the updated **LichtBlickElectricityPrice** instance in HeatingControl's form.
Confirm that its current quote matches the customer tariff, then enable tariff
confirmation and configure the threshold, safety margin and operating capacity.
Enabling automatic source selection does not enable a disabled/DryRun controller.
Configuration restore disables the controller, web access and automatic switch.

## Source and capacity

LichtBlick publishes **RecommendedHeatPumpPowerPercent** and a final coherent
**RecommendationJSON** snapshot. The percentage comes from the editable
**ReferenceCapacityPercent** property when the weather model is off (default 50%).
With **WeatherModelEnabled** on, it comes from the editable outside-temperature
curve, initially 65% at −7°C, 50% at +2°C and 35% at +7°C/+12°C, with interpolation
and a default 5% command step. These are provisional settings, not a measured
optimum. Gas/unavailable recommendations carry zero power. The snapshot includes
an issue timestamp and the earliest price/cache/outside-sensor expiry.
**WeatherHeatPumpPowerPercent** retains the valid weather candidate while gas is
preferred. See [model and protected price dashboard](lichtblick-electricity-price.md).

HeatingControl validates provider identity, snapshot schema, strict validity,
issue age no greater than 180 seconds, expiry and integer HP capacity 1–100.
Missing, unconfirmed, stale, malformed or expired recommendations select gas only
when existing heating permissions and room demand allow heating. Cooling and
applicable night shutdown remain authoritative; master/summer retain existing
bypass semantics. No room demand stops the sources, with existing residual recovery.

Automatic selection updates **OperatingModeID** (default **41085**) to the
configured heat-pump/gas mode values (defaults **1 / 2**), including gas fallback.
This selector represents the intended source during transfer and no-demand
periods; it is not physical running feedback. **HeatPumpDesiredPowerID** (default
**59800**) retains the manual desired percentage. Turning automatic off keeps the
last source selector value and uses that manual percentage through the protected
handover. The previous manual source is not restored.

The mode is written only when changed, after equipment-command dispatch, as a
direct integer state update. Its custom/action script is not invoked, so legacy
source actions cannot bypass the module's transfer logic. Disabled, DryRun,
master/summer bypass, night shutdown and cooling do not write the selector.
Cooling retains its existing veto, including when automatic selection is on.
The page makes manual source/power read-only while automatic selection is on.
Cooling can still veto heating through the external manual mode; to select it on
the page, turn automatic selection off first.

## Heating dashboard and setup — 0.3.5

1. Update **Heating-Control** to **0.3.5, build 15**. Open the HeatingControl instance form and select the actual **LichtBlick electricity-price instance** under **Automatic air-heating source selection**, then apply. A missing provider remains a gas fallback, not a valid economic comparison.
2. Check the provider tariff confirmation and current RecommendationJSON. Configure its weather curve or fixed power, threshold and margin in the LichtBlick form.
3. Use the actionable **Automatic heating source selection (LichtBlick)** / **AutomaticSourceSelection** Boolean directly below HeatingControl, or the dedicated **Turn automatic on / off** button near the top of the protected heating dashboard. It defaults to off; changing it does not bypass Enabled/DryRun.

The dedicated HTML section displays automatic/manual state, the configured price instance, the provider’s valid recommended source/power, current price and tariff availability, safe/entry thresholds, expiry and reason. It also shows the controller’s last selected source/power, current heat-pump enable/power and gas-air-pump command values, and source-transfer status. These are command variables, not independent physical feedback. If the provider web page is enabled, a local link opens its protected price/model dashboard.

The display and controller share the same validation of the provider’s **RecommendationJSON**, identified from the selected instance. This final publication contains **RecommendedSource** and **RecommendedHeatPumpPowerPercent** together; separate display readings never control heating. Provider VM_UPDATE messages and the heating timer trigger evaluation.

A new valid percentage while the selected heat pump is running updates its power command without initiating a gas/heat-pump transfer or restarting the heat pump. A source change follows the existing stop, mixer and off-time checks. Gas recommendations carry zero heat-pump power. Expired, missing or malformed snapshots cause automatic gas fallback, still subject to heating demand/permissions and transfer delays. The safety margin is already applied in LichtBlick; HeatingControl does not apply it again.

Automatic off uses the current source selector and retained manual desired percentage, through the same protected handover when changing source. Manual source and desired-power fields are read-only on the page while automatic is on. No demand or shutdown can leave a valid provider HP recommendation alongside a controller selection of Off; this is intentional. Reading the dashboard never switches equipment or changes provider data.

## Exact Berlin command mapping

All IDs and open/closed positions are configurable. Verify these defaults before
live use. This integration controls the air-heating branch; it never switches off
the entire gas boiler or directly commands radiator/DHW services.

| Operation | Commands, in order after transfer readiness |
|---|---|
| Activate heat pump | Gas-air pump **32875=false**; gas mixer **14348=100** (closed); heat/cool **51011=true** (heating); physical percentage **19207=provider percentage**; heat-pump enable **35931=true** |
| Activate gas-air heating | Heat-pump enable **35931=false**; percentage **19207=0**; mixer **14348=0** (open); gas-air pump **32875=true**; flow command **14533=value from 46820** |
| Stop sources for transfer | **35931=false**, **19207=0**, **14348=100**, **32875=false**; fan **46921=false**, fan speed **29718=0** |

Before changing from an active source, both air sources are stopped. The
**SourceTransferDelaySeconds** pause defaults to 120 seconds and starts only after
both enable/pump command variables read off and the mixer command reads closed.
A heat-pump start additionally needs **HeatPumpMinimumOffSeconds**, default 300
seconds, from the observed off command. Unknown off duration, restart or bypass
causes conservative reobservation. A brief loss of room demand cannot erase the
transfer pause. These times are configurable implementation defaults, not
manufacturer-specific certified timings.

These are **command-state acknowledgments**, not compressor-running or actual
valve-position feedback. If a stop/closed command does not update, the replacement
source does not start. Equipment defrost, compressor ramping and internal limits
remain controlled by the heat pump. Manufacturer controller documentation describes
staged ramp delays; the software's minimum off timer is not a substitute for them.

When a source is ready, existing fan logic starts the fan only when its temperature
exceeds incoming air by the configured ResidualDeltaID (**28538**). Fan speed comes
from **48646**. Demand opens the relevant room flaps. The existing gas flow target
may be shared with other boiler services and still needs installation verification.

## Outputs and validation

HeatingControl publishes **EffectiveSource** (0 off, 1 heat pump, 2 gas),
**SelectedHeatPumpPower**, **SourceSelectionStatus** and **SourceTransferStatus**.
**LearningSourceMode** retains the selected heating mode (1/2) through normal
no-demand periods and is zero for cooling/shutdown/bypass/disabled control. If
using the daily learner with automatic selection, enable Archive logging on this
child and set LichtBlick **SourceModeID** to its ID. Do not leave it pointing at
**41085**: it now mirrors the intended source, but lacks the learner's shutdown/bypass state. The learner also rejects more
than two minutes of opposite air-source activity, guarding incorrectly mapped or
mixed-source histories. Initial daily learning remains disabled until configured.

A selected source is an intended choice, not evidence that the equipment is
physically running; inspect device command variables and measured response too.

Fixture tests check default manual control, snapshot validation/fallback, stop
acknowledgment, delayed starts, command order, restarts, demand interruption,
shutdown priority, source-selector mirroring (including configurable mode values),
DryRun, legacy-action bypass, unchanged-value suppression, handback, configuration restore and authenticated web controls.
Existing heating/recovery, archive/security and DOM checks also run. No live
heating equipment was operated during implementation.
