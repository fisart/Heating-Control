#!/usr/bin/env python3
"""Offline replay of all October 2026 runs. Never accesses equipment.

Usage: python replay-heatpump-raw.py RAW_DIRECTORY ARCHIVE14_JSONL OUTPUT_DIRECTORY
Native change-only temperatures are held, not age-filtered.
"""
import argparse, bisect, hashlib, json, math
from pathlib import Path

VERSION='provisional-heatpump-surface-2026-10-23-v4'
def dump(p,x):
    p.write_text(json.dumps(x,indent=2,ensure_ascii=False,allow_nan=False)+'\n')
def replay(samples):
    events=[]
    start=samples[0]['timestamp']
    for i,s in enumerate(samples):
        now=s['timestamp'];rise=s['outgoing']-s['incoming'];lift=s['coil']-s['incoming']
        if events and events[-1]['endedAt'] is None:
            e=events[-1]
            good=rise>=max(5,.8*e['referenceAirRise']) and lift>=max(5,.6*e['referenceCoilLift'])
            if not good:
                e.update(recoverySince=None,recoveredAt=None,releaseAt=None)
            else:
                if e['recoverySince'] is None:e['recoverySince']=now
                recent=[r for r in samples[:i+1] if r['timestamp']>=max(e['recoverySince'],now-120)]
                rises=[r['outgoing']-r['incoming'] for r in recent];coils=[r['coil'] for r in recent]
                if max(rises)-min(rises)>2 or max(coils)-min(coils)>3:
                    e.update(recoverySince=now,recoveredAt=None,releaseAt=None)
                elif e['recoveredAt'] is None and now-e['recoverySince']>=120:
                    e.update(recoveredAt=now,releaseAt=now+120)
                if e['releaseAt'] is not None and now>=e['releaseAt']:e['endedAt']=e['releaseAt']
            continue
        after=events[-1]['endedAt'] if events else start
        history=[r for r in samples[:i+1] if max(after,now-300)<=r['timestamp']<=now-30]
        if len(history)<6 or history[-1]['timestamp']-history[0]['timestamp']<50:
            if not events or now-after>300:continue
            old=events[-1];ref=dict(timestamp=after,incoming=old['referenceOutgoing']-old['referenceAirRise'],outgoing=old['referenceOutgoing'],coil=old['referenceCoil'])
        else:
            ordered=sorted(history,key=lambda r:r['outgoing']-r['incoming']);ref=ordered[math.floor(.8*(len(ordered)-1))]
        rr=ref['outgoing']-ref['incoming'];rl=ref['coil']-ref['incoming']
        if rr<5 or rl<10 or rr-rise<max(8,.4*rr) or ref['outgoing']-s['outgoing']<6 or ref['coil']-s['coil']<8:continue
        healthy=max([ref['timestamp']]+[r['timestamp'] for r in history if abs(r['outgoing']-r['incoming']-rr)<=max(2,.1*rr) and abs(r['coil']-ref['coil'])<=max(3,.1*rl)])
        events.append(dict(classification='suspected-defrost-like-temperature-transient',confirmedDefrost=False,
            startedAt=max(start,healthy-60),onsetReferenceAt=healthy,detectedAt=now,referenceAirRise=rr,referenceCoilLift=rl,
            referenceOutgoing=ref['outgoing'],referenceCoil=ref['coil'],detectedAirRise=rise,detectedOutgoing=s['outgoing'],
            detectedCoil=s['coil'],recoverySince=None,recoveredAt=None,releaseAt=None,endedAt=None))
    return events
def complement(events,a,b):
    cursor=a;out=[]
    for e in events:
        x=max(a,e['startedAt']);y=min(b,e['endedAt'] or b)
        if y<=x:continue
        if x>cursor:out.append(dict(startedAt=cursor,endedAt=x))
        cursor=max(cursor,y)
    if cursor<b:out.append(dict(startedAt=cursor,endedAt=b))
    return out
def mean(samples,key,intervals):
    ts=[r['timestamp'] for r in samples];total=0;seconds=0
    for v in intervals:
        a,b=v['startedAt'],v['endedAt'];bounds=[a]+[t for t in ts if a<t<b]+[b]
        for x,y in zip(bounds,bounds[1:]):
            i=bisect.bisect_right(ts,x)-1
            assert i>=0 and x-ts[i]<=30,'Uncovered thermal boundary'
            total+=samples[i][key]*(y-x)
        seconds+=b-a
    return total/seconds
def counter_at(records,t):
    ts=[x for x,y in records];i=bisect.bisect_left(ts,t)
    if i<len(records) and ts[i]==t:return records[i][1]
    assert 0<i<len(records),f'Unbracketed counter boundary {t}, range {ts[0]}..{ts[-1]}'
    a,v=records[i-1];b,w=records[i]
    assert b-a<=90 and w>=v,'Counter gap/reset'
    return v+(w-v)*(t-a)/(b-a)
def energy(records,intervals,scale):
    return sum((counter_at(records,x['endedAt'])-counter_at(records,x['startedAt']))*scale for x in intervals)
