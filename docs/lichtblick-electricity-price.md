# LichtBlick electricity prices and air-heating recommendation — reviewed 0.3.8

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

## Persistent price history and historical retrieval — 0.3.7

The owner's LichtBlick contract started on **1 October 2026**. `PriceHistoryStartDate`
defaults to **2026-10-01**. Historical imports and chart requests before that date are
rejected. No prices before the contract are imported. Changing this date is an
explicit instance configuration change.

**PriceHistoryEnabled=true** saves every interval in the entire returned LichtBlick
schedule immediately, including already elapsed and future intervals on/after the
contract start. It does not depend on `Enabled`, which controls the heating
recommendation. The normal price-fetch cadence remains **15 minutes**. Historical
collection never makes a heating recommendation valid or commands equipment.

The public LichtBlick postcode feed has **no verified historical date interface**.
During development on 12 October 2026, requests using `date`, `start/end` and
`startDate/endDate` for earlier days returned the same current-day schedule. This
is not proof that customer-account exports cannot exist. The module does not
pretend that the current price is an older price.

With **HistoricalFetchEnabled=true**, the first background run retrieves **1 October
2026 through yesterday**. Later runs revisit the last seven completed days to
recover missing/revised market data; longer downtime is caught up in bounded
periods. Import work runs in weekly batches on the 60-second timer, retains its
queue across restarts, and retries failures after 15 minutes. Stored direct quotes
are retained when a historical import overlaps them. The module uses:

- `https://www.smard.de/app/chart_data/4169/DE/index_quarterhour.json`
- `https://www.smard.de/app/chart_data/4169/DE/4169_DE_quarterhour_<weekTimestampMilliseconds>.json`

These are fixed-host public website data endpoints for German/Luxembourg
**day-ahead wholesale prices**, not a LichtBlick customer-account API. Source:
**Bundesnetzagentur | SMARD.de**, https://www.smard.de/home/datennutzung (CC BY 4.0).
Wholesale EUR/MWh is converted to net ct/kWh by dividing by ten. Negative prices
are retained. Nulls and missing samples remain gaps. Native hourly data, if
encountered, are explicitly labelled with `resolutionSeconds=3600`; splitting that
hour for alignment does not claim quarter-hour price variation.

### Historic retail tariff components

The **HistoricalTariffs** list initially contains **no assumed tariff formula**.
Each row has an effective date (`from`, YYYY-MM-DD), delivery `postcode`, total
fixed **net** per-kWh additions (`adderNetCt`), VAT (`vatPercent`) and explicit
contract-formula confirmation (`verified`). Rows must have unique ascending dates.
The latest applicable row governs until the next row, even when unconfirmed; an
older confirmed row cannot silently carry through a changed tariff.

For a dated, verified matching-postcode row only:

```
reconstructed gross ct/kWh = (SMARD market net ct/kWh + adderNetCt) × (1 + vatPercent / 100)
```

The net adder must include all applicable net per-kWh tariff components exactly
once. Do not include VAT again or include a monthly basic charge in the adder.
This simple reconstruction requires the actual contract to use the same day-ahead
index and additive formula. A different index/formula cannot be repaired by an
invented constant. No current `PriceCorrectionCt` is applied to reconstructed
historic prices; the dated tariff table provides its complete formula.

Without a verified applicable tariff, market data are stored as **smard-wholesale**
with `price=null`; this means retail price unavailable, never zero/free electricity.
Verified reconstructions use **smard-reconstructed**. Downloaded regional quotes
use **lichtblick-quote**, with their original quote, correction, delivery postcode,
tariff-confirmation flag and retrieval timestamp. They represent the public
regional quote, not an independently obtained billed customer price. A direct
quote wins over a reconstruction; an unconfirmed refresh cannot downgrade a
previously confirmed quote. Editing tariff rows schedules backfill again and
reconstructs SMARD-derived records, while preserving direct quote records.

### Storage, selected periods and chart

Persistent files are stored under:

`<Symcon kernel directory>/media/lichtblick-price-history/<instanceID>/YYYY-MM.json`

Writes use temporary files and atomic replacement under an instance semaphore.
Interval keys use absolute Unix timestamps; both repeated hours at the autumn DST
change remain distinct. Months and previous postcode/correction identities are
retained. Changing postcode/correction isolates the new identity without deleting
older records. The page and API select the active postcode/correction identity.
No automatic history deletion or retention limit is implemented. Include this
media directory in production backup and standby replication. Removing an
instance does not automatically purge the files.

In the form, **Retrieve historical prices** queues a selected completed date range
(up to 366 calendar days, inclusive end date). **Cancel pending historical
retrieval** keeps all prices already saved. The default initial retrieval runs
without manually filling dates. Disabling historical retrieval pauses the pending
queue; disabling price history pauses collection. The existing records remain
readable. Configuration export/import includes the new settings but excludes
price files; restoring configuration preserves them and still disables the
recommendation, daily learner, tariff confirmation and web page. Price collection
uses the restored history switches independently; turn them off too if desired.

The protected HTML **Price history** section offers Today, Yesterday, Last 7 days,
Since contract start and custom dates. Retail gross and wholesale net are separate
selections, with source colours, exact hover values and an accessible table.
Periods up to seven calendar days show actual 15-minute slots; longer periods show
daily arithmetic means of available prices. These are time averages, **not
consumption-weighted billing prices**. Partial days, missing intervals and
confirmed-versus-available retail coverage are reported. The chart does not fill
gaps or substitute the current price. Requests remain read-only and use the
existing SecretsManager session/origin gate; the chart uses local SVG, without
external scripts/CDNs. Expired sessions/network errors clear old chart data.

New child variables are **PriceHistoryStatus**, **HistoricalFetchStatus** and
**LastHistoricalFetch**. The existing bounded **DebugLog** reports retrieval/storage
failures. For later consumption calculations:

`LBEP_GetPriceHistory(instanceID, fromUnixTimestamp, toUnixTimestamp)`

returns schema-1 JSON with identity, from/to and raw `intervals`; the end is
exclusive, intervals overlap the requested period, and raw requests are limited
to approximately 31 calendar days. Consumers must check `price !== null`,
`verified`, source, native resolution and coverage. This API reads only local
files and never fetches, reconstructs or changes heating settings.

Software checks: `php tests/lichtblick-price-history.php` covers durable storage,
quote precedence, repeat imports, independent identities, contract bounds,
negative/null prices, missing samples, DST, formula confirmation, bounded weekly
retrieval, resumable failures and configuration restore. The webhook/DOM suites
also verify history access gates, chart drawing, source labels and clearing on
session expiry. Deployment on the owner's Symcon installations is separate from
these local fixture checks.

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

### Partial retail history notice — 0.3.8

If historical imports contain wholesale-only records, the retail chart now displays a clear notice even when today has retail quotes. **Show available wholesale history** explicitly selects wholesale net prices and reveals older imported market data; it never changes the tariff or treats net prices as gross. Configure dated, verified HistoricalTariffs to reconstruct the missing retail prices. A completed import does not mean retail tariff components are available.
