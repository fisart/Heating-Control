# Sinclair capacity and fan consumption — 11 October 2026

## Findings and decision

The installed outdoor unit was identified by the owner in the earlier heat-pump
comparison as **Sinclair ASGE-48BI-3 New Uni DC**, with 13.4 kW rated cooling and
15.5 kW rated heating. The owner confirms the humidifier **was and remains always
off**. Its shared circuit can therefore be treated as the heat-pump circuit for
these periods, including standby/control loads. A generic percentage of rated
input is not a measured heat or electricity curve.

**Keep fan energy in the comparison.** It is significant at low heat-pump capacity.
Outside weather is relevant, but the best capacity also depends on room heat demand,
setpoint, indoor heat-exchanger temperature, airflow, cycling and equipment limits.
The current data do not establish a unique optimal percentage or a causal weather
curve. Version 0.3.2 implements an opt-in editable starting curve using this evidence,
not a learned optimum. Its power/threshold model and conservative fan allowance
are described in [LichtBlick setup](lichtblick-electricity-price.md). The
manufacturer COP values still apply to its tested indoor pairing and prescribed
part loads; suitability for this installed system remains to be measured.

## Archive evidence

Input: `Heating_history_20250212_000000_fb8f31.txt`, raw changes and baselines,
1 January–12 February 2025, Europe/Berlin. Source commands are reconstructed on a
10-second grid. HP eligibility requires enable 35931=true, selected mode 41085=1,
and heating mode 51011=true. Capacity 19207 is grouped within one percentage point
of 35/50/100 to include KNX rounding (34/49/99). Reported watt readings held over
15 minutes and outside temperature held over two hours are excluded. The commands
identify requested operation, not physical compressor-running feedback.

Consumption below comes from cumulative counters **12254** and **10054**, scaled
0.001 kWh/pulse according to the meter instance's 1000-impulse configuration. Short
boundary intervals are allocated linearly between readings. Thus these are
approximate energy/running-time averages, including start-up, cycling, defrost and
standby during the requested operating period. They are not steady full-load watts.
They cover about 17.4, 13.5 and 59.6 hours respectively, across nonidentical weather.

| Command setting | Requested-operation hours | HP circuit mean consumption rate | Fan/heating circuit mean while fan on | Mean outside temperature |
|---|---:|---:|---:|---:|
| Around 35% | 17.4 h | 1.00 kWh/h | 0.72 kWh/h | +0.8°C |
| Around 50% | 13.5 h | 2.12 kWh/h | 0.70 kWh/h | −1.4°C |
| Around 100% | 59.6 h | 5.68 kWh/h | 0.57 kWh/h | +1.6°C |

The auxiliary circuit is **not a dedicated fan meter**: it includes heating
controls/pumps too. With fan off and gas-air pump off, its reported mean background
was around 89 W (median 72 W). Subtracting roughly 70–90 W gives an indicative
fan-related increase of **0.48–0.65 kW**, rather than an isolated fan measurement.
During these periods fan speed was almost always commanded to 90%. Duct flap states
and pressure can change real airflow/power despite the same speed command.

The fan-related increment is roughly 8% of HP-plus-fan consumption at 100%, around
22% at 50%, and around 38% at 35% when averaged over those command periods. Treat
these proportions as approximate: counter/power estimates, auxiliary attribution
and compressor on/off timing differ. A lower electrical rate does not imply less
energy for the same delivered heat if the fan runs much longer.

A particularly useful additional period is **3 February 2025, 07:17:30–12:45
Berlin time**, at a steady 35% request. The HP circuit's reported power averaged
**234 W**, the auxiliary circuit **769 W**, and outgoing duct air minus incoming
averaged **0.99°C**. Only about 10% of that interval had HP-circuit readings above
500 W. This is a requested-on interval, not evidence of 5.5 hours of continuous
compression. It warrants checking actual compressor status, heat-exchanger limits
and whether the selected setting was maintaining room targets. Do not diagnose a
fault, defrost duration or a minimum capacity from this alone.

Raw instantaneous circuit readings at 100% sometimes exceed the manufacturer's
5.95 kW maximum input declaration, reaching roughly 9 kW; whole-period counter
averages are lower. With the humidifier excluded, meter pulse-rate calculation,
scaling and the exact circuit/model should be checked before using instantaneous
values as a physical capacity curve. No automatic correction factor is applied.

Reproduce the state/power and consumption summaries with:

```
python libs/tools/analyse-heatpump-history.py /path/to/Heating_history_20250212_000000_fb8f31.txt
```

The script needs NumPy. It is deliberately specific to this exported period/IDs,
uses no price variables and does not calculate delivered heat or COP.

## Manufacturer evidence

Sinclair's product information gives nominal heating output **15.5 kW** and input
**4.45 kW**. Its service manual gives maximum electrical input **5.95 kW** for this
outdoor model. These figures do not mean that a 35% request draws 35% of 4.45 kW.

Sinclair's authored product-data sheet, model **ASGE-48BI-3 + ASF-48BI**, provides
seasonal part-load heating points at indoor 20°C:

| Outdoor temperature | Declared heating output | COP | Calculated electrical input (output / COP) |
|---|---:|---:|---:|
| −7°C | 9.95 kW | 2.70 | 3.69 kW |
| +2°C | 6.23 kW | 3.75 | 1.66 kW |
| +7°C | 3.92 kW | 5.78 | 0.68 kW |
| +12°C | 3.21 kW | 7.26 | 0.44 kW |

These are **different prescribed part loads at different temperatures**, not four
tests of one fixed command percentage. They support using weather/load information
and show efficient part-load operation is possible. They do not separate the causal
effects of load and weather, define a mapping to KNX percentages, or certify the
performance of the installed SCHRAG heat exchanger/fan or a 10°C holiday setpoint.
Other matched indoor units have different published values. Do not substitute this
table for the site's measured COP or directly set the switching threshold from it.

The **SCMI-01.4** manufacturer manual says slave 0–10 V requests compressor
performance 0–100%, with enable also required and off below 0.5 V. Its display has
**Cset**, **Cact**, current mode (Stop/Cool/Heat/Wait), fan speed and expansion-valve
position. It describes staged stabilisation delays of about three minutes near
30%, 45% and sometimes 75%. Requested percentage and achieved operation therefore
need not coincide. Availability of those values in Symcon is not established;
manual documentation of a display does not imply an existing network-readable API.

Sources (manufacturer-authored material, including distributor-hosted copies):

- Sinclair model data: https://www.sinclair.pl/oferta/agregaty-skraplajace/dc-inverter/asge-48bi3--scmi-014.html
- Sinclair product-data sheet, **PDF page 16** for ASF-48BI pairing (page 25 has a different ASD pairing): https://www.ch4you.cz/user/related_files/product_data_sheet_sinclair_uni_split_asge-xxbi.pdf
- Sinclair service manual: https://www.sinclair.pl/static/uploaded_files/media-11-2018/dtr-uni-split.pdf
- SCMI-01.4 user manual, especially PDF pages 7 and 11: https://sinclair.ee/wp-content/uploads/2023/08/sinclair-um-scmi-01.4-v2.2-sw-2.06-en-v01-1-1.pdf
- Owner's supplied SINCLAIR_9_1DV_2020_web.pdf: the apparent capacity graphs are **cooling** graphs at fixed condensing conditions, not winter heating/capacity-command curves.

## Proposed next learning step

Use outside temperature for the initial capacity estimate, then room target error
and temperature trend to increase/decrease it slowly. Verify warm air and respect
ramp/defrost/start limits. Explore a small number of operating points (initially
35%, 50%, then intermediate higher points if needed), holding fan/flap strategy and
room targets comparable. Exclude warm-up, cooling buildings, mixed-source/radiator
loads and transient/defrost intervals from steady comparisons, but include their
energy in whole-cycle economics. Choose the lowest combined-energy strategy that
maintains all target rooms, not merely the lowest compressor input.

Additional useful archive data: humidifier status **17095** and switching command
**25240** (their instances are 16275 and 13954); these document the owner's off
condition. If accessible, add actual compressor status/capacity and defrost/error
feedback. Log room targets, flap commands, fan speed and HP/supply/inlet/outlet
sensors alongside the existing power/cumulative meters. Existing temperature rise
is a diagnostic proxy; delivered heat needs calibrated airflow as well.

For gas-vs-HP economics, identical fan energy can cancel **only when the two sources
actually require the same fan energy to provide the same useful heating**. Different
runtime prevents that cancellation. The current daily learner already includes
both circuits, so keeping fan electricity requires no new tariff input.

See the [controlled test proposal](heatpump-test-design.md) for block timing,
weather repeats, measurements and exclusive controller ownership.
