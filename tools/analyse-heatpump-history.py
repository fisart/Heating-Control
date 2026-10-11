import json, numpy as np, datetime as dt, collections, argparse
parser=argparse.ArgumentParser(description='Diagnostic reconstruction of Symcon heat-pump/fan states and reported circuit power; not a COP estimator.')
parser.add_argument('export',help='January–February 2025 heating-history JSONL export')
args=parser.parse_args()
from zoneinfo import ZoneInfo
ids=[35702,47831,35931,51011,19207,46921,29718,41085,25911,15315,40843,32875]
rows={i:[] for i in ids}
for line in open(args.export):
 r=json.loads(line)
 if r.get('id') in rows and r['record'] in ['raw_baseline','raw_chunk']:rows[r['id']]+=r['rows']
a=int(dt.datetime(2025,1,1,tzinfo=ZoneInfo('Europe/Berlin')).timestamp()); b=a+42*86400
step=10;t=np.arange(a,b,step);v={};age={}
for i,rr in rows.items():
 rr=sorted(rr,key=lambda r:r['TimeStamp']);tt=np.array([r['TimeStamp'] for r in rr]);vv=np.array([r['Value'] for r in rr]);ix=np.searchsorted(tt,t,side='right')-1;v[i]=vv[ix];age[i]=t-tt[ix]
hp=(v[35931]==1)&(v[41085]==1)&(v[51011]==1); fan=v[46921]==1
fresh=(age[35702]<=900)&(age[47831]<=900)&(age[25911]<=7200)
for cap in [35,50,100]:
 m=hp&(abs(v[19207]-cap)<=1)&fresh; mf=m&fan
 p=v[35702][m];aux=v[47831][mf]
 print('CAP',cap,'hours',m.sum()*step/3600,'outsideMean',v[25911][m].mean(),'outsideRange',np.quantile(v[25911][m],[.1,.9]).round(2),'circuitKW',p.mean()/1000,'circuitP10med90',np.quantile(p,[.1,.5,.9]).round(0),'auxWhenFanKW',aux.mean()/1000,'fanFraction',mf.sum()/max(m.sum(),1),'fanSpeed',np.unique(v[29718][mf],return_counts=True))
 for lo,hi in [(-10,0),(0,3),(3,6),(6,12)]:
  x=m&(v[25911]>=lo)&(v[25911]<hi)
  if x.sum():print(' bin',lo,hi,'hours',x.sum()*step/3600,'powerKW',v[35702][x].mean()/1000)
# comparable off load, pure gas heating fan at90, fan off both HP and gaspump off
for name,m in [('off',~hp&~fan&(v[32875]==0)&fresh),('gasFan90',(v[41085]==2)&~hp&fan&(v[29718]==90)&fresh),('hpFan90',hp&fan&(v[29718]==90)&fresh),('allHP',hp&fresh)]:
 print(name,'hours',m.sum()*step/3600,'hpCircuitKW',v[35702][m].mean()/1000,'auxCircuitKW',v[47831][m].mean()/1000,'auxQuantiles',np.quantile(v[47831][m],[.1,.5,.9]).round(1))
# sessions contiguous commanded on
edges=np.diff(np.r_[False,hp,False].astype(int));starts=np.flatnonzero(edges==1);ends=np.flatnonzero(edges==-1)
print('sessions',len(starts),'days',sorted(set(dt.datetime.fromtimestamp(int(t[x]),ZoneInfo('Europe/Berlin')).strftime('%Y-%m-%d') for x in starts)))
for x,y in zip(starts,ends):
 if (y-x)*step>=1800:print('long',dt.datetime.fromtimestamp(int(t[x]),ZoneInfo('Europe/Berlin')).isoformat(),(y-x)*step/60,'capacity',np.quantile(v[19207][x:y],[0,.5,1]),'kw',v[35702][x:y].mean()/1000,'outside',v[25911][x:y].mean())


# Consumption is calculated from cumulative counters, not historic prices.
# Linear allocation between adjacent pulse readings approximates short boundary intervals.
for counter_id,label in [(12254,'Heat-pump circuit'),(10054,'Fan/heating circuit')]:
    records=[]
    for line in open(args.export):
        r=json.loads(line)
        if r.get('id')==counter_id and r['record'] in ['raw_baseline','raw_chunk']: records+=r['rows']
    records=sorted(records,key=lambda r:r['TimeStamp'])
    ct=np.array([r['TimeStamp'] for r in records]);cv=np.array([r['Value'] for r in records],dtype=float)
    if np.any(np.diff(cv)<0): raise ValueError('Counter reset detected: split the period before energy allocation')
    energy=np.interp(np.r_[t,t[-1]+step],ct,cv)
    delta=np.diff(energy)*.001 # Assumed 1000 pulses/kWh; verify installation configuration.
    for cap in [35,50,100]:
        m=hp&(abs(v[19207]-cap)<=1)&fresh
        if counter_id==10054: m &= fan
        print(label,'capacity',cap,'hours',m.sum()*step/3600,'kWh',delta[m].sum(),
              'meanKW',delta[m].sum()/(m.sum()*step/3600))
