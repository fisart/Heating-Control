# Verbrauch & Kosten — 0.3.15, build 25

The protected page has five tabs: **Strom**, **Gas**, **Wasser**, **Total consumption**, **Heizung**.
Strom shows electricity prices, consumption and invoice reconciliation. Heizung
contains the gas/heat-pump threshold, live comparison price, recommendation,
safety margin, hysteresis, outside-temperature/COP/power model, learning status
and diagnostics. It updates with the state refresh every 60 seconds, retaining
the selected tab. Gas shows reviewed observations and supplier invoices. Wasser reads separately configured house, garden and well water counters with
dated charges. Unconnected sources remain unknown; no sample readings appear.

The module class `LichtBlickElectricityPrice`, prefix `LBEP`, module ID and existing
`/hook/lichtblick_<instanceID>` URL stay compatible. Every gas request uses the
same SecretsManager session and origin checks as electricity. No new passkeys,
external client requests, commands or heating-control parameters are introduced.

## Connect an existing reviewed gas import

1. Update Heating-Control to **0.3.15, build 25**.
2. In **Reviewed gas consumption and invoice costs**, select the verified import
   metadata, cleaned cumulative m³, observed daily m³, sparse reference m³,
   sparse reference kWh and daily quality variables.
3. Select the original counter for later periods, using its **verified** scale
   to m³. This setting is independent of the gas inputs used by threshold learning.
4. Enable `GasHistoryEnabled`, Apply and open the existing protected page.
5. In AlarmPortal, set the existing `<instanceID>:lichtblick` PageOverrides row
   Name to **Verbrauch & Kosten**. Keep its Cluster and Hidden settings. A
   site-specific connection script can perform these selections and a readback
   check without importing or rewriting archive history.

The verified metadata variable must contain `Symcon.GasReviewedSnapshot.v1`,
`import_status=verified`, its archive and target IDs, inclusive snapshot cutoff,
invoice periods and quality meanings. The reader rejects a different import,
missing/unlogged targets, inconsistent daily/cumulative values and changed
invoice references. Pre-gas configuration backups remain compatible and default
the gas connection to disabled. Gas history settings do not reset the heating
learning identity.

## What the gas values mean

- `CleanedCounterM3` accumulates observed increases across explicitly reviewed
  reset segments. `ObservedDailyM3` contains only days with source records.
  Imported day endpoints are allocation timestamps, not original reading times.
- A day with no archive records stays **unknown**, not measured zero. Long
  intervals between counter changes can also indicate inactivity; they do not
  prove an outage. Known subtotals are shown separately from incomplete buckets.
- Sparse reference counters retain only invoice-period endpoints. They must
  **not** be interpolated or aggregated into invented daily reference consumption.
  Supplier readings have no known clock time.
- The invoice table shows supplier m³/kWh, reviewed observed m³ and the unallocated
  volume difference. It preserves invoice dates and totals; it neither shifts
  readings nor spreads residuals across days.
- Gross supply cost, December aid and price-brake credits remain separate. Costs
  after aid are their difference. Installments and refunds are not consumption
  costs. Totals include only **whole invoice periods** within the selection; a
  partially overlapping invoice is shown as a whole reference row with a partial
  selection label, and contributes no prorated cost.
- With `GasHistoryTariffs` connected, daily estimated kWh use observed m³ times
  the dated invoice conversion factor (calorific value times condition factor).
  Independently calculated gross costs use the dated gross unit rate plus the
  dated basic charge. Later periods can carry forward the last factor, explicitly
  marked provisional. Missing prices or consumption leave an incomplete subtotal.
- The tariff history records inclusive dates, gross ct/kWh, annual gross basic
  charge, its 365/366-day convention, kWh/m³, conversion status and both sources.
  Rate changes split at Berlin midnight. DST days receive one daily basic charge;
  a partial snapshot day and its later segment share that day's charge.
- The current-year selection shows its own calculated subtotal even when no
  whole invoice falls inside the year. Invoice residuals and assistance remain
  separate; no residual is used to adjust consumption or tariff rates.

