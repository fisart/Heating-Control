# Automatic air-heating source selection — 0.3.1

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
**ReferenceCapacityPercent** property (form caption: Automatic heat-pump power /
learning reference). It is currently a fixed configured percentage, default 50%,
not a weather-dependent or learned optimum. Gas/unavailable recommendations carry
zero power. The snapshot includes an issue timestamp and actual price/cache expiry.

HeatingControl validates provider identity, snapshot schema, strict validity,
issue age no greater than 180 seconds, expiry and integer HP capacity 1–100.
Missing, unconfirmed, stale, malformed or expired recommendations select gas only
when existing heating permissions and room demand allow heating. Cooling and
applicable night shutdown remain authoritative; master/summer retain existing
bypass semantics. No room demand stops the sources, with existing residual recovery.

The manual source **41085** and desired power **59800** remain stored separately.
Turning automatic off returns to those values through the protected handover.
The page makes manual source/power read-only while automatic selection is on.
Cooling can still veto heating through the external manual mode; to select it on
the page, turn automatic selection off first.

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
manual **41085**, which is deliberately preserved. The learner also rejects more
than two minutes of opposite air-source activity, guarding incorrectly mapped or
mixed-source histories. Initial daily learning remains disabled until configured.

A selected source is an intended choice, not evidence that the equipment is
physically running; inspect device command variables and measured response too.

Fixture tests check default manual control, snapshot validation/fallback, stop
acknowledgment, delayed starts, command order, restarts, demand interruption,
shutdown priority, handback, configuration restore and authenticated web controls.
Existing heating/recovery, archive/security and DOM checks also run. No live
heating equipment was operated during implementation.
