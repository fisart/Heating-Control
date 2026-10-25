# LichtBlick prices, consumption and measured heating comparison — 0.3.26

This instance supplies verified electricity prices, consumption/cost histories,
daily consumption reports and a read-only view of the connected Heating Control
matrix. It does not command heating hardware. The provisional weather curve,
manual reference threshold/power and daily threshold learner are retired.

## Setup

1. Update the library and apply LichtBlick and Heating Control.
2. Open **Electricity tariff and price retrieval**: enter delivery postcode,
   verify any constant correction against the actual gross tariff, confirm the
   tariff and enable price supply. Default fetching is every 15 minutes.
3. Open **Gas / heat-pump switching settings**: set current gas energy price,
   safety margin (default 25%) and price hysteresis (default 2 ct/kWh).
4. Select this provider in Heating Control, where gas efficiency, supply margins,
   calibration and the actual automatic/manual switch are configured.
5. Keep **Consumption history — shared settings / Archive Control** configured:
   it remains necessary for electricity, gas and water history, even though the
   old daily learner is removed. No-consumption periods are shared settings too.
6. Review the independent electricity/gas/water history panels, midnight report
   SMTP instance, protected-page vault and diagnostics settings as appropriate.

## Price and measured decision

The public postcode feed is `https://www.lichtblick.de/api/energy-price-curve/{postcode}/`.
Regional quotes require confirmation against the actual customer tariff. Absolute
15-minute slots, negative prices and verified corrections are retained; missing
prices remain gaps and quotes are never extended beyond their interval/cache life.
A verified `PriceQuoteJSON` is published independently of any heating recommendation.
Invalid applied settings immediately invalidate it.

The Heizung tab finds the one Heating Control instance explicitly referencing
this provider and displays its current measured EUR/kWh comparison. No controller,
multiple matching controllers or expired/mismatched settings produce an explicit
unavailable state. Only a registered, enabled, same-vault Heating Control page gets
a navigation link. The original source/threshold output identities mirror the
current measured comparison for existing references; they do not run a second model.

Protected origin/authentication checks apply to pages, charts and downloads.
Existing histories, meter mappings, archived object identities and report recipient
configuration are preserved. Preview/test actions retain their exact API signatures;
reorganizing the form never sends an email. Retired settings are retained internally
for backup compatibility and cannot reactivate their old calculation paths.

## Persistent price history and manual import — 0.3.9

## Consumption-weighted fixed-price comparison — 0.3.47

The Strom tab now has **Dynamic electricity versus fixed price**. Choose since
the LichtBlick contract start, yesterday, last seven days, this month, or custom
dates up to 366 Berlin calendar days. Today includes completed quarters only.
Periods before the provider's contract start and future dates are rejected.
The comparison is calculated on demand, independently of the normal live-page
refresh and the heating/fan analysis.

Enter a gross fixed energy price in **ct/kWh**. The initial benchmark is the
owner's requested **34 ct/kWh**, editable in 0.001 ct increments and retained in
this browser. A separate preset uses **35.479 ct/kWh**, the gross work price in
the owner's 123energie tariff-change document effective **1 October 2025**.
That document is evidence of its specified rate, not confirmation that every
later bill used that price. The input remains a user-selected comparison rate.

The weighted average is **sum(physical kWh × gross ct/kWh) / sum(physical kWh)**.
The dynamic euro cost and fixed alternative use exactly the same paired kWh.
The physical cleaned house-meter series is used, rather than reference-normalized
consumption. Prepared-history fixed costs, invoice residuals, bonuses and
standing charges are not included. The comparison does not claim a complete
bill comparison. Imported app-normalized prices may already absorb basic
charges; their kWh are prominently identified as provisional and cannot be
called independently verified energy-only prices.

