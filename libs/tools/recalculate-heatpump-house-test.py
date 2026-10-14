#!/usr/bin/env python3
"""Rebuild house-meter calibration; no actuator access. Inputs are owner exports."""
import argparse, bisect, gzip, json
p=argparse.ArgumentParser();p.add_argument('archive');p.add_argument('report');p.add_argument('readings');p.add_argument('output');a=p.parse_args()
with gzip.open(a.archive,'rt') as f: archive=[json.loads(l) for l in f if l.strip()]
report=json.load(open(a.report));raw=[json.loads(l)['sample'] for l in open(a.readings) if l.strip() and json.loads(l)['kind']=='sample'];raw.sort(key=lambda r:r['timestamp'])
counters=sorted((r['timestamp'],r['value']) for r in archive if r['kind'] in ['baseline','sample'] and r.get('id')==38023)
ct=[t for t,v in counters];rt=[r['timestamp'] for r in raw]
def counter(t):
 i=bisect.bisect_left(ct,t)
 if i<len(ct) and ct[i]==t:return counters[i][1]
 if i==0 or i==len(ct):raise ValueError('Unbracketed counter boundary')
 t0,v0=counters[i-1];t1,v1=counters[i]
 if t1-t0>90 or v1<v0:raise ValueError('Counter gap/reset')
 return v0+(v1-v0)*(t-t0)/(t1-t0)
def mean(key,start,end):
 bounds=[start]+[t for t in rt if start<t<end]+[end]
 return sum(raw[bisect.bisect_right(rt,t0)-1][key]*(t1-t0) for t0,t1 in zip(bounds,bounds[1:]))/(end-start)
bend=report['startedAt'];bstart=bend-900;base=(counter(bend)-counter(bstart))*.001*4
rows=[]
for point in report['points']:
 start=point['stepStartedAt'];end=start+900
 gross=(counter(end)-counter(start))*.001*4;net=gross-base
 means={k:mean(k,start,end) for k in ['outside','incoming','outgoing','coil','target']}
 rise=means['outgoing']-means['incoming'];heat=.000335*report['airflowM3h']*rise
 rows.append(dict(pointID=report['runID']+'-'+str(point['powerPercent']),runID=report['runID'],measuredAt=start,powerPercent=point['powerPercent'],**means,
 airRise=point['airRise'],coilLift=point['coilLift'],fullPeriodAirRise=rise,steadyIncoming=point['incoming'],steadyOutgoing=point['outgoing'],
 systemInputKW=net,estimatedHeatKW=heat,estimatedSystemCOP=heat/net,stable=point['stable'],usable=point['usable'],
 fanSpeed=report['fanSpeed'],excludedFlapID=report['excludedFlapID'],airflowM3h=report['airflowM3h'],airflowIsMeasured=False,
 electricalMethod='house-counter-minus-pretest-baseline',counterID=38023,counterScale=.001,baselineKW=base,baselineSeconds=900,measurementSeconds=900,
 grossElectricityKWh=gross/4,netElectricityKWh=net/4,estimatedHeatKWh=heat/4,lightingCorrectionApplied=False,
 stability=point['stability'],basis='full-period matched heat/electricity; final-window temperature stability'))
# Publish only the derived performance matrix; raw timestamps/counter totals,
# house baseline and room histories stay in the owner's local input exports.
keys=['powerPercent','outside','incoming','outgoing','coil','airRise','coilLift','systemInputKW','estimatedHeatKW','estimatedSystemCOP','stable','usable','fanSpeed','airflowM3h','electricalMethod']
published=[]
for row in rows:
 item={k:row[k] for k in keys};item['pointID']='reviewed-calibration-'+str(row['powerPercent']);item['calibrationContext']='master-bedroom-closed-other-flaps-open';published.append(item)
model=dict(schema=2,note='Derived equipment-performance points only; estimated airflow; whole-period matched heat/electricity.',points=published)
with open(a.output,'w') as f:json.dump(model,f,indent=2);f.write('\n')
for r in rows:print(f"{r['powerPercent']:3}% outside {r['outside']:.2f} intake {r['incoming']:.2f} net {r['systemInputKW']:.4f} COP {r['estimatedSystemCOP']:.4f} usable {r['usable']}")
