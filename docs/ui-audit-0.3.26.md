# Form and protected-page audit — 0.3.26, build 36

Scope: LichtBlickElectricityPrice and HeatingControl forms, exposed actions,
protected pages, navigation and associated active code. The owner explicitly
selected retirement of the provisional model and daily threshold learner.

## Disposition

| Area | Decision / resulting location |
|---|---|
| Gas price, safety margin, price hysteresis | Kept together in LichtBlick **Gas / heat-pump switching settings**, expanded near the top; all three feed measured economics. |
| Postcode, tariff verification/correction and fetching/cache | Kept together in **Electricity tariff and price retrieval**. |
| Archive Control and confirmed no-consumption periods | Kept in shared history settings: utility charts still require them. |
| Provisional weather curve / reference threshold and capacity | Inputs and page panels removed; their old runtime path no longer selects heating. |
| Daily threshold learner / opposite-source reference days | Inputs, recalculation/reference actions and page panels removed; old callable entry points are inert compatibility notices. |
| 31 retired LichtBlick input properties | Retained internally for backup acceptance, excluded from active validation/calculation and removed from the form. |
| Old Heating Control model selector | Removed; automatic selection always uses the measured matrix. |
| Occupied/holiday exchanger minima and circuit meters | Previously retired settings remain hidden/inert; no new visible form entry or active price calculation uses them. |
| Heating mappings and temperature sensors | Kept, grouped by function; temperature/Boolean/integer selectors match their expected variable types. |
| At-home input | Still used as diagnostic context, grouped under advanced diagnostics. |
| Manual calibration JSON | Still functional, moved into an advanced calibration panel. |
| Existing status/IPSView outputs | Kept with their existing identities; optional output bindings remain configurable. |
| Reports, SMTP selection, previews and explicit test send | Kept; grouped actions preserve method names and argument counts. |
| Price, electricity, gas, water and total charts | Kept with their existing data routes, date/group/series controls and cursor tooltips. |

Retired names are defined by `RETIRED_PROPERTIES` and verified by `tests/form-audit.php`.
Defaults and saved input names of retained fields are unchanged. Obsolete child
status variables are hidden without deleting their object/archive identities.

## Page and link checks

- Heating Control shows actual selection separately from verified price supply;
  the matrix/price calculation is separate from the calibration test.
- LichtBlick shows the controller's measured comparison, rather than its old
  provisional recommendation/table; manual/automatic status is explicit.
- Cross-page links require the right module, explicit provider connection,
  enabled target, same passkey vault and a registered route owned by that target.
- Missing, mismatched, disabled or ambiguous targets have no guessed link.
- Portal/back/login links and protected chart/report/test-result downloads keep
  their origin, authentication and no-cache protections.
- Reading dashboards remains read only. Only the explicit Heating Control
  action endpoints can alter controls, with their existing authentication/CSRF gates.

## Validation

`tests/form-audit.php` verifies every visible named input against registered
properties, duplicate inputs, default ranges, public action methods/argument
counts, required retained settings and dynamic room/matrix form rows.
`tests/measured-links.php` verifies matched controller discovery, expiry/settings
consistency, read-only lookups, route ownership, vault isolation and ambiguity.
Existing price/cache, backup/migration, source handover, eight-step experiment,
room/recovery, utility histories, report and protected-page/DOM checks remain.

Live installed-route availability still requires applying the updated instances;
this audit tests the code and isolated fixtures without operating the house.
