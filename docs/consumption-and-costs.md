# Verbrauch & Kosten — 0.3.14, build 24

The protected page has four tabs: **Strom**, **Gas**, **Wasser**, **Heizung**.
Strom shows electricity prices, consumption and invoice reconciliation. Heizung
contains the gas/heat-pump threshold, live comparison price, recommendation,
safety margin, hysteresis, outside-temperature/COP/power model, learning status
and diagnostics. It updates with the state refresh every 60 seconds, retaining
the selected tab. Gas shows reviewed observations and supplier invoices. Wasser states
that its counter, archives and invoices still require review; it shows no sample
consumption or costs.

The module class `LichtBlickElectricityPrice`, prefix `LBEP`, module ID and existing
`/hook/lichtblick_<instanceID>` URL stay compatible. Every gas request uses the
same SecretsManager session and origin checks as electricity. No new passkeys,
external client requests, commands or heating-control parameters are introduced.

## Connect an existing reviewed gas import

1. Update Heating-Control to **0.3.14, build 24**.
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
