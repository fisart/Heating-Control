/* Read-only 3D charts; no external libraries or equipment commands. */
(()=>{
 'use strict';
 const $=s=>document.querySelector(s),canvas=$('#surface-canvas');
 let ctx=null;
 const tabs=[$('#tab-overview'),$('#tab-surface')],panels=[$('#heating-overview'),$('#heating-surface')];
 let data=null,loading=false,yaw=-.72,pitch=.63,zoom=1,drag=null,screenPoints=[];
 const number=(v,n=2)=>Number(v).toLocaleString(undefined,{maximumFractionDigits:n});
 function tab(index,focus=false){
  tabs.forEach((t,i)=>{t.setAttribute('aria-selected',String(i===index));t.tabIndex=i===index?0:-1;t.classList.toggle('secondary',i!==index);panels[i].hidden=i!==index;});
  if(focus)tabs[index].focus();if(index===1){load();requestAnimationFrame(draw);}
 }
 tabs.forEach((t,i)=>{t.addEventListener('click',()=>tab(i));t.addEventListener('keydown',e=>{
  if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();tab(e.key==='Home'?0:e.key==='End'?1:1-i,true);
 });});
 $('#calibration-link').addEventListener('click',()=>tab(0));
 async function load(force=false){
  if(loading||data&&!force)return;loading=true;$('#surface-retry').hidden=true;$('#surface-status').textContent='Loading reviewed calibration and model…';
  const abort=new AbortController(),timeout=setTimeout(()=>abort.abort(),60000);
  try{
   const response=await fetch(document.querySelector('main').dataset.path+'?view=heatpump-surface',{credentials:'same-origin',cache:'no-store',signal:abort.signal});
   const result=await response.json();if(!response.ok)throw Error(result.error||'Chart data unavailable.');
   if(!Array.isArray(result.grid)||!Array.isArray(result.points)||!result.metrics)throw Error('Incomplete chart data.');
   data=result;$('#surface-status').textContent=result.metrics.accepted_points+' fitted observations · '+result.metrics.recorded_rows+' stored calibration rows · '+result.metrics.recorded_runs+' recorded runs · '+result.version;
   $('#surface-assumptions').textContent=result.assumptions;
   const body=$('#surface-measurements');body.replaceChildren();
   for(const p of result.points){const tr=document.createElement('tr');for(const value of [p.runID,p.powerPercent,number(p.outside),number(p.incoming),number(p.airRise),number(p.estimatedHeatKW),number(p.systemInputKW),number(p.estimatedSystemCOP),p.originalStable?'Stable':'Warning retained',p.excludedSeconds]){const td=document.createElement('td');td.textContent=value;tr.append(td);}body.append(tr);}
   draw();
  }catch(error){$('#surface-status').textContent='Could not load charts: '+(error.name==='AbortError'?'Request timed out.':error.message);$('#surface-retry').hidden=false;}
  finally{loading=false;clearTimeout(timeout);}
 }
 function draw(){
  if(!data||panels[1].hidden)return;ctx=ctx||canvas.getContext('2d');if(!ctx)return;
  const box=canvas.getBoundingClientRect(),width=Math.max(280,box.width),height=box.height||560,dpr=window.devicePixelRatio||1;
  canvas.width=Math.round(width*dpr);canvas.height=Math.round(height*dpr);ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,width,height);
  const key=$('#surface-metric').value,all=data.grid.flat().map(p=>p[key]).concat(data.points.map(p=>p[key])),min=Math.min(...all),max=Math.max(...all),range=max-min||1;
  const tmin=data.temperatures[0],tmax=data.temperatures.at(-1);
  function raw(t,p,value){const x=(t-tmin)/(tmax-tmin)*2-1,y=(p-30)/70*2-1,z=(value-min)/range*1.65;
   const xx=x*Math.cos(yaw)-y*Math.sin(yaw),yy=x*Math.sin(yaw)+y*Math.cos(yaw);
   return {x:xx,y:yy*Math.sin(pitch)-z*Math.cos(pitch),depth:yy*Math.cos(pitch)+z*Math.sin(pitch)};
  }
  const corners=[tmin,tmax].flatMap(t=>[30,100].flatMap(p=>[min,max].map(v=>raw(t,p,v))));
  const xmin=Math.min(...corners.map(p=>p.x)),xmax=Math.max(...corners.map(p=>p.x)),ymin=Math.min(...corners.map(p=>p.y)),ymax=Math.max(...corners.map(p=>p.y));
  const scale=Math.min((width-90)/(xmax-xmin),(height-100)/(ymax-ymin))*zoom,ox=width/2-(xmin+xmax)*scale/2,oy=height/2-(ymin+ymax)*scale/2;
  function project(t,p,v){const q=raw(t,p,v);return {x:ox+q.x*scale,y:oy+q.y*scale,depth:q.depth};}
  const line=(a,b,color='#b8c9c3')=>{ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.strokeStyle=color;ctx.lineWidth=1;ctx.stroke();};
  const label=(text,p,align='center')=>{ctx.fillStyle='#334b43';ctx.font='12px system-ui';ctx.textAlign=align;ctx.fillText(text,p.x,p.y);};
  for(let p=30;p<=100;p+=10)line(project(tmin,p,min),project(tmax,p,min),'#dfe7e1');
  for(let t=Math.ceil(tmin);t<=tmax;t+=2)line(project(t,30,min),project(t,100,min),'#dfe7e1');
  const colors=[[36,93,131],[59,156,153],[214,188,84],[225,154,63]];
  function color(v,alpha){const f=Math.max(0,Math.min(.9999,(v-min)/range))*3,i=Math.floor(f),g=f-i,a=colors[i],b=colors[Math.min(3,i+1)];return 'rgba('+a.map((n,j)=>Math.round(n+(b[j]-n)*g)).join(',')+','+alpha+')';}
  const mesh=data.grid.map((row,i)=>row.map((p,j)=>project(data.temperatures[i],data.powers[j],p[key]))),faces=[];
  for(let i=0;i<mesh.length-1;i++)for(let j=0;j<mesh[i].length-1;j++){
   const vertices=[mesh[i][j],mesh[i+1][j],mesh[i+1][j+1],mesh[i][j+1]],value=(data.grid[i][j][key]+data.grid[i+1][j][key]+data.grid[i+1][j+1][key]+data.grid[i][j+1][key])/4;
   faces.push({vertices,value,depth:vertices.reduce((s,v)=>s+v.depth,0)/4,inside:[data.grid[i][j],data.grid[i+1][j],data.grid[i+1][j+1],data.grid[i][j+1]].every(p=>p.surfaceSupport==='interpolation')});
  }
  faces.sort((a,b)=>a.depth-b.depth);for(const face of faces){ctx.beginPath();face.vertices.forEach((v,i)=>i?ctx.lineTo(v.x,v.y):ctx.moveTo(v.x,v.y));ctx.closePath();ctx.fillStyle=color(face.value,face.inside ? .8 : .22);ctx.fill();ctx.strokeStyle='rgba(255,255,255,.3)';ctx.lineWidth=.5;ctx.stroke();}
  screenPoints=[];
  if($('#surface-points').checked){
   const points=data.points.map(p=>({p,pos:project(p.outside,p.powerPercent,p[key])})).sort((a,b)=>a.pos.depth-b.pos.depth);
   for(const {p,pos} of points){const latest=p.runID===data.latestRunID;ctx.beginPath();if(p.originalStable===false){ctx.moveTo(pos.x,pos.y-6);ctx.lineTo(pos.x+6,pos.y);ctx.lineTo(pos.x,pos.y+6);ctx.lineTo(pos.x-6,pos.y);ctx.closePath();}else ctx.arc(pos.x,pos.y,latest?4.5:3.5,0,Math.PI*2);ctx.fillStyle=latest?'#ae5c22':'#173e5e';ctx.fill();ctx.strokeStyle='#fff';ctx.lineWidth=1;ctx.stroke();screenPoints.push({p,...pos});}
  }
  line(project(tmin,30,min),project(tmax,30,min),'#758c82');line(project(tmin,30,min),project(tmin,100,min),'#758c82');line(project(tmin,30,min),project(tmin,30,max),'#758c82');
  for(const t of [tmin,6,10,14,tmax]){const p=project(t,30,min);label(number(t,1),{x:p.x,y:p.y+18});}
  for(const power of [30,50,70,100]){const p=project(tmin,power,min);label(power+'%',{x:p.x-10,y:p.y},'right');}
  for(let n=1;n<=4;n++){const v=min+range*n/4,p=project(tmin,30,v);label(number(v,1),{x:p.x-12,y:p.y},'right');}
  ctx.fillStyle='#23352f';ctx.font='600 14px system-ui';ctx.textAlign='left';ctx.fillText($('#surface-metric').selectedOptions[0].textContent,18,25);
  ctx.font='12px system-ui';ctx.fillText('Outside temperature (°C)',18,height-27);ctx.fillText('Heat-pump power (%)',18,height-10);
 }
 function inspect(event){if(!data||drag)return;const box=canvas.getBoundingClientRect(),x=event.clientX-box.left,y=event.clientY-box.top;
  let nearest=null,dist=18;for(const p of screenPoints){const d=Math.hypot(x-p.x,y-p.y);if(d<dist){nearest=p.p;dist=d;}}
  if(nearest){const p=nearest;$('#surface-hover').textContent=p.runID+' · '+p.powerPercent+'% · Outside '+number(p.outside)+'°C · Intake '+number(p.incoming)+'°C · COP '+number(p.estimatedSystemCOP)+' · Input '+number(p.systemInputKW)+' kW'+(p.originalStable===false?' · Original stability warning retained':'')+(p.excludedSeconds?' · '+p.excludedSeconds+' s interruption excluded':'');}
 }
 canvas.addEventListener('pointerdown',event=>{drag={x:event.clientX,y:event.clientY,moved:false};canvas.setPointerCapture?.(event.pointerId);});
 canvas.addEventListener('pointermove',event=>{if(drag){const dx=event.clientX-drag.x,dy=event.clientY-drag.y;drag.moved=drag.moved||Math.abs(dx)+Math.abs(dy)>2;yaw+=dx*.008;pitch=Math.max(.2,Math.min(1.3,pitch+dy*.006));drag.x=event.clientX;drag.y=event.clientY;draw();}else inspect(event);});
 canvas.addEventListener('pointerup',event=>{const moved=drag?.moved;drag=null;if(!moved)inspect(event);});canvas.addEventListener('pointercancel',()=>{drag=null;});
 canvas.addEventListener('wheel',event=>{event.preventDefault();zoom=Math.max(.6,Math.min(1.7,zoom-event.deltaY*.001));draw();},{passive:false});
 canvas.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','+','=','-'].includes(event.key))return;event.preventDefault();if(event.key==='ArrowLeft')yaw-=.12;if(event.key==='ArrowRight')yaw+=.12;if(event.key==='ArrowUp')pitch=Math.min(1.3,pitch+.08);if(event.key==='ArrowDown')pitch=Math.max(.2,pitch-.08);if(['+','='].includes(event.key))zoom=Math.min(1.7,zoom+.1);if(event.key==='-')zoom=Math.max(.6,zoom-.1);draw();});
 $('#surface-metric').addEventListener('change',draw);$('#surface-points').addEventListener('change',draw);
 $('#surface-reset').addEventListener('click',()=>{yaw=-.72;pitch=.63;zoom=1;draw();});$('#surface-retry').addEventListener('click',()=>load(true));
 $('#refresh').addEventListener('click',()=>{if(!panels[1].hidden)load(true);});window.addEventListener('resize',()=>requestAnimationFrame(draw));
 if(location.hash==='#3d-charts')tab(1);
})();