The panel shows the weighted price, entered fixed price, energy costs, saving
or additional cost, time/known-energy coverage, daily results and a cursor chart
with independently selectable dynamic/fixed series. Changing only the reference
price recalculates from the same captured consumption immediately without
another archive query or any heating-control change. Changing dates clears old
results; late responses cannot restore a different period. Errors/session
expiry clear the comparison. Overall savings percentages are withheld for
incomplete time coverage; prices and differences then refer to covered energy.

Original stored/reviewed interval prices are used first. Missing prices trigger
one supplier recovery attempt, then chronological carry-forward from the last
known preceding price. The absence of an earlier price remains explicit.
Carried/other unconfirmed prices and estimated meter intervals qualify results.
Zero and negative dynamic prices are supported. Zero consumption has no
meaningful weighted unit price. Raw later-meter reads are processed one calendar
day at a time, including 23/25-hour clock-change days, and preserve existing
counter-gap/reset checks. No household consumption is excluded because a fan
or heat pump was off.

`LBEP_GetElectricityPriceComparison(instanceID, from, through, fixedPriceCt=34)`
returns schema-1 JSON with summary, daily values, price-recovery status and
coverage errors. The authenticated GET view is `electricity-price-comparison`
with `from`, `to`, and `fixedCt`. Existing passkey/origin checks apply. It works
with reviewed prepared history and later raw house-meter data, or raw data alone
when prepared history is not connected. Supplier recovery accepts up to 366
calendar days while the existing raw `GetPriceHistory` 31-day limit is retained.

Run `php tests/lichtblick-fixed-comparison.php` and the existing consumption,
price-history, webhook and page DOM suites. Tests verify energy weighting,
identical cost scope, ignored reference/fixed costs, missing consumption/price
denominators, zero/negative prices, chronological carry-forward, 25-hour days,
all-hours meter integration, recovery failure, auth/origin checks, editable
benchmarks, chart tooltips, escaping, clearing and browser persistence.

### Persistent price history

From **0.3.46**, `RecoverPriceHistory(from, to)` can attempt to refill missing
original quotes from the existing supplier endpoint without changing live
recommendations or the price cache. Older dates absent from that response stay
unrecovered; arbitrary-date supplier downloads are not assumed. Raw
`GetPriceHistory` includes `previousInterval` for the last known preceding price.
The Heating Control report then uses carried-forward prices as explicitly
provisional estimates, never persisting them as original quotes. Wholesale
reconstruction remains retired. See [backtesting rules](heatpump-period-costs.md).

The contract starts **1 October 2026**. `PriceHistoryStartDate` defaults to
**2026-10-01**; chart and raw-history requests before that date are rejected.
**PriceHistoryEnabled=true** retains every actual interval in the live public
LichtBlick response, including elapsed/future slots on or after the contract start.
Collection remains independent of the heating recommendation and runs at the
normal **15-minute** fetch cadence. It never invents missing live intervals.

The module no longer downloads SMARD history or reconstructs retail tariffs.
The historical retrieval/cancellation buttons and historical tariff table have
been removed. `HistoricalFetchEnabled` and `HistoricalTariffs` are hidden, inert
compatibility properties for old schema-1 backups; restore clears them. An old
saved true value does not restart downloads. Old retrieval-status children are
preserved but hidden; `PriceHistoryStatus` reports live schedule storage.

### One-time import of app-normalized estimates

The owner imported **1,056 quarter-hour intervals for 1–11 October 2026** with a
standalone script into **Archive Control 14817 / CurrentPriceCt 17542** and the
chart's persistent monthly store under **instance 30424**. Imported historical
`PriceValid` points are false; the live values remain managed by the price module.
The script enables future price/validity logging, protects existing quotes and
archive points, backs up touched files/settings and supports repeat runs.

The estimated price model is:

```
estimated retail ct/kWh = market ct/kWh × 1.19 + 18.440804258195374
```

