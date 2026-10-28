# Heat-pump cost versus gas and recorded switching decisions

Version **0.3.46, build 57** applies the owner's four backtesting guidelines.
Open the existing passkey-protected Heating Control page, select Today,
Yesterday, Last 7 days or up to 31 Berlin calendar days, and Calculate.
Today stops at calculation time. The regular status refresh does not rerun
archive analysis.

## Agreed calculation rules

1. Include **only fan-ON periods** in heat, electricity, cost, COP and gas
   comparisons. Exclude fan-OFF periods completely. Unknown fan state is
   excluded and reported, not interpreted as OFF.
2. At identical archive timestamps, use the **first entry returned by Archive
   Control**. Keep the rest of the day. This is a deterministic tie rule,
   not a claim of subsecond physical ordering.
3. Attempt to download missing original supplier prices. Fill unresolved
   intervals with the **last known preceding historical price**, including
   zero and negative prices. Show carried-price durations and provisional
   costs. Never use a future price to fill an earlier gap. If no preceding
   price exists, that interval remains explicitly unavailable.
4. Use **2,000 m³/h whenever the fan is ON**. Fan-speed records are not read or
   validated. The 90% label selects full transformer speed and does not scale
   airflow by 0.9.

Dedicated import counter **22365**, with its configured kWh scale, supplies
heat-pump consumption. No household baseline is subtracted. Fan electricity,
fixed charges and radiator/hot-water gas are excluded. Startup, standby and
defrost electricity are included only where they overlap eligible fan-ON time.
The gas equivalent uses the same covered intervals and the configured useful
gas-to-air efficiency. A dated gas unit tariff takes precedence; current gas
price fallback is explicitly provisional. Switching margin and hysteresis
affect the decision audit, not retrospective energy costs.

## Source heat and coverage

Air-side heat is integrated as
`0.000335 × 2000 × (outlet − inlet) °C × hours`.
States and temperatures are held until their next recorded change.
A positive configured sensor-age limit remains respected. Missing temperature
history is not replaced by today's values.

Negative heat while the heat pump is enabled in heating mode remains in the
balance, including defrost. Positive residual heat after shutdown is attributed
to the heat pump until the air rise reaches zero. Later independent fan cooling
or warming cannot revive that cycle. Gas heat/run-on are excluded. Exact
separation of stored heat from both exchangers during a handover is not claimed.

Coverage is **matched time / eligible fan-ON time**, not a fraction of all
calendar hours. Fan-OFF time, unknown fan time, tests and carried-price minutes
are shown separately. Missing values display as unavailable. Incomplete reports
show a difference on matched intervals and suppress the headline overall
savings percentage. COP is the ratio of summed heat to summed electricity.

Monotonic meter brackets up to 180 seconds support interval interpolation.
Long flat brackets establish zero consumption. A nonzero longer bracket cannot
prove fan state or tariff allocation within a shutdown. Its **whole counter
increase** is retained separately in meter-gap diagnostics, never added to
daily/fan-ON totals or summed twice across days. Counter resets, implausible
increments and absent boundaries remain excluded. Recovering aggregate kWh
does not recover missing heat measurements.

## Intentional tests

Saved `*.summary.json` test files, the latest saved report, active test state and
archived capacity/flap-test flags identify exclusions. Automatic exclusion only
covers records actually available on the current Symcon system. Missing old
test flags are explained; no complete historical test inventory is assumed.

Add manual tests in the page's exclusion box, one Berlin period per line:

```text
2026-10-23 10:00 / 2026-10-23 13:00
```

Up to 16 manual periods are accepted. Exclusions affect costs and decision
review and accompany export links; original measurements and calibration
points are never deleted. Local times in the autumn repeated hour follow PHP's
Europe/Berlin interpretation; use periods outside that ambiguous hour when
possible. Exclusions are page inputs, not changes to heating control settings.

## Price recovery

The updated LichtBlick module exposes
`LBEP_RecoverPriceHistory($instanceID, $from, $to)`.
It attempts one download from the existing postcode-specific supplier endpoint
only when stored intervals are missing. Only original quotes present in that
response can be repaired. Arbitrary older-date access is **not assumed**.
Network/storage errors and remaining hole counts are returned explicitly.

