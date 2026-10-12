# Verbrauch & Kosten — 0.3.12, build 22

The protected page has three tabs: **Strom**, **Gas**, **Wasser**. Strom retains
the electricity price, consumption, invoice reconciliation and heating-model
functions. Gas adds reviewed observations and supplier invoices. Wasser states
that its counter, archives and invoices still require review; it shows no sample
consumption or costs.

The module class `LichtBlickElectricityPrice`, prefix `LBEP`, module ID and existing
`/hook/lichtblick_<instanceID>` URL stay compatible. Every gas request uses the
same SecretsManager session and origin checks as electricity. No new passkeys,
external client requests, commands or heating-control parameters are introduced.

## Connect an existing reviewed gas import

1. Update Heating-Control to **0.3.12, build 22**.
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
- Daily kWh and daily costs are not calculated from a constant conversion or an
  undocumented tariff. Invoice kWh and costs belong to their own complete periods.

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