This fits the owner's **466 kWh / EUR 173.26 for 1–10 October** with a relative
meter normalization of **1.0041854709015685**, used only in the analysis. The
normalization is not written to a consumption variable or hardware calibration.
The 3 October interval allocation used hourly power scaled to the known daily
counter increase. Both SMARD and Fraunhofer Energy-Charts provided matching
wholesale samples. Matching the app total is by construction, not independent
verification of a contract formula. Any fixed charges included in the app total
are absorbed by this effective addition and must not be charged again.

Imported estimates have **source=app-normalized-estimate, verified=false**.
They are displayed separately from **lichtblick-quote** records, whose confirmation
reflects `TariffVerified` when captured. Existing **smard-wholesale** and
**smard-reconstructed** rows remain readable; no source or verification flag is
silently upgraded. A direct quote takes precedence over a manual estimate.
Consumers must check source, verification and coverage, not just a numeric price.

### Storage, chart and missing intervals

The chart reads `<kernel>/media/lichtblick-price-history/<instanceID>/YYYY-MM.json`,
not the price variable's archive alone. Therefore an archive-only import does not
populate it. Standalone imports update both stores. Files use UTC months and
absolute timestamps, atomic replacement and the `LBEP.History.<instanceID>`
semaphore. September's UTC file includes the first two local hours of 1 October.
Other months and postcode/correction identities are retained. Include the entire
price-history and import-backup media directories in backup/standby replication.
Configuration restore preserves price files and leaves web access, recommendation,
learning and tariff confirmation disabled. Live collection uses the restored
`PriceHistoryEnabled` switch independently.

The protected page offers Today, Yesterday, Last 7 days, Since contract start and
custom dates. The chart displays retail gross prices only. The wholesale selector and legend have been removed. Up to
seven days shows actual intervals; longer periods show daily arithmetic means,
not consumption-weighted billing prices. The page reports imported-estimate,
confirmed-retail and missing interval counts, with exact hover values and a retail price table. **Published price intervals** is a collapsed section; click its heading to open or close it. Refreshing the page data preserves the open/closed state.
Read-only chart requests require the existing SecretsManager session/origin gate.
Network/session failures clear previous values. The accessible table also displays retail prices only. Wholesale samples remain in stored records for provenance and later analysis.

A complete **1–11 October** import does not fill a gap on **12 October**. The
observed live 12 October response stored **88 of 96** local-day intervals; the
missing **00:00–02:00** consists of **8** intervals. A separate targeted script
can add those eight app-normalized estimates, preserving all actual quote slots.
Subsequent live-feed gaps remain visible until supported by a separate import;
the module does not fill them with the current price. A fetch count in the debug
log cannot recover previous schedules.

`LBEP_GetPriceHistory(instanceID, fromUnixTimestamp, toUnixTimestamp)` still
returns schema-1 JSON with the active identity, bounds and raw intervals. End is
exclusive and each request is limited to approximately 31 calendar days. It reads
local files only. Historic tariff reconstruction/download APIs are removed.

`php tests/lichtblick-price-history.php` covers manual estimates, quote precedence,
repeat imports, identities, DST, legacy settings, retained files and absence of
historical downloads. The DOM/webhook suites cover estimate labels, coverage,
source distinctions, read-only access and session failure handling.

## Configuration backup and checks

Export shows versioned JSON in the form. Import validates the complete backup before writing, includes the latest learned threshold and chosen safety margin, clears the live price cache and learning references, preserves persistent price files, and leaves web access, recommendation, daily learning and tariff confirmation **disabled**. Recheck IDs/tariff and reenable explicitly. Learned reference records/debug logs and persistent price files are not configuration and are not exported. Include the persistent price directory in the normal Symcon data backup.

