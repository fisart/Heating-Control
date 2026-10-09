# Berlin installation cross-check (26 September 2026)

The screenshot of script 30716 was compared with the exported installation
inventory. All 36 external links resolve to the target IDs preconfigured in
`HeatingControl/module.php`; the separate master disable variable 11098 is
also preconfigured. The module does not write commands to link IDs.

| Old link/group | External target IDs used by the module |
| --- | --- |
| Conditions | winter 47386; night disable 20141; holiday 46970; at home 11637 (reference only); master disable 11098 |
| Other inputs | operating mode 41085; hysteresis 58771; residual delta 28538; outside 25911 (reference only); outgoing air 40843; incoming air 15315; heat exchanger 54400; heat pump temperature 58696 |
| Fan | on/off 46921; speed command 29718; speed setting 48646 |
| Heat pump | on/off 35931; power command 19207; power setting 59800; heat/cool 51011 |
| Gas | mixer 14348; pump 32875; target flow command 14533; heating setpoint 46820; idle setpoint 11595 |
| Living and Dining Room | sensor 57694; target 12594; flap 36911 |
| Guest Bedrooms | sensor 44502; target 40975; flap 22150 |
| Master Bedroom | sensor 15880; target 30481; flap 40259 |
| Blue Room and Kitchen | sensor 57255; target 37341; flap 27527 |

The external *settings* remain live variables. For example, the residual
delta 28538 was 6 K at the 13:09 export and 8 K in the later screenshot;
the module reads its current value on each evaluation rather than copying
the snapshot value into a fixed setting.

## Existing internal objects and dependencies

| Old object IDs | Role | Migration implication |
| --- | --- | --- |
| 14665, 46039, 53777, 10485 | `DemandActive` latches in the four room groups | The module keeps its own latch attribute. Its first decision inside the hysteresis band can differ until a threshold is crossed. |
| 14234, 27988, 20059, 11839 | `PreviousTemp` variables | Preserved beneath the old script; the supplied script does not update them. |
| 49037, 51626, 57981, 39468 | `PurgeActive` variables | Preserved beneath the old script; the supplied script does not update them. |
| 53400, 50623, 43898, 36744 | Blue/Kitchen, Guest, Living/Dining, Master room demand statuses | Since 0.1.2 these are optional default outputs in the room editor. Updated only in live operation, preserving existing views/charts. |
| 57844, 52602, 21945 | Residual heating, last heated group, action log | Since 0.1.2 configurable default outputs, in addition to internal instance state. The external log retains HTML formatting. |
| 29352 | Configuration snapshot JSON | Since 0.1.2 optional default backup destination; live-mode exports replace the one-time inventory with portable module configuration. Not a live control input. |
| 32844 | Separate `Heating Status Report` script with an error marker in the screenshot | Inspect its references and error before retiring old status variables. |
| 16528, 49690 | Heating and residual heat charts | Check their data sources after handover. |
| 52453, 52255 | Action scripts below the fan speed and residual delta settings | Continue to belong to the external settings; their IDs are not module properties. |

The last screenshot shows 14 `AutoTrigger` events and the periodic event 17426
under the old script. They remain active during the module's dry run. Disable
all events that execute script 30716 before allowing the module to send real
commands. Event 54580 belongs to `IS_AT_HOME` and must be assessed separately;
it is not an event of script 30716.

The old mixer variable is an integer with 0=open and 100=closed in the
configuration. Confirm actual hardware behavior before enabling writes,
because the old heat-pump path supplied Boolean `true` to that integer output.

## Original room category IDs for status compatibility

`LastHeatedGroupID` stores a room category ID, not a sensor or status-variable
ID. Verified categories are Blue/Kitchen **36698**, Guest **38782**,
Living/Dining **25055**, and Master **16188**. They are configurable per room.
The status objects above are variables, not links; their action markers do not
mean that custom action scripts should be called to publish status.

In DryRun, no old status variables or snapshots are changed. Night/cooling
shutdown clears external room-demand and residual Booleans; summer and master
bypasses leave them untouched. Missing/wrong-type mappings or collisions with
live inputs/actuators cause evaluation to stop before commands. Clear optional
selectors to disable individual outputs.
