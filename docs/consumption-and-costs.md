# Verbrauch & Kosten — 0.3.19, build 29

The protected page has five tabs: **Strom**, **Gas**, **Wasser**, **Total consumption**, **Heizung**.
Strom shows electricity prices, consumption and invoice reconciliation. Heizung
contains the gas/heat-pump threshold, live comparison price, recommendation,
safety margin, hysteresis, outside-temperature/COP/power model, learning status
and diagnostics. It updates with the state refresh every 60 seconds, retaining
the selected tab. Gas shows reviewed observations and supplier invoices. Wasser leads with the electronic main meter (including garden), dated calculated
costs and zero-cost well consumption. Manual garden data remain period references. Unconnected sources remain unknown; no sample readings appear.

The module class `LichtBlickElectricityPrice`, prefix `LBEP`, module ID and existing
`/hook/lichtblick_<instanceID>` URL stay compatible. Every gas request uses the
same SecretsManager session and origin checks as electricity. No new passkeys,
external client requests, commands or heating-control parameters are introduced.

## Connect an existing reviewed gas import

1. Update Heating-Control to **0.3.17, build 27**.
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

## Water streams and original-script charges — 0.3.17

`WaterHistoryEnabled` enables archive reads. `WaterHistorySources` is an object
with `house`, `garden`, `well` entries: positive distinct `counterID`, positive
`scaleM3` and a nonempty provenance `source`. A missing or zero ID remains
unconnected. Raw cumulative counter increases are assigned to their recorded
Berlin calendar day. A missing initial baseline or counter reset remains
incomplete. Quiet held counters contribute zero. Original archives are never
rewritten. This reader does not reproduce the old script's daily extrema/reset
heuristics or sum daily counter averages.
Dense raw water archives are processed as ascending events in bounded batches.
Annual selections retain daily totals rather than the complete raw event list;
unchanged readings, resets and ambiguous timestamps keep the same semantics.

If `WaterHistoryHouseIncludesGarden` is enabled, the displayed main volume includes
garden. Missing manual garden readings do not hide electronic main quantities or
calculated charges. A house-only main-minus-garden split remains available as a
period reference when supported by complete readings. Well water costs zero.
The Water tab opens with **Days** and **Water · m³**, showing daily main/well
consumption even though the well has no price. Costs and coarser groups remain
selectable; **Whole-period total** combines the entire selection into one bar.
The summary always totals the selected dates independently of chart grouping.
The `electronic` response contains the primary main/well report; legacy `streams`
retain the separate house-only view for API compatibility.
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


### Connect a verified water import

Set `WaterHistoryReviewedMetadataID` to the verified import metadata variable.
The metadata must contain `Symcon.PreparedWaterHistory.v1`, `import_status=verified`,
source IDs, target IDs, independent main/well coverage, snapshot cutoff and sparse
invoice comparisons. The reader verifies daily quantities against the cumulative
counter and rejects changed/missing archives or invoice-reference boundaries.
Registration markers after the cutoff are excluded. A configured invalid import
fails closed and does not fall back to the original unhealthy history.

The original source mappings and dated tariff/manual tables remain configured.
The page reads those original counters automatically after the cutoff, merging
its partial final day once. Later well zero/return pairs and small backward drift
are suppressed; unexplained drops remain unresolved. The well scan starts at the
snapshot boundary so changing the selected range does not restart its drift
baseline. Long winter inactivity is valid held-counter zero change. Neither the
page nor its 60-second refresh writes or extends the prepared archives.

Quality retains reset intervals, the initial incomplete baseline, suppressed well
noise, unverified historical calibration and invoice-period bypass uncertainty.
A bypass flag identifies an affected billing period, not exact asserted bypass
dates. Invoice differences are never spread across days. Known observed volumes
and their dated-price subtotals stay visible even on incomplete periods.

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
Water and total ranges are bounded to 4,018 days; electricity and gas retain their own 2,192-day limits. Invalid dates are rejected; missing connections
are reported per stream without hiding the other streams.

### Manual garden readings and invoices

Garden water need not have an electronic meter. The instance form has an
editable `WaterHistoryGardenPeriods` table with inclusive `from`/`through`,
consumption `m3` and the reading/invoice `source`. Use the consumed volume for
that interval, or the difference of its two meter readings. Intervals must be
ordered and must not overlap. Do not configure both manual periods and an
electronic garden meter. Archive history is unchanged.

A full manual period shows its garden volume as already included in the main
meter. It is never added to main plus well totals or stacked a second time. The
main daily consumption and costs remain visible for finer chart resolutions.
The house-only reference requires complete main coverage and garden not exceeding
main. Missing or partially selected manual periods are never interpolated.

