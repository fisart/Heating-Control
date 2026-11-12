#!/usr/bin/env python3
"""Render the saved validation evidence without refitting or live access."""
import json
from pathlib import Path
import numpy as np
from scipy.spatial.distance import cdist
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

root=Path(__file__).resolve().parents[2]
data=root/'docs/data';out=root/'docs/assets';out.mkdir(exist_ok=True)
model=json.loads((data/'heatpump-cop-surface.json').read_text())
old=json.loads((data/'heatpump-cop-surface-v5.json').read_text())
validation=json.loads((data/'heatpump-surface-validation.json').read_text())

def value(t,p,intake):
    x=np.column_stack(((t-10.5)/5.5,np.full(len(t),(p-65)/35),np.full(len(t),(intake-17.5)/8)))
    basis=np.column_stack([np.prod(x**f,axis=1) for f in model['feature_exponents']])
    return np.exp(basis@np.array(model['coefficients']))[:,0]

def prior(t,p):
    q=np.column_stack(((t-10.5)/5.5,np.full(len(t),(p-65)/35)))
    d=cdist(q,np.array(old['centers_normalized']))
    basis=np.column_stack((np.where(d>0,d*d*np.log(np.maximum(d,1e-300)),0),np.ones(len(t)),q))
    v=basis@np.array(old['coefficients'])
    return .67*v[:,0]/v[:,1]

plt.rcParams.update({'font.family':'DejaVu Sans','axes.spines.top':False,'axes.spines.right':False})
fig,axes=plt.subplots(1,3,figsize=(13,4.7),sharey=True)
t=np.linspace(3.25,6,220)
for ax,p in zip(axes,[30,50,100]):
    ax.plot(t,prior(t,p),'--',color='#9b6252',label='Previous v5: intake omitted')
    for intake,color in [(10,'#286c93'),(15,'#209583'),(23,'#b88b29')]:
        ax.plot(t,value(t,p,intake),color=color,label=f'New v6: intake {intake}°C')
    ax.axvspan(3.25,4,color='#e7edf0',alpha=.4)
    ax.set(title=f'{p}% command',xlabel='Outside temperature (°C)');ax.grid(alpha=.15)
axes[0].set_ylabel('Estimated COP');axes[-1].legend(fontsize=8,loc='upper right')
fig.suptitle('Cold-weather shape at fixed intake temperature',fontsize=16)
fig.text(.08,.015,'Smooth shape is a model constraint, not proof that defrost cannot cause real discontinuities. Retained heating intervals; assumed airflow 2,000 m³/h.',fontsize=9,color='#4e6059')
fig.tight_layout(rect=[0,.05,1,.94]);fig.savefig(out/'heatpump-cold-weather-review-2026-11-12.png',dpi=170);plt.close(fig)

fig,ax=plt.subplots(figsize=(8,6))
rows=validation['predictions'];observed=[r['observedCOP'] for r in rows];pred=[r['heldOutCOP'] for r in rows]
runs=list(dict.fromkeys(r['runID'] for r in rows))
for run in runs:
    rr=[r for r in rows if r['runID']==run]
    ax.scatter([r['observedCOP'] for r in rr],[r['heldOutCOP'] for r in rr],s=38,label=run)
ax.plot([1.5,4.6],[1.5,4.6],color='#596b63',linewidth=1)
ax.set(xlabel='Recorded estimated COP',ylabel='Prediction with the complete run withheld',title='Grouped validation · 47 observations / 6 runs',xlim=(1.5,4.6),ylim=(1.5,4.6));ax.grid(alpha=.15);ax.legend(fontsize=8)
fig.text(.10,.015,f"COP RMSE: previous {validation['previous_v5']['rmse_COP']:.3f} → new {validation['nested']['rmse_COP']:.3f}. Alpha tuned only on other runs. Internal validation; absolute COP remains provisional.",fontsize=9)
fig.tight_layout(rect=[0,.05,1,1]);fig.savefig(out/'heatpump-run-validation-2026-11-12.png',dpi=170);plt.close(fig)
