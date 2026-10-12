# LichtBlick electricity prices and air-heating recommendation — reviewed 0.3.11

Available in Heating-Control 0.3.0, build 10 as a separate **LichtBlickElectricityPrice** instance. It imports prices, publishes a price-based recommendation and optionally learns a break-even threshold. It never writes to external variables or commands a boiler, heat pump, mixer or pump. Library 0.3.2 adds an editable weather/power model and a protected dashboard alongside the optional, initially disabled HeatingControl integration; see [setup and exact source commands](automatic-source-selection.md).

## Setup

1. Update `https://github.com/fisart/Heating-Control` in Module Control. Create a **LichtBlick Electricity Prices and Heating Recommendation** instance.
2. Enter the electricity delivery postcode. Enter a positive initial break-even threshold in **ct/kWh**, derived from your consumption comparison. There is deliberately no guessed initial threshold.
3. Keep or change **Safety margin (%)** in the configuration form (`form.json`). Its initial default is **25%**. It is a saved property, not a constant in the decision calculation. Export/import includes your chosen value. Editing a static form default does not replace a saved instance setting.
4. Click **Fetch prices now**. Compare several intervals against the actual variable gross price in your LichtBlick contract/app. Only if a constant difference is verified, enter that difference in **Price correction (ct/kWh)**. Confirm **prices match my tariff**, then enable the instance. No credentials are needed for this public feed.
5. Inspect `RecommendedSource`, `RecommendationValid`, `DecisionReason` and `PriceStatus`. Leave the existing heating controller unchanged while evaluating recommendations.

The endpoint is the public website's postcode price display: `https://www.lichtblick.de/api/energy-price-curve/{postcode}/`. It was verified on 11 October 2026 against the website's own JavaScript client. It is an undocumented website interface, not a verified customer-account API. The user must check regional quotes and tariff corrections against their own contract; differing tariff formulas cannot be fixed with a constant correction.

Prices arrive in ct/kWh with absolute timestamps, normally in 15-minute intervals. The module uses only intervals actually returned, including negative prices. It does not assume that a response contains a complete day or tomorrow's prices, interpolate gaps or extend the final interval. Fetching defaults to every **15 minutes**; decisions are reevaluated every **60 seconds**, so a new interval can take up to one minute to appear in the recommendation. The last fetch result remains visible separately from current price validity. A failed request retains a still-covered, fresh cache. Changing postcode/correction clears it and resets tariff confirmation.

## Decision rule

`safe ceiling = current break-even threshold × (1 − safety margin / 100)`

With the weather model off, the current threshold is the configured reference. With it on, the model below adjusts that reference before applying the margin.

- Enter heat-pump recommendation when electricity is **strictly below safe ceiling minus hysteresis**.
- Retain that recommendation within the band; return to gas at or above the safe ceiling.
- Starting from gas/unavailable uses the entry threshold. Default hysteresis is **2 ct/kWh**, adjustable to zero for the direct comparison.
- Disabled/unconfigured/unconfirmed tariff: source **0**, neither source Boolean true.
- Confirmed configuration but missing/expired current price: source **2** (gas fallback), with `RecommendationValid=false`. This is a fallback, not a claim that gas has been proved cheaper.

For threshold **30**, margin **25%**, hysteresis **2**, the safe ceiling is **22.5 ct/kWh** and entry is below **20.5 ct/kWh**. With hysteresis zero, entry is below 22.5. These are boundaries of one decision band, not two competing estimates of heat-pump efficiency.