## Confirmed periods without consumption

`ConsumptionZeroPeriods` stores inclusive full calendar dates, affected utilities
(`electricity`, `gas`, `water`) and the confirmation source. Only observations
entirely inside a confirmed interval are treated as zero. Partial boundary days
remain unresolved unless their times are known. Original archives and supplier
invoice references remain unchanged; conflicting observations/allocations are
flagged. Electricity reference allocations within those periods are overridden
at read time and their cost difference remains in the invoice reconciliation.
Gas variable charges are zero; documented basic charges remain payable in the
calculation. Water rules can be retained before its history reader is connected.
These display settings do not alter the heating learning identity.

Archive logging is enabled so Symcon permits archive access. There is no writer
updating the prepared snapshot. Enabling logging can add an asynchronous current
terminal-value marker; reads always stop at the metadata cutoff so that marker
is not interpreted as a later measurement or supplier reading.

## Later readings and quality

After the snapshot cutoff, the page reads the original archived counter directly.
Later events are marked **unreviewed** and do not change the prepared archives.
No invoice reference is extended beyond its period. Negative changes leave that
day unresolved; their positive rebounds are not reported as cleaned consumption.
Missing later events remain unknown. Snapshot and later observations on the same
day merge once, retaining their quality flags and partial coverage.

The endpoint is `GET ?view=gas-history&from=YYYY-MM-DD&to=YYYY-MM-DD&resolution=auto`.
`to` is an inclusive Berlin calendar date; supported resolutions are `auto`,
`daily`, `monthly`, `yearly`. The read-only console API is
`LBEP_GetGasHistory(instanceID, from, through, resolution)`.
Ranges are limited to 2,192 days and cannot start before the reviewed import.
Gas tab switching, keyboard navigation, refresh and session-expiry handling are
covered by the DOM tests; snapshot markers, DST, missing days, aid, invoice
selection, archive integrity and later counter resets are covered by PHP tests.

## Water streams and original-script charges — 0.3.15

`WaterHistoryEnabled` enables archive reads. `WaterHistorySources` is an object
with `house`, `garden`, `well` entries: positive distinct `counterID`, positive
`scaleM3` and a nonempty provenance `source`. A missing or zero ID remains
unconnected. Raw cumulative counter increases are assigned to their recorded
Berlin calendar day. A missing initial baseline or counter reset remains
incomplete. Quiet held counters contribute zero. Original archives are never
rewritten. This reader does not reproduce the old script's daily extrema/reset
heuristics or sum daily counter averages.

If `WaterHistoryHouseIncludesGarden` is enabled, the house volume is main meter
minus garden submeter, once. If either volume is unavailable, or garden exceeds
the main meter, house-only volume remains unknown. Well water always costs zero.
Confirmed `ConsumptionZeroPeriods` also apply to water; basic fees remain.

`WaterHistoryTariffs` accepts a dated list per `house`/`garden` stream. Each row
contains `stream`, `from`, inclusive `through` (null for open-ended), `source`,
`drinkingNetEuroM3`, `vatPercent`, `drinkingBasicNetEuroDay`,
`sewageGrossEuroM3`, `sewageBasicGrossEuroDay`, `rainAreaPerDay`, `rainGrossRate`.
The recovered original formula is:

```
variable = m3 * (drinkingNetEuroM3 * (1 + vatPercent/100) + sewageGrossEuroM3)
fixed/day = drinkingBasicNetEuroDay * (1 + vatPercent/100)
          + sewageBasicGrossEuroDay + rainAreaPerDay * rainGrossRate
```

Shared connection basic/rainwater fees must be assigned once to the house
stream. Separate documented meter fees can be entered for garden. Garden
sewage exemptions, tariff dates and meter scales must come from actual source
settings/documents; no current tariff is backfilled into unknown years. On DST
days one daily fee applies; today's partial day is charged proportionally.
Old water-related display settings do not reset the heating learning identity.

