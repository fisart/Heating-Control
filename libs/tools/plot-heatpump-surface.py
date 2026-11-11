#!/usr/bin/env python3
"""Render the saved numeric matrix and exact accepted observations; no live access."""
import argparse,json
from pathlib import Path
import numpy as np
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--date',default='2026-11-11');a=ap.parse_args()
    root=Path(__file__).resolve().parents[2];data=root/'docs/data';out=root/'docs/assets';out.mkdir(exist_ok=True)
    grid=json.loads((data/'heatpump-surface-matrix.json').read_text());source=json.loads((data/'heatpump-cop-surface-observations.json').read_text())
    points=[p for p in source['points'] if p['stable'] and p['usable'] and not p.get('supersededBy')]
    t,p=np.meshgrid(grid['outside_temperature_C'],grid['power_percent'],indexing='ij')
    plt.rcParams.update({'font.family':'DejaVu Sans','font.size':10,'axes.titleweight':'bold','figure.facecolor':'#fafbf8'})
    for field,pointfield,label,name in [('estimated_COP','estimatedSystemCOP','Estimated COP','modeled-surface'),('estimated_air_rise_C',None,'Air-temperature rise (°C)','temperature-rise-surface')]:
        fig=plt.figure(figsize=(11,7.5));ax=fig.add_subplot(111,projection='3d');z=np.array(grid[field]);ax.plot_surface(t,p,z,cmap='viridis',alpha=.65,linewidth=.2,edgecolor='#ffffff55')
        for latest,color,marker in [(False,'#183d5e','o'),(True,'#b75c21','D')]:
            rows=[r for r in points if r['runID'].startswith('20261111')==latest]
            ax.scatter([r['outside'] for r in rows],[r['powerPercent'] for r in rows],[r[pointfield] if pointfield else r['outgoing']-r['incoming'] for r in rows],c=color,marker=marker,s=30,depthshade=False,label='11 Nov · target 10°C' if latest else 'Earlier measurements')
        ax.set(xlabel='Outside temperature (°C)',ylabel='Heat-pump command (%)',zlabel=label,title=f'{label} · {len(points)} reviewed observations');ax.view_init(elev=26,azim=-55);ax.legend(loc='upper left');fig.text(.08,.035,'Provisional thin-plate surface · airflow assumed 2,000 m³/h · normal-heating intervals\nMixed electricity-meter scopes; mesh outside the observation hull is extrapolation.',fontsize=9,color='#56645c');fig.subplots_adjust(bottom=.13,top=.91)
        fig.savefig(out/f'heatpump-{name}-{a.date}.png',dpi=160);plt.close(fig)
    fig,ax=plt.subplots(figsize=(11,6.5));runs=list(dict.fromkeys(r['runID'] for r in points))
    for run in runs:
        rows=sorted([r for r in points if r['runID']==run],key=lambda r:r['powerPercent']);ax.plot([r['powerPercent'] for r in rows],[r['estimatedSystemCOP'] for r in rows],marker='o',label=run,linewidth=2.5 if run.startswith('20261111') else 1)
    ax.set(xlabel='Heat-pump command (%)',ylabel='Estimated COP',title='Recorded calibration COP by run · full retained intervals');ax.grid(alpha=.2);ax.legend(fontsize=8,ncol=2);fig.text(.08,.02,'Different weather and intake temperatures; lines connect measurements from the same run, not a universal power curve.',fontsize=9);fig.tight_layout(rect=[0,.05,1,1]);fig.savefig(out/f'heatpump-2d-chart-{a.date}.png',dpi=160);plt.close(fig)

if __name__=='__main__':main()