| Instance output | Meaning |
|---|---|
| `RecommendedSource` | 0 unavailable/disabled, 1 heat pump, 2 gas; air heating only |
| `HeatPumpRecommended`, `GasRecommended` | Mutually exclusive convenience flags; reflect the source, including gas fallback |
| `RecommendedHeatPumpPowerPercent` | Weather-curve command when enabled, otherwise configured ReferenceCapacityPercent rounded to an integer; valid heat-pump recommendation only, zero otherwise |
| `RecommendationJSON` | Final coherent source/power publication: schema 1, source, valid, powerPercent, updatedAt, validUntil, reason |
| `RecommendationValid` | True for an economic comparison using valid settings/current price; false for fallback or unavailable |
| `CurrentPriceCt`, `PriceValid` | Effective interval price and tariff-confirmed validity; a zero price with false validity must not be treated as free electricity |
| `ThresholdCt` | Current break-even threshold after weather adjustment, before margin; configuration property remains the reference |
| `ReferenceThresholdCt` | Configured / daily-learned reference threshold |
| `WeatherHeatPumpPowerPercent` | Weather power candidate, also visible when gas is preferred; zero if model disabled/invalid |
| `WeatherCOP`, `WeatherSystemCOP`, `WeatherHeatPumpKW` | Provisional COP, fan-adjusted COP and input estimate |
| `WeatherOutsideTemperatureC`, `WeatherModelValid`, `WeatherModelStatus` | Held model temperature, validity and explanation |
| `WebPath` | Registered protected dashboard path |
| `EffectiveThresholdCt`, `EntryThresholdCt` | Margin ceiling and hysteresis entry boundary |
| `PriceStart`, `PriceEnd`, `LastSuccessfulFetch` | Unix timestamps; end is exclusive |
| `PriceSchedule` | JSON of the effective prices and actual coverage returned |
| `PriceStatus`, `FetchStatus`, `DecisionReason` | Availability, latest request result and decision explanation |
| `DailyCandidateCt`, `ThresholdCalculatedAt`, `DailyStatus` | Latest reference-normalized candidate (raw in fixed mode), last accepted update time and outcome |
| `DebugLog` | Debug string directly below the instance; enabled in the form, bounded to 32,000 bytes |

This is an economic preference whenever air heating is needed. It does not decide whether heating is needed or optimise capacity. With the weather model off it publishes **ReferenceCapacityPercent**. With it on it publishes the interpolated temperature-curve command. The atomic recommendation expires when either the price/cache or the outside-temperature reading expires. HeatingControl enforces its transfer/off delays and existing demand/shutdown rules; defrost remains equipment-controlled. Connect the provider instance through HeatingControl configuration, rather than wiring independent source/power variables into the manual inputs. Wider moisture and radiator/DHW coordination remain design work.

## Editable weather and power model — 0.3.2

Enable **Use editable outside-temperature curve for power and threshold** after checking the input ID and starting assumptions. It defaults to off for existing and new instances. The form exposes each curve row, reference temperature/gas price, fan allowance, power step, temperature deadband and sensor age. Export/import includes them. These are provisional starting values, not an identified optimum:

| Outside temperature | Command | Provisional COP | Estimated heat-pump input |
|---|---:|---:|---:|
| −7°C | 65% | 2.70 | 3.19 kW |
| +2°C | 50% | 3.75 | 2.12 kW |
| +7°C | 35% | 5.78 | 1.00 kW |
| +12°C | 35% | 7.26 | 1.00 kW |

COP values come from the manufacturer's ASGE-48BI-3 + ASF-48BI sheet at different prescribed loads and 20°C indoor air. They are **not measured COPs of the installed SCHRAG system or holiday operation at 10°C**. The 35%/50% electricity estimates come from site counter data; 65%/3.19 kW is an interpolation between the observed 50% and 100% rates. Assigning these commands to temperatures is a starting policy for testing. See [source evidence and limitations](heatpump-capacity-research.md).

The existing threshold is treated as a reference at **+2°C** and **10.48 ct/kWh gas**, both editable calibration assumptions. An existing 30 ct threshold remains exactly 30 at that reference. No gas-boiler efficiency is invented:

```
netCOP(T) = COP(T) × HPkW(T) / (HPkW(T) + fanAllowanceKW)
factor(T) = gasPriceCt / referenceGasPriceCt × netCOP(T) / netCOP(referenceTemperature)
current threshold = configured / learned reference threshold × factor(T)
safe ceiling = current threshold × (1 − safetyMarginPercent / 100)
```

The default fan allowance is **0.60 kW**, conservative relative to the observed additional fan consumption. It is editable; the model gives no credit for fan electricity during gas operation. Fan costs remain included. The reference calibration contains unmeasured system/boiler effects; the manufacturer curve supplies relative weather adjustment. A 25% margin cannot guarantee compensation for a wrong curve or a different operating profile.

Power, COP and estimated input interpolate linearly between rows; power is rounded to the editable step, initially **5%**, and the outside-temperature model point changes after **0.5°C** movement. The input estimate is interpolated before power rounding, so it is approximate. Below the coldest row the recommendation falls back to gas; above the warmest row the model holds its last values without further COP extrapolation. Invalid or stale outside readings (initially **900 seconds**) clear the weather candidate and publish invalid gas fallback while leaving independently valid tariff data visible.

HeatingControl still determines demand and permissions. Update the curve after supervised tests; this version does not automatically identify optimal capacity or run experiments.

## Protected HTML dashboard and portal link

1. Update **Heating-Control** to **0.3.7, build 17** and **MyAlarmSystem** from `main`.
2. In the LichtBlick form, open **Protected LichtBlick web page**, enable **WebEnabled** and select **SecretsManager**. ID 0 selects the only eligible vault; multiple eligible vaults require an explicit choice. The vault must have its HTTPS portal enabled and expose `SEC_IsPortalAuthenticated`.
3. Apply. Deferred registration adds `/hook/lichtblick_<instanceID>` to WebhookControl. The child **WebPath** shows the result. Open it on the configured SecretsManager HTTPS origin.
4. Use **Refresh pages** on AlarmPortal. **Smart Home → LichtBlick prices & heating** appears automatically for each registered, web-enabled LichtBlick instance.

The dashboard shows interval price and tariff validity, coherent source/power recommendation, reference/raw/safe/entry thresholds, editable safety margin, outside temperature and model estimates, configured curve, learning/fetch status, actual upcoming intervals and the bounded debug log. It updates every 60 seconds and has a manual refresh. A single enabled AlarmPortal supplies a direct Back to portal link; otherwise browser Back is available.

Each page and JSON request checks the existing SecretsManager portal session and configured origin. Anonymous page requests redirect to the existing passkey login with a fixed local return path; anonymous data requests return 401. Missing/disabled vaults or authentication APIs fail closed. There is no additional password, passkey store or public data endpoint. SecretsManager retains ownership of its supported login methods. Nonces, a restrictive CSP, no framing and private/no-store headers protect the page; displayed text uses safe DOM text insertion.

The page is **read-only**: it cannot edit configuration, trigger fetches/learning or command heating. Opening it never mutates the instance. Expired recommendations show gas fallback and zero command; network/session failures hide previously displayed data. It is a recommendation view, not actuator feedback. The page can remain enabled when the price engine is disabled. Disabling WebEnabled blocks requests immediately and removes only its own hook.

## Persistent price history and manual import — 0.3.9

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

## Daily threshold calculation

Learning is **off by default**. It runs once at/after **00:10 Europe/Berlin**, using the previous complete calendar day (23/24/25 hours as appropriate). It needs suitable archived consumption and an earlier comparable period with the other heat source. Yesterday by itself cannot identify the heat-pump/gas consumption ratio.

The initial learner deliberately accepts only **holiday** days with unchanged air-heating targets between 5 and 15°C. It requires a single selected heat source, at least one hour of heating, room start/end drift no greater than 0.3°C, valid room/outside temperatures, and no significant radiator/storage charging: pump activity is limited to two minutes for exercise, with storage temperature at most 20°C. Heat-pump days must be in heating mode. In fixed mode they must use the configured reference capacity within 3 percentage points while on; in weather mode each active interval must match the curve command within 5 percentage points. Default reference capacity is **50%**; change it to the intended operating command. Cooling, mixed-source days, warm-up days, invalid sensors and changed targets are rejected.

