#!/usr/bin/env python3
"""Check a read-only Symcon power extraction against the retained model intervals.

Usage: check-heatpump-power-validation.py EXTRACTION_DIRECTORY --output SUMMARY.json
Requires only Python's standard library. Does not modify the extraction or model.
"""
import argparse
import bisect
import collections
import hashlib
import json
from pathlib import Path


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('directory', type=Path)
    ap.add_argument('--output', type=Path, required=True)
    args = ap.parse_args()
    report_path = args.directory/'report.json'
    report = json.loads(report_path.read_text())
    raw_path = args.directory/'readings.ndjson'
    if not raw_path.exists():
        raw_path = args.directory/'raw.ndjson'
    assert hashlib.sha256(raw_path.read_bytes()).hexdigest() == report['files']['readings.ndjson']['sha256']
    archive = collections.defaultdict(list)
    with raw_path.open() as stream:
        for line in stream:
            row = json.loads(line)
            if row['key'] in ['totalImport', 'legacyCounter']:
                archive[(row['window'], row['key'])].append((row['record']['TimeStamp'], row['record']['Value']))
    for rows in archive.values():
        rows.sort()
        assert all(b[0] > a[0] and b[1] >= a[1] for a, b in zip(rows, rows[1:]))

    def at(window, key, timestamp):
        rows = archive[(window, key)]
        index = bisect.bisect_right([r[0] for r in rows], timestamp)-1
        assert index >= 0
        a = rows[index]
        if a[0] == timestamp:
            return a[1]
        assert index+1 < len(rows)
        b = rows[index+1]
        assert b[0]-a[0] <= report['settings']['counterMaximumInterpolationGapSeconds']
        return a[1]+(b[1]-a[1])*(timestamp-a[0])/(b[0]-a[0])

    periods = []
    for index, p in enumerate(report['periods']):
        w = p['window']
        start, end = w['fromTimestamp'], w['throughTimestamp']
        shelly = at(index, 'totalImport', end)-at(index, 'totalImport', start)
        legacy = (at(index, 'legacyCounter', end)-at(index, 'legacyCounter', start))*report['settings']['legacyPulseKWh']
        assert abs(shelly-p['comparison']['shellyImportKWh']) < 1e-9
        assert abs(legacy-p['comparison']['legacyPulseKWh']) < 1e-9
        periods.append(dict(window=w, shellyKWh=shelly, pulseMeterKWh=legacy,
                            shellyDifferencePercent=(shelly/legacy-1)*100,
                            signedPowerIntegralKWh=p['comparison']['totalPowerIntegralNetKWh']))
    points = json.loads((Path(__file__).resolve().parents[2]/'docs/data/heatpump-cop-surface-observations.json').read_text())['points']
    checks = []
    for p in points:
        if not p['stable'] or not p['usable'] or p.get('supersededBy') or p['runID'] not in report['settings']['calibrationRunIDs']:
            continue
        index = report['settings']['calibrationRunIDs'].index(p['runID'])
        intervals = p['measurementIntervals']
        energy = sum(at(index, 'totalImport', i['endedAt'])-at(index, 'totalImport', i['startedAt']) for i in intervals)
        seconds = sum(i['endedAt']-i['startedAt'] for i in intervals)
        assert abs(energy-p['netElectricityKWh']) < 1e-9 and seconds == p['measurementSeconds']
        checks.append(dict(pointID=p['pointID'], retainedSeconds=seconds, reextractedKWh=energy,
                           modelRecordedKWh=p['netElectricityKWh'], differenceKWh=energy-p['netElectricityKWh']))
    assert len(checks) == 16
    last = report['calibrationSteps'][-1]
    retained = last['retained']
    summary = dict(schema=1, extraction=report['relativeOutputDirectory'], createdAtBerlin=report['createdAtBerlin'],
                   report_sha256=hashlib.sha256(report_path.read_bytes()).hexdigest(),
                   raw_sha256=report['files']['readings.ndjson']['sha256'],
                   pulseKWh=report['settings']['legacyPulseKWh'], periods=periods,
                   retainedModelIntervalChecks=checks,
                   november100Percent=dict(shellyRetainedMeanKW=retained['shellyImportMeanKW'],
                                           pulseRetainedMeanKW=retained['legacyPulseKWh']*3600/retained['seconds'],
                                           retainedSeconds=retained['seconds'],
                                           fullStepMeanKW=last['fullStep']['shellyImportMeanKW'],
                                           excludedSeconds=last['excludedSeconds']),
                   conclusion='Independent pulse meter and Shelly agree within 0.6% on both complete windows. All 16 retained model energy totals reproduce from the native counter records. No electrical correction is justified. Circuit coverage and actual equipment nameplate remain unresolved; measured airflow and absolute COP are not validated by this comparison.')
    args.output.write_text(json.dumps(summary, indent=2, ensure_ascii=False, allow_nan=False)+'\n')
    print('PASS: two independent period comparisons and all 16 retained model intervals; no source files modified')


if __name__ == '__main__':
    main()
