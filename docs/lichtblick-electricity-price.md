# LichtBlick electricity prices and air-heating recommendation

Available in Heating-Control 0.3.0, build 10 as a separate **LichtBlickElectricityPrice** instance. It imports prices, publishes a price-based recommendation and optionally learns a break-even threshold. It never writes to external variables or commands a boiler, heat pump, mixer or pump. Library 0.3.1 adds an optional, initially disabled HeatingControl integration; see [setup and exact source commands](automatic-source-selection.md).

## Setup

1. Update `https://github.com/fisart/Heating-Control` in Module Control. Create a **LichtBlick Electricity Prices and Heating Recommendation** instance.
2. Enter the electricity delivery postcode. Enter a positive initial break-even threshold in **ct/kWh**, derived from your consumption comparison. There is deliberately no guessed initial threshold.
3. Keep or change **Safety margin (%)** in the configuration form (`form.json`). Its initial default is **25%**. It is a saved property, not a constant in the decision calculation. Export/import includes your chosen value. Editing a static form default does not replace a saved instance setting.
4. Click **Fetch prices now**. Compare several intervals against the actual variable gross price in your LichtBlick contract/app. Only if a constant difference is verified, enter that difference in **Price correction (ct/kWh)**. Confirm **prices match my tariff**, then enable the instance. No credentials are needed for this public feed.
5. Inspect `RecommendedSource`, `RecommendationValid`, `DecisionReason` and `PriceStatus`. Leave the existing heating controller unchanged while evaluating recommendations.

The endpoint is the public website's postcode price display: `https://www.lichtblick.de/api/energy-price-curve/{postcode}/`. It was verified on 11 October 2026 against the website's own JavaScript client. It is an undocumented website interface, not a verified customer-account API. The user must check regional quotes and tariff corrections against their own contract; differing tariff formulas cannot be fixed with a constant correction.

Prices arrive in ct/kWh with absolute timestamps, normally in 15-minute intervals. The module uses only intervals actually returned, including negative prices. It does not assume that a response contains a complete day or tomorrow's prices, interpolate gaps or extend the final interval. Fetching defaults to every **15 minutes**; decisions are reevaluated every **60 seconds**, so a new interval can take up to one minute to appear in the recommendation. The last fetch result remains visible separately from current price validity. A failed request retains a still-covered, fresh cache. Changing postcode/correction clears it and resets tariff confirmation.

## Decision rule

`safe ceiling = configured threshold × (1 − safety margin / 100)`

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
| `RecommendedHeatPumpPowerPercent` | Configured ReferenceCapacityPercent rounded to an integer for a valid heat-pump recommendation; zero otherwise; not a learned optimum |
| `RecommendationJSON` | Final coherent source/power publication: schema 1, source, valid, powerPercent, updatedAt, validUntil, reason |
| `RecommendationValid` | True for an economic comparison using valid settings/current price; false for fallback or unavailable |
| `CurrentPriceCt`, `PriceValid` | Effective interval price and tariff-confirmed validity; a zero price with false validity must not be treated as free electricity |
| `ThresholdCt` | Current original break-even threshold, before margin |
| `EffectiveThresholdCt`, `EntryThresholdCt` | Margin ceiling and hysteresis entry boundary |
| `PriceStart`, `PriceEnd`, `LastSuccessfulFetch` | Unix timestamps; end is exclusive |
| `PriceSchedule` | JSON of the effective prices and actual coverage returned |
| `PriceStatus`, `FetchStatus`, `DecisionReason` | Availability, latest request result and decision explanation |
| `DailyCandidateCt`, `ThresholdCalculatedAt`, `DailyStatus` | Raw latest candidate, last accepted update time and calculation outcome |
| `DebugLog` | Debug string directly below the instance; enabled in the form, bounded to 32,000 bytes |

This is an economic preference whenever air heating is needed. It does not decide whether heating is needed or optimise capacity. In 0.3.1 it publishes the configured **ReferenceCapacityPercent** as an operating command when recommending the heat pump. HeatingControl enforces its transfer/off delays and existing demand/shutdown rules; defrost remains equipment-controlled. Connect the provider instance through HeatingControl configuration, rather than wiring independent source/power variables into the manual inputs. Wider moisture and radiator/DHW coordination remain design work.

## Daily threshold calculation

Learning is **off by default**. It runs once at/after **00:10 Europe/Berlin**, using the previous complete calendar day (23/24/25 hours as appropriate). It needs suitable archived consumption and an earlier comparable period with the other heat source. Yesterday by itself cannot identify the heat-pump/gas consumption ratio.

The initial learner deliberately accepts only **holiday** days with unchanged air-heating targets between 5 and 15°C. It requires a single selected heat source, at least one hour of heating, room start/end drift no greater than 0.3°C, valid room/outside temperatures, and no significant radiator/storage charging: pump activity is limited to two minutes for exercise, with storage temperature at most 20°C. Heat-pump days must be in heating mode and use the configured reference capacity within 3 percentage points while on. Default reference capacity is **50%**; change it to the intended operating command. Cooling, mixed-source days, warm-up days, invalid sensors and changed targets are rejected.

Configure Archive Control, all input IDs and counter multipliers in the form; Berlin IDs are prefilled. The electricity inputs are **cumulative counters**, not power readings or historic prices. Defaults use 0.001 kWh/pulse on 12254 and 10054, gas m³ on 25105. Verify those units for the actual variables. All inputs, including room targets, must have logging enabled and an archived start baseline. The module neither starts logging nor fills missing historical data. A temperature held for over two hours is conservatively rejected because this learner cannot distinguish an unchanged reading from a failed sensor. A valid quiet cumulative counter is held between its logged changes; resets, ambiguous timestamps and saturated archive requests reject the day. Logging interruptions with no recorded change cannot always be detected from historic counter records alone.

With automatic selection, enable archive logging on HeatingControl **LearningSourceMode** and set **SourceModeID** to that child, because manual 41085 is preserved. Mixed or incorrectly mapped days with over two minutes of opposite air-source activity are rejected.

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

Only positive gas savings, at least 3 kWh extra electricity and candidate thresholds between 5 and 100 ct/kWh qualify. This is an approximate matched-consumption calculation; it does not measure delivered heat or COP. It assumes similar unmetered loads and weather response, and its result applies to the chosen capacity/holiday profile. It does not extrapolate performance to other capacities, comfort temperatures or substantially different weather.

Take the median of the qualifying candidates. Move the original threshold 20% toward that median, capped at **10% change per day**. Save the result back to the **ThresholdCt configuration property**, the form and the child threshold variable. Apply the user-selected safety margin once, after this update. Insufficient matches or failed checks leave the threshold intact and explain the reason in `DailyStatus`. Repeated calculation of an already accepted day does not compound the update. Input/model changes clear the reference pool; price changes and margin edits do not.

## Configuration backup and checks

Export shows versioned JSON in the form. Import validates the complete backup before writing, includes the latest learned threshold and chosen safety margin, clears cached prices/history and leaves recommendation, daily learning and tariff confirmation **disabled**. Recheck IDs/tariff and reenable explicitly. Learned reference records/debug logs are not configuration and are not exported.

`php tests/lichtblick.php` checks interval coverage, invalid responses, negative prices, margin/hysteresis, outage cache handling, configuration restore, DST day boundaries, accepted matched learning, repeat-update prevention and rejection of resets, sensor failures, radiator contamination and incomplete archive requests. It uses local fixtures, not live heating hardware. Existing HeatingControl behavior/security tests remain in the workflow. A live public response was also parsed successfully during development; deployment and testing against the user's IP-Symcon installation remain to be done.