Recovery fills holes without overwriting valid stored prices. It does not
evaluate recommendations, replace the live price cache, change configuration
or command heating equipment. Original recovered prices are persisted through
the existing history semaphore. Carried-forward prices are calculated for the
report and never saved as original supplier quotes. Raw history now includes
the last known interval preceding the query, even across UTC month files.

Both modules must be updated for supplier recovery and the preceding-price
seed contract. If the updated recovery API is unavailable, the page says so.
Wholesale-price tariff reconstruction remains retired. Neither today's tariff
nor the old app-normalized model silently fills missing prices.

## Recorded decision review

The new **Recorded switchover decisions** table shows fan-ON selection changes,
period-start context and fan-start context. It uses archived automatic/manual
state, the selected mode, and the original compact economic snapshot. It shows
recorded price, entry/exit limits, expected COP, expected source and the reason.

The threshold check uses the original previous economic source. Below entry
selects heat pump; at/above exit selects gas. Inside the hysteresis band the
previous economic source matters. Legacy snapshots missing that state remain
unverifiable in the band. Fresh recorded gas fallback is distinguished from an
economic gas choice. A later recovered price does not rewrite what the
controller knew then. Manual/test selections are not judged as automatic errors.

This is a review of **recorded thresholds and selection**, not a reconstruction
with today's COP surface or proof that a modeled alternative would have
performed identically. Daily measured cost/COP results remain separate.
Complete per-run thermal outcome association, model-vs-achieved COP analysis,
and gas-period heat-pump counterfactual simulation are not yet implemented.
Older decisions without archived inputs cannot be fully verified.

New compact `HeatPumpDecisionHistoryJSON` snapshots include price, prior source,
thresholds and expected COP; unchanged context is not archived every ten
seconds. Full legacy status JSON can still supply old records where logged.
The report reads only snapshots at relevant events rather than loading a
whole day's large JSON status stream.

## Diagnostics, storage and API

The page exposes exact issue intervals, with up to
200 visible rows. Download CSV for daily values plus interval/gap JSON cells,
or **Download detailed data** for the complete structured report, input variable
IDs, exclusions, read failures, price recovery and decision rows.
Per-day issue intervals are bounded at 2,000 with an explicit truncation warning;
aggregate issue durations remain complete. Decisions are bounded at 2,000
events per day. Failed channel reads identify the channel, ID and day.

`HC_GetHeatPumpCostAnalysis($id, $fromDate, $throughDate, $excludedPeriods = '')`
returns schema 1 with **calculationVersion 3**. Authenticated GET views are
`heatpump-costs`, `heatpump-costs-csv` and `heatpump-costs-json`, with `from`,
`through` and optional `excluded` text. The existing passkey/origin checks apply.

Applying the module enables logging of the linked dedicated counter, source,
fan on/off, temperatures, mode, compact decisions and test/automatic flags in
the configured test Archive Control. Newly enabled channels receive a present-time
baseline where Archive Control supports it; no current values are backdated
into old gaps. Existing logging/records are preserved. Logging failures are
reported in debug and historical deficiencies remain visible on the page.
Calculating reports changes no heating controls, configuration or raw sensor
history. Supplier-price recovery can add original prices to the persistent
LichtBlick history and update its history-storage status.

## Verification and remaining limits

The regression suite covers fan-only inclusion and coverage, ignored fan speeds,
same-second toggles, price recovery success/failure, chronological carry-forward,
zero/negative prices, missing first prices, meter gaps, tests/exclusions, defrost,
residual heat, DST, bounded reads, exports/authentication, immutable controls,
compact decision logging, hysteresis/fallback review and page interactions.

The changes correct report handling, not hardware performance. Missing
temperature/source records, unexplained fan-ON meter consumption with an OFF
heat-pump command, source handover attribution and unrecorded old decisions
still need real archive evidence. The previous seven-day CSV contains daily
aggregates and cannot be retroactively recalculated into exact fan-ON results.
