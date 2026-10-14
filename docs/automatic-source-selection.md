# Automatic gas / heat-pump switchover — 0.3.26

Heating Control is the sole source/power decision maker. LichtBlick supplies a
verified `PriceQuoteJSON` containing electricity price, gas price, safety margin,
price hysteresis, enable state and expiry. Provisional weather/reference-price
calculations and daily threshold learning are retired.

## Setup and controls

1. In LichtBlick, open **Electricity tariff and price retrieval**; configure
   postcode/correction, confirm the actual tariff and enable price supply.
2. In **Gas / heat-pump switching settings**, configure gas price in ct/kWh,
   safety margin (%) and price hysteresis (ct/kWh). Default margin is 25%.
3. In Heating Control, open **Automatic gas / heat-pump switchover** and select
   the LichtBlick instance. Its current ON/OFF state is shown in the form.
4. Enable/disable automatic operation through the form’s buttons, the child
   `AutomaticSourceSelection` Boolean, or the protected page’s switch.
5. Review the measured matrix, gas efficiency and supply margins in Heating
   Control. Hardware mappings, operating permissions and transfer timers remain
   separate and keep their existing saved values.

`HC_SetAutomaticSourceSelection(instanceID, true/false)` and
`RequestAction(switchID, true/false)` remain valid. Automatic off retains the last
selected source and uses manual desired power. Master/summer/night/cooling gates,
room demand, recovery, dry run and controller enable still apply. Restore never
enables hardware control or web access automatically.

## Decision and validity

The controller checks provider identity, schema, verification, expiry and issue
age (maximum 180 seconds). It chooses the highest estimated COP among stable
local points meeting the current fan/airflow and active room-target requirements.
Gas price × system COP / gas efficiency gives break-even electricity price.
Safety margin is applied once; price hysteresis supplies entry/exit separation.
Missing/unsupported measurements or invalid/expired prices use gas. There is no
alternative provisional model to select. See [calibration and temperature tests](heatpump-capacity-test.md).

The Heating Control page separates actual selected source/power from price supply
and measured thresholds. LichtBlick’s heating tab shows the same measured
comparison, the current automatic/manual status and selected air source. Cross-page
links appear only for a registered, enabled target using the same passkey vault.
Multiple controllers referring to one provider are reported as ambiguous rather
than choosing one silently. Reading either page does not operate hardware.

## Exact Berlin command mapping

All IDs and open/closed positions are configurable. Verify these defaults before
live use. This integration controls the air-heating branch; it never switches off
the entire gas boiler or directly commands radiator/DHW services.

| Operation | Commands, in order after transfer readiness |
|---|---|
| Activate heat pump | Gas-air pump **32875=false**; gas mixer **14348=100** (closed); heat/cool **51011=true** (heating); physical percentage **19207=selected measured percentage**; heat-pump enable **35931=true** |
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