`php tests/lichtblick.php` checks interval coverage, invalid responses, negative prices, margin/hysteresis, outage cache handling, configuration restore, DST day boundaries, accepted matched learning, repeat-update prevention and rejection of resets, sensor failures, radiator contamination and incomplete archive requests. It uses local fixtures, not live heating hardware. `tests/lichtblick-webhook.php` checks session/origin enforcement, read-only behavior, stale decisions and deferred hook ownership. `tests/lichtblick-page-dom.cjs` verifies displayed values, text escaping, refresh and expired-session/outage handling. Weather interpolation, sensor expiry and end-to-end normalized learning are covered by the price tests. Existing HeatingControl behavior/security tests remain in the workflow. A live public response was also parsed successfully during development; deployment and testing against the user's IP-Symcon installation remain to be done.

## Reviewed electricity consumption and costs — 0.3.11

The protected dashboard now has a separate consumption/cost history, independent
of the current heating recommendation and the LichtBlick contract start date.
Connect an already reviewed one-time import in **Reviewed electricity consumption
and costs**: select its cleaned kWh counter, reference-adjusted kWh counter and
quality variable; enter the import manifest path relative to the Symcon kernel
directory. Enable the history after the import succeeds. Set the later whole-house
meter and its scale to kWh. Defaults are disabled and IDs zero; another installation
is never silently connected to this site's private variables.

Historical kWh come from the dedicated archived counter boundaries. The module
checks their values and quality against the reviewed dataset, rather than repairing
the raw past again. The manifest binds the target IDs, archive and dataset SHA256.
Missing/mismatched boundaries or changed evidence fail visibly. Aggregation can be
original intervals (maximum 31 days), daily, monthly or yearly; automatic grouping
uses original intervals for up to seven days, daily for up to 100 days and monthly
thereafter. Dates use Europe/Berlin, including 23/25-hour days. Native daily history
is never expanded into pretend quarter-hour measurements.

Cleaned consumption and reference-adjusted consumption remain separate. Cost is
reference kWh times the dated gross unit price plus documented interval fixed cost.
Invoice/app totals and their cost residual are displayed in a separate table. A
complete selected reference period receives its full reconciliation once; partial
selections show the whole-period reference but exclude its residual. A verified
stored LichtBlick quote can replace an earlier app price estimate without altering
either consumption archive; the complete app cost residual is recomputed accordingly.
Normalization is confined to the documented invoice/app period. It never calibrates
future meter readings or claims an actual daily supplier allocation.

After the reviewed snapshot ends, completed quarter hours are read from the later
meter's archived counter values and paired with stored retail quotes. Short boundary
gaps interpolate over at most 180 seconds; resets, implausible jumps, longer missing
meter sections and unavailable prices remain gaps. Archive reads split saturated
queries rather than truncate them. No initial accumulator, power-based reset energy,
daily average price or constant latest price is invented. An unlogged consumption
counter cannot yield historical consumption from its current value.

Later costs contain energy charges only. The module adds no undocumented basic fee;
app-normalized price estimates may already absorb such fees. Known partial sums are
labelled as such; a missing cost is blank, not zero. The imported counters remain a
fixed reviewed snapshot; opening/refreshing the page neither extends them nor writes
any raw archive or commands heating. Later consumption appears automatically from
the live meter archive as it is recorded. New invoice reconciliation still requires
new reviewed evidence. An absent closing invoice stays absent.

Public module API: `LBEP_GetConsumptionHistory($instanceID, 'YYYY-MM-DD',
'YYYY-MM-DD', 'auto')` returns JSON; the second date is inclusive. The authenticated
dashboard uses `?view=consumption-history&from=...&to=...&resolution=...` with the same
passkey, HTTPS-origin and no-store controls as other history requests. The full
window is limited to 2,192 calendar days. Configuration backup/restore includes the
connection; older backups fill these new properties with their disabled defaults.



## Consumption & costs page — 0.3.12

The provider-independent title is **Verbrauch & Kosten**, with **Strom**, **Gas** and **Wasser** tabs. The class/prefix and `/hook/lichtblick_<instanceID>` URL remain compatible. See [reviewed gas data, invoice semantics and connection](consumption-and-costs.md). Use a targeted AlarmPortal PageOverrides name to label the existing card; no additional hook or authentication setup is needed.
