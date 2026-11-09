# Initial cold-sensor selection

One-time selection from the 8 November 2026 settings snapshot. Sensors older than one hour in that snapshot and explicitly faulty sensors were excluded. Equal readings use the lowest object ID. A cold air sensor is not a wall-temperature measurement. The new module uses these fixed defaults; changing readings never changes the selected sensor.

| Floor | Room | Temperature ID | Source | Snapshot temperature |
|---|---|---:|---|---:|
| Basement | Electronic office | 42622 | KNX presence sensor | 14.8 °C |
| Basement | Penny’s office | 42464 | HomeMatic room sensor | 15.0 °C |
| Basement | Computer room | 29639 | FRITZ radiator | 16.0 °C |
| Basement | Gym | 50081 | FRITZ radiator | 14.0 °C |
| Basement | Washroom | 17086 | FRITZ radiator | 16.0 °C |
| Basement | Basement toilet | 33010 | HomeMatic room sensor | 15.0 °C |
| Ground floor | Living room | 57694 | HomeMatic room sensor | 13.5 °C |
| Ground floor | Kitchen | 14900 | FRITZ socket sensor | 14.0 °C |
| Ground floor | Blue room | 48084 | FRITZ socket sensor | 13.5 °C |
| Ground floor | Guest bathroom GF | 45558 | HomeMatic room sensor | 15.8 °C |
| First floor | Master bedroom | 42946 | HomeMatic room sensor | 12.2 °C |
| First floor | Master bathroom | 59814 | Shelly radiator | 12.9 °C |
| First floor | Large guest bedroom | 44502 | HomeMatic room sensor | 12.6 °C |
| First floor | Small guest bedroom | 10190 | HomeMatic room sensor | 13.0 °C |
| First floor | Guest bathroom FF | 34297 | HomeMatic room sensor | 12.6 °C |

Kitchen has no mapped humidity sensor. Its air-branch humidity is explicitly borrowed from blue room, together with that sensor’s matching temperature. Its own selected cold sensor still evaluates the colder point. Review this proxy in Admin. Rooms without a known duct branch remain monitoring-only.

Rooms with a required temperature, humidity or cold-point sensor set to 0 are removed from the saved configuration on Apply Changes. Optional surface sensor and duct-branch IDs may remain 0. Removed defaults: Heating room, Basement hallway, Entrance hall, Dining room, Storeroom, Dressing room. Fifteen configured rooms remain.