Read-only APIs: `LBEP_GetWaterHistory(instanceID,from,through,resolution)` and
`GET ?view=water-history&from=YYYY-MM-DD&to=YYYY-MM-DD&resolution=auto`.

## Total consumption — 0.3.15

The new tab selects a common inclusive date range and daily/monthly/yearly
buckets. It lists electricity, gas, house water, garden water and well water,
with known quantities, costs and incomplete-day counts. A stacked chart switches
between costs (EUR), energy (electricity plus converted gas kWh) and water (m3).
Negative electricity costs stack below zero. Unknown source amounts remain
blank, while available sources still render. A faded stack marks incomplete
coverage or provisional rates/conversion; no absent source is treated as measured zero. Well cost is zero even
when its volume has not yet been connected.

Costs use electricity's dated reference-curve tariff costs plus independently
calculated gas and water supply costs. Invoice residuals and assistance remain
in their source tabs, outside the selected-period cost stack. Gas kWh remain an
estimate from billing conversion factors, including provisional carried-forward
factors. Water and energy are never added as one physical quantity. Coverage
starts independently for each source; earlier periods or today's unfinished
meter intervals keep the total incomplete. All endpoints use the existing
SecretsManager session and origin checks. Refresh every 60 seconds retains the
selected tab and custom dates, and requests only the active history view.

API: `LBEP_GetTotalConsumption(instanceID,from,through,resolution)` / read-only
`GET ?view=total-consumption&from=YYYY-MM-DD&to=YYYY-MM-DD&resolution=auto`.
Ranges are bounded to 2,192 days. Invalid dates are rejected; missing connections
are reported per stream without hiding the other streams.

### Manual garden readings and invoices

Garden water need not have an electronic meter. The instance form has an
editable `WaterHistoryGardenPeriods` table with inclusive `from`/`through`,
consumption `m3` and the reading/invoice `source`. Use the consumed volume for
that interval, or the difference of its two meter readings. Intervals must be
ordered and must not overlap. Do not configure both manual periods and an
electronic garden meter. Archive history is unchanged.

A full manual period contributes its garden volume and, where the main meter
covers that same complete period without unresolved resets, main-minus-garden
house volume. A partial selection displays the whole reference row but excludes
its volume and variable cost from the totals. No daily volume is interpolated.
Variable costs can be computed only when a documented unit rate covers the full
period and is constant. If a unit price changes inside a manual period, enter
actual subdivided readings/periods; no average rate or uniform split is assumed.
Fixed charges are still calculated by calendar day, once.

`selected` groups the entire selected interval into one stacked bar, including
its fully selected manual references. For finer daily/monthly/yearly buckets,
a manual amount is placed only when its whole period fits one bucket. Other
known manual amounts remain in the total/reference table and are explicitly
shown as unallocated to the chart. The chart never relocates the volume to its
reading date or invents daily consumption. Whole-period water invoice
references and current tariff documents are needed to connect private rates;
a historic invoice is not proof that its rate is valid today.

### Invoice references and captured main-meter totals

`WaterHistoryInvoicePeriods` preserves supplier main m3, sewage m3, billed garden
**deduction** m3, gross supply cost, billed gross cost and source per invoice.
An invoice's zero deduction does not prove zero actual garden use. Actual garden
consumption comes only from manual readings or explicit supplier private-meter
records. Whole invoice rows stay visible for overlapping selections; their
amounts are never prorated into calculated charts. Supplier adjustments remain
separate from the dated supply calculation.

The captured main meter already includes garden use. Combined physical water
consumption therefore uses main plus well once, even while the house/garden
split is unknown. The summary shows both captured main m3 and the known split
subtotal. Cost/volume stacks continue to show only quantities that can actually
be assigned to their stream, with incomplete coverage clearly marked. Optional
`estimated: true` on a tariff period marks provisional carry-forward prices.
Use `rainExcludeLeapDay: true` only when documented rainwater billing excludes
29 February from its 365-day allocation. Drinking-water and sewage basic fees
still accrue on that day. By default all calendar days receive rainwater fees.