def main():
    ap=argparse.ArgumentParser();ap.add_argument('raw',type=Path);ap.add_argument('archive',type=Path);ap.add_argument('out',type=Path);a=ap.parse_args();a.out.mkdir(parents=True,exist_ok=True)
    archive=[json.loads(s) for s in a.archive.read_text().splitlines() if s.strip()]
    morning_counter=sorted((r['timestamp'],r['value']) for r in archive if r['kind'] in ('baseline','sample') and r.get('id')==38023)
    points=[];runs=[];sources=[]
    for file in sorted(a.raw.glob('202610*.jsonl')):
        records=[json.loads(s) for s in file.read_text().splitlines()];report=records[-1]['report'];rid=report['runID']
        samples=[r['sample'] for r in records if r['kind']=='sample'];step=[r['sample'] for r in records if r['kind']=='sample' and r['phase']=='step']
        events=replay(step);original={p['powerPercent']:p for p in report['points']}
        groups={}
        for s in step:groups.setdefault(s.get('powerPercent',30),[]).append(s)
        source=dict(filename=file.name,sha256=hashlib.sha256(file.read_bytes()).hexdigest());sources.append(source)
        runs.append(dict(runID=rid,sampleCount=len(samples),complete=report['complete'],reason=report['reason'],events=events,source=source))
        dedicated=report.get('electricalMethod')=='dedicated-heatpump-import-counter'
        legacy=rid.startswith('20261013')
        if dedicated:
            values={s['importUpdated']:s['meterCounter'] for s in samples}
            exports={s['exportUpdated']:s['meterExportCounter'] for s in samples}
            for op in original.values():
                for s in op.get('meterBoundaries',[]):
                    values[s['importUpdated']]=s['meterCounter'];exports[s['exportUpdated']]=s['meterExportCounter']
            counters=sorted(values.items());export_counters=sorted(exports.items());scale=1;base=0
        elif rid.startswith('20261014-031310'):
            counters=morning_counter;scale=.001;b=report['startedAt'];base=(counter_at(counters,b)-counter_at(counters,b-900))*.004
        elif not legacy:
            # Original raw file samples the held house-counter total every ~8 s.
            # Interpolate sampled values, do not invent native change timestamps.
            counters=sorted({s['timestamp']:s['houseCounter'] for s in samples}.items());scale=.001;base=report['baseline']['houseKW']
        previous_end=step[0]['timestamp']
        for power,ss in groups.items():
            old=original.get(power);start=old['stepStartedAt'] if old else previous_end
            end=old['stepEndedAt'] if old else ss[-1]['timestamp'];previous_end=end
            intervals=complement(events,start,end);seconds=sum(x['endedAt']-x['startedAt'] for x in intervals)
            if not seconds:continue
            means={k:mean(samples,k,intervals) for k in ('outside','incoming','outgoing','coil','target')}
            rise=means['outgoing']-means['incoming'];heat=.67*rise
            p=dict(pointID=rid+'-'+str(power),runID=rid,powerPercent=power,measuredAt=start,**means,
                airRise=rise,fullPeriodAirRise=rise,coilLift=means['coil']-means['incoming'],estimatedHeatKW=heat,
                estimatedHeatKWh=heat*seconds/3600,stable=True,usable=True,originalStable=old.get('stable') if old else None,
                originalUsable=old.get('usable') if old else None,completedStep=old is not None,fanSpeed=90,airflowM3h=2000,airflowIsMeasured=False,
                excludedFlapID=40259,calibrationContext='master-bedroom-closed-other-flaps-open',stepStartedAt=start,stepEndedAt=end,
                measurementIntervals=intervals,measurementSeconds=seconds,excludedSeconds=end-start-seconds,
                excludedIntervals=[e for e in events if e['startedAt']<end and (e['endedAt'] or end)>start],
                measurementBasis='all-recorded-heating-time-except-evidenced-thermal-events',electricalQualified=not legacy,
                reviewNote='Owner accepts all temperatures, warm-up and partial runs; only joint-collapse event brackets removed. Original stability flags are diagnostic.')
            if legacy:
                p.update(electricalMethod='legacy-overlapping-instantaneous-meters',systemInputKW=None,estimatedSystemCOP=None,
                    electricalNote='Original overlapping instantaneous meters are preserved in the private raw log. No matched household/import counter covers this run; used for thermal fit and capacity, without inventing COP.')
            else:
                if not dedicated and not rid.startswith('20261014-031310') and old and 'grossElectricityKWh' in old:
                    # Finalized original endpoints remain authoritative; only internal
                    # event brackets need raw snapshot counter interpolation.
                    excluded=complement([dict(startedAt=x['startedAt'],endedAt=x['endedAt']) for x in intervals],start,end)
                    gross=old['grossElectricityKWh']-energy(counters,excluded,scale)
                else:gross=energy(counters,intervals,scale)
                net=gross-base*seconds/3600;kw=net*3600/seconds
                assert net>0
                p.update(systemInputKW=kw,estimatedSystemCOP=heat/kw,netElectricityKWh=net,grossElectricityKWh=gross,
                    baselineKW=base,counterScale=scale,counterID=22365 if dedicated else 38023,
                    electricalMethod='dedicated-heatpump-import-counter' if dedicated else 'house-counter-minus-pretest-baseline',
                    counterFinalized=True,lightingCorrectionApplied=False)
                if dedicated:p.update(powerMeterID=28681,exportCounterID=57639,exportElectricityKWh=energy(export_counters,intervals,1),baselineSubtracted=False,
                    meterScope='dedicated heat-pump circuit; equipment outside this circuit is excluded')
                else:p['counterBoundaryBasis']='native archive timestamps' if rid.startswith('20261014-031310') else 'linearly interpolated timestamped raw counter snapshots (approximately 8-second sampling)'
            points.append(p)
    dump(a.out/'heatpump-replayed-candidates.json',dict(schema=1,points=points,sourceFiles=sources))
    dump(a.out/'heatpump-calibration-audit.json',dict(schema=1,runs=runs,sourceFiles=sources))
if __name__=='__main__':main()