Configure Archive Control, all input IDs and counter multipliers in the form; Berlin IDs are prefilled. The electricity inputs are **cumulative counters**, not power readings or historic prices. Defaults use 0.001 kWh/pulse on 12254 and 10054, gas m³ on 25105. Verify those units for the actual variables. All inputs, including room targets, must have logging enabled and an archived start baseline. The module neither starts logging nor fills missing historical data. A temperature held for over two hours is conservatively rejected because this learner cannot distinguish an unchanged reading from a failed sensor. A valid quiet cumulative counter is held between its logged changes; resets, ambiguous timestamps and saturated archive requests reject the day. Logging interruptions with no recorded change cannot always be detected from historic counter records alone.

With automatic selection, enable archive logging on HeatingControl **LearningSourceMode** and set **SourceModeID** to that child, because LearningSourceMode marks shutdown/bypass as unavailable. In 0.3.5, source selector 41085 mirrors the intended automatic source, including transfer/no-demand periods, and is retained when automatic selection is switched off. Mixed or incorrectly mapped days with over two minutes of opposite air-source activity are rejected.

The defaults monitor radiator pump 44860, storage load pump 20574, storage temperature 15709, actual heat-pump capacity command 19207 and mode 41085. **These defaults must be checked against your installation.** Shared humidifier/auxiliary electricity must be comparable between periods; unequal unrelated circuit loads can bias the estimate.

Use **Add historical reference** to check and store individually selected completed days without changing the threshold. Otherwise the learner collects suitable days as they occur. It retains at most 60 eligible days and matches only earlier days within 60 calendar days, with identical target profiles, outside average within 1°C, each room average within 0.3°C, and heating degree-hour ratio between 0.8 and 1.25. It requires **three opposite-source reference days** by default; this minimum is configurable. Several gas references against one heat-pump day do not provide several independent heat-pump experiments.

For each matched pair, let `S` be heat-pump/gas heating degree-hours, `B` the configured electric background load in kW, `H` the heat-pump day and `G` the gas day:

```
S = H.degreeHours / G.degreeHours
saved gas (m³) = (G.gasM³ − H.gasM³ / H.hours × G.hours) × S
expected gas electricity (kWh) = (G.electricity − B × G.hours) × S + B × H.hours
extra electricity (kWh) = H.electricity − expected gas electricity
candidate (ct/kWh) = saved gas × gas kWh/m³ × gas price ct/kWh / extra electricity
```

Only positive gas savings, at least 3 kWh extra electricity and candidate thresholds between 5 and 100 ct/kWh qualify. This is an approximate matched-consumption calculation; it does not measure delivered heat or COP. It assumes similar unmetered loads and weather response, and its result applies to the chosen capacity/holiday profile. Fixed-mode results apply to the configured capacity. Weather mode divides each accepted candidate by the heat-pump day’s estimated weather/gas factor, weighted by estimated total electricity over active intervals, before median/smoothing. This normalizes the learned reference; it does not measure COP, update curve rows, justify occupied-house extrapolation or remove the existing weather/profile matching restrictions.

Take the median of the qualifying candidates. Move the original threshold 20% toward that median, capped at **10% change per day**. Save the result back to the **ThresholdCt configuration property**, the form and the child threshold variable. Apply the user-selected safety margin once, after this update. Insufficient matches or failed checks leave the threshold intact and explain the reason in `DailyStatus`. Repeated calculation of an already accepted day does not compound the update. Learning input/model changes clear the reference pool; electricity quote, margin and web-access edits do not. Gas-price/model changes clear it.

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