A documented house-versus-garden tariff difference adjusts main costs once for a
complete manual period. This can deduct garden sewage charges already included
in the primary main calculation. The difference must be constant throughout the
period; otherwise actual subdivisions are needed. Provisional rates remain
marked. A manual credit appears in a chart bucket only when its entire period
fits that bucket; otherwise it stays in the total and is reported as unallocated
to finer chart buckets. Partial or unresolved credits leave incomplete coverage.
The main quantity is never redistributed to accommodate a manual reference.

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
split is unknown. The summary leads with captured main m3, well m3 and their physical total.
Garden appears as an included reference; cost stacks can show its signed tariff
adjustment while volume stacks contain main plus well once. Incomplete coverage
and provisional historical calibration remain visible. Optional
`estimated: true` on a tariff period marks provisional carry-forward prices.
Use `rainExcludeLeapDay: true` only when documented rainwater billing excludes
29 February from its 365-day allocation. Drinking-water and sewage basic fees
still accrue on that day. By default all calendar days receive rainwater fees.

## Cursor tooltips and dataset selection — 0.3.19

All five utility charts have immediate cursor tooltips showing the actual bucket
period, visible dataset values, units and quality. Zero is distinct from an
unrecorded value; gaps stay unrecorded. SVG screen transforms handle responsive
layouts. Tap a chart on touch devices, or focus it and use Left/Right/Home/End
for keyboard inspection. Escape, leaving the chart or session expiry clears the
tooltip and its cached data.

Checkbox legends select each dataset independently. Use **None** and then check
one dataset to isolate it, or select several. The vertical axis and visible chart
subtotal rescale to the chosen datasets. The selected-period summary and source
tables remain the full data totals. Selection is retained per chart/metric across
60-second refreshes, grouping/date changes and tab switches. Electricity cleaned
and reference kWh may be compared as separate bars, without summing two views of
the same consumption. Price sources have their own controls.

An included garden quantity and its parent main quantity are mutually exclusive
in a volume chart. Garden manual volumes are shown only when a complete recorded
period fits a chart bucket; finer buckets stay unrecorded. Select Whole-period
total for an eligible multi-day manual reference. Dated tariff adjustments remain
signed cost datasets. These controls and tooltips never write any archive data.

## Daily consumption email — 0.3.20

In the instance form, expand **Daily consumption email · midnight** and select
an existing SMTP instance from the object tree. The selector permits only the
built-in SMTP module. Mail goes to the recipient configured in that instance;
this module does not copy credentials or choose another recipient. Symcon 8.0
(the library minimum) supports HTML through `SMTP_SendMail`.

Use **Download yesterday’s email preview (no sending)** to inspect the same
HTML that the schedule generates. `LBEP_GetDailyConsumptionReport(instanceID,
"YYYY-MM-DD")` returns the HTML for a completed Berlin day without sending,
scheduling, changing settings, or writing archive records. Leave
`ConsumptionReportEnabled` off until the preview is approved. It defaults to
false and configuration imports disable it.

When enabled, the independent timer starts at the next **00:00 Europe/Berlin**
and reports the previous complete calendar day. Each following midnight is
calculated from the local calendar, including 23/25-hour clock-change days. The
saved due time survives restarts; an overdue timer reports yesterday once,
without sending a multi-day backlog. Recommendation and price-refresh timers
do not need to be enabled for the email timer to run.

The four rows are electricity, gas (converted kWh), house/main water and well
water. The report uses the same reviewed consumption and dated supply-cost
calculation as Total consumption. Main water physically includes garden use:
there is no separate garden row, no additional garden charge and no invented
daily subtraction. A documented garden tariff credit applicable to a whole
day is folded into main cost once. Unallocatable manual credits mark that cost
incomplete. Well water has no water charge; pump energy remains in the
electricity meter.

Known documented basic/rainwater charges are included once. Later electricity
periods currently contain energy charges only: undocumented standing charges
are not invented. Invoice reconciliation and aid remain outside dated supply
costs. Unconfirmed electricity tariffs, gas conversion and estimated water
rates remain provisional. Missing or partial values are labelled; incomplete
costs produce a **Known subtotal**, not a complete total. Each row is rounded
to cents before summing so that the displayed total matches its rows. Quantities
retain their units and are not added across kWh and m³.

`ConsumptionReportHTML` is a string variable with the HTMLBox profile directly
below the instance. Each generated scheduled report replaces its value before
the SMTP call, so it contains exactly the email body and remains available if
sending fails. A preview download leaves it unchanged; a failed data read retains
the last successfully generated report, whose day is shown in its header.

**Send yesterday’s report as test email** sends the same HTML through the selected
SMTP instance with a `[TEST]` subject and stores it in `ConsumptionReportHTML`.
Select the SMTP instance and apply the form settings first. This explicit button
works with daily sending disabled, reports SMTP success/failure in the form and
does not change the midnight timer or its attempt/sent markers. It can be used
to check the delivered appearance in Gmail on desktop and Android. A successful
SMTP call alone does not confirm the message arrived or how Gmail rendered it.

`ConsumptionReportStatus` shows scheduling, SMTP acceptance or failure. The
module records one attempt per report day before calling SMTP and serializes
callbacks with a semaphore. It will not automatically resend that day after
a failure or an uncertain response, including a restart during sending. SMTP
acceptance is not a guarantee of delivery to the recipient’s inbox. No mail
is sent when disabled or merely downloading a preview.

Run `php tests/lichtblick-daily-report.php` for clock changes, date rollover,
preview isolation, SMTP selection, restart/duplicate handling, failures,
rounding, free well water and incomplete/provisional reporting.
