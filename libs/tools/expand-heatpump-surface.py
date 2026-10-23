#!/usr/bin/env python3
"""Expand an existing reviewed matrix without changing historical measurements.

python expand-heatpump-surface.py PRIOR_CALIBRATION PRIOR_SURFACE_OBSERVATIONS ADDITIONS OUTPUT_DIRECTORY
ADDITIONS contains reviewed points, thermal-only observations, evidence and supersessions.
Dependencies: numpy, scipy. No actuator or live-system access.
"""
import argparse, copy, hashlib, json
from pathlib import Path
import numpy as np
from scipy.interpolate import RBFInterpolator
from scipy.spatial import ConvexHull, Delaunay, distance

def dump(path,obj):path.write_text(json.dumps(obj,indent=2,ensure_ascii=False,allow_nan=False)+'\n')
def norm(t,p):return np.column_stack(((np.asarray(t)-10.5)/5.5,(np.asarray(p)-65)/35))
def phi(d):return np.where(d>0,d*d*np.log(np.maximum(d,1e-300)),0)
def fit(x,y):
    p=np.column_stack((np.ones(len(x)),x));a=np.block([[phi(distance.cdist(x,x)),p],[p.T,np.zeros((3,3))]])
    return np.linalg.solve(a,np.r_[y,np.zeros((3,y.shape[1]))])
def predict(x,c,q):return np.column_stack((phi(distance.cdist(q,x)),np.ones(len(q)),q))@c
def main():
    a=argparse.ArgumentParser();a.add_argument('prior',type=Path);a.add_argument('observations',type=Path);a.add_argument('additions',type=Path);a.add_argument('out',type=Path);a=a.parse_args();a.out.mkdir(parents=True,exist_ok=True)
    prior=json.loads(a.prior.read_text());old_obs=json.loads(a.observations.read_text());add=json.loads(a.additions.read_text())
    rows=copy.deepcopy(prior['points']);old_runs={p['pointID']:p['runID'] for p in old_obs['points']}
    for p in rows:
        p['runID']=old_runs[p['pointID']]
        if p['pointID'] in add['supersessions']:p['supersededBy']=add['supersessions'][p['pointID']]
    rows+=add['points'];ids=[p['pointID'] for p in rows];assert len(ids)==len(set(ids))
    for old,new in zip(prior['points'],rows):
        for k,v in old.items():assert new[k]==v,f'Historical field changed: {old["pointID"]}/{k}'
    source=dict(schema=4,createdAtBerlin='2026-10-23',points=rows,thermalOnlyObservations=add['thermalOnlyObservations'],
        sourceFiles=add['sourceFiles'],notes=add['notes'],preservedHistoricalRows=len(prior['points']))
    dump(a.out/'heatpump-calibration.json',dict(schema=4,note='Existing 30 rows preserved; reviewed additions expand the matrix. Superseded rows remain auditable.',points=rows))
    dump(a.out/'heatpump-cop-surface-observations.json',source)
    points=[p for p in rows if p['stable'] and p['usable'] and not p.get('supersededBy')]
    x=norm([p['outside'] for p in points],[p['powerPercent'] for p in points]);y=np.array([[p['outgoing']-p['incoming'],p['systemInputKW'],p['incoming']] for p in points]);c=fit(x,y)
    np.testing.assert_allclose(predict(x,c,x),y,atol=1e-9)
    temps=np.round(np.arange(3.25,16.001,.25),2);powers=np.arange(30,101);t,p=np.meshgrid(temps,powers,indexing='ij');q=norm(t.ravel(),p.ravel());v=predict(x,c,q).reshape(t.shape+(3,))
    np.testing.assert_allclose(v.reshape(-1,3),RBFInterpolator(x,y,kernel='thin_plate_spline',smoothing=0,degree=1)(q),atol=1e-8)
    rise,electric,inlet=v[:,:,0],v[:,:,1],v[:,:,2];cop=.67*rise/electric
    assert np.isfinite(v).all() and rise.min()>0 and electric.min()>.05 and .5<cop.min() and cop.max()<10
    runids=np.array([p['runID'] for p in points]);errors=[];rise_errors=[];cv=[]
    for rid in sorted(set(runids)):
        tr=runids!=rid;te=~tr;pred=predict(x[tr],fit(x[tr],y[tr]),x[te]);obs=y[te]
        assert pred[:,1].min()>.05
        e=.67*pred[:,0]/pred[:,1]-.67*obs[:,0]/obs[:,1];er=pred[:,0]-obs[:,0];errors.extend(e);rise_errors.extend(er)
        cv.append(dict(heldOutRun=rid,points=int(te.sum()),rmseCOP=float(np.sqrt(np.mean(e**2))),rmseAirRiseC=float(np.sqrt(np.mean(er**2)))))
    model=json.loads((Path(__file__).resolve().parents[2]/'docs/data/heatpump-cop-surface.json').read_text())
    inside=Delaunay(x).find_simplex(q,tol=1e-10)>=0
    model.update(version='provisional-heatpump-surface-2026-10-23-v4',centers_normalized=x.tolist(),coefficients=c.tolist(),
        source_sha256=hashlib.sha256((a.out/'heatpump-cop-surface-observations.json').read_bytes()).hexdigest(),support_hull_normalized=x[ConvexHull(x).vertices].tolist())
    model['domain'].update(outside_min_C=3.25,outside_max_C=16)
    model['status']='Provisional expanded runtime surface; established zero-smoothing method retained; measured-point mode remains default'
    model['reference_basis']['incoming_air_range_C']=[min(p['incoming'] for p in points),max(p['incoming'] for p in points)]
    model['metrics']=dict(accepted_points=len(points),preserved_historical_rows=len(prior['points']),recorded_rows=len(rows),
        fitted_runs=len(set(runids)),recorded_runs=6,thermal_only_points=len(add['thermalOnlyObservations']),
        dedicated_meter_points=sum(p['electricalMethod']=='dedicated-heatpump-import-counter' for p in points),
        legacy_house_meter_points=sum(p['electricalMethod']=='house-counter-minus-pretest-baseline' for p in points),
        leave_one_run_out_rmse_COP=float(np.sqrt(np.mean(np.square(errors)))),leave_one_run_out_rmse_air_rise_C=float(np.sqrt(np.mean(np.square(rise_errors)))),
        grid_cells=int(cop.size),inside_hull_cells=int(inside.sum()),extrapolation_cells=int((~inside).sum()),leave_one_run_out=cv)
    model['update_policy']='Append reviewed observations; preserve original measured fields. Existing thin-plate/affine zero-smoothing method retained. Only event-overlapping historical 100% row is superseded by its filtered derivative.'
    model['limitations']=['Estimated fixed airflow, mixed electrical circuit scopes, sequential warm-up and sparse weather coverage.','Thermal-only earliest run supports capacity checks but has no invented electrical input or COP.','Event-filtered normal heating; not seasonal or complete-defrost-cycle COP.']
    dump(a.out/'heatpump-cop-surface.json',model)
    dump(a.out/'heatpump-surface-matrix.json',dict(schema=2,modelVersion=model['version'],outside_temperature_C=temps.tolist(),power_percent=powers.tolist(),
        estimated_COP=cop.tolist(),estimated_air_rise_C=rise.tolist(),estimated_heat_kW=(.67*rise).tolist(),estimated_electrical_input_kW=electric.tolist(),
        descriptive_reference_inlet_C=inlet.tolist(),support=np.where(inside.reshape(t.shape),'interpolation','extrapolation').tolist()))
    print(json.dumps(model['metrics'],indent=2))
if __name__=='__main__':main()
