// DOM interaction coverage when a real browser is unavailable. This does not
// claim to validate rendered pixels or CSP; the Chromium suite covers those.
const assert=require('node:assert/strict'),path=require('node:path');
const {execFileSync}=require('node:child_process'),{JSDOM}=require('jsdom');
(async()=>{
 const php=process.env.PHP_BIN||'php';
 const fixture=JSON.parse(execFileSync(php,[path.join(__dirname,'webhook-ui-fixture.php')],{encoding:'utf8'}));
 const charts=JSON.parse(execFileSync(php,['-r',`require '${path.join(__dirname,'../HeatingControl/HeatPumpSurface.php')}'; echo json_encode(HeatPumpSurface::chartData());`],{encoding:'utf8'}));
 let chartLoads=0;const requests=[],labels=[],errors=[];
 const dom=new JSDOM(fixture.page.body,{url:'https://heating.example:46900/hook/heating_1',runScripts:'dangerously',pretendToBeVisual:true,
  beforeParse(w){
   w.HTMLCanvasElement.prototype.getContext=function(){return Object.fromEntries(['setTransform','clearRect','beginPath','moveTo','lineTo','stroke','closePath','fill','arc'].map(k=>[k,()=>{}]).concat([['fillText',text=>labels.push(text)]]));};
   w.HTMLCanvasElement.prototype.getBoundingClientRect=()=>({width:1000,height:560,left:0,top:0});
   w.fetch=async(url,options={})=>{requests.push({url:String(url),method:options.method||'GET'});const chart=String(url).includes('view=heatpump-surface');if(chart)chartLoads++;return {ok:true,json:async()=>chart?charts:fixture.state};};
   w.addEventListener('error',e=>errors.push(e.message));
  }});
 const w=dom.window,d=w.document;
 async function until(fn){for(let n=0;n<100;n++){if(fn())return;await new Promise(r=>setTimeout(r,5));}throw Error('Page state did not settle');}
 await until(()=>d.querySelector('#calibration-status').dataset.state==='idle');
 assert.equal(chartLoads,0,'Opening the overview does not fetch chart data');
 d.querySelector('#tab-surface').click();await until(()=>d.querySelector('#surface-status').textContent.includes('47 fitted observations'));
 assert.equal(chartLoads,1);assert.equal(d.querySelector('#heating-surface').hidden,false);
 assert.equal(d.querySelector('#heating-overview').hidden,true);assert.equal(d.querySelector('#calibration-status').hidden,false);
 assert.equal(d.querySelectorAll('#surface-measurements tr').length,47);
 const measured=d.querySelector('#surface-measurements').textContent;
 assert.equal([...d.querySelectorAll('#surface-measurements tr')].filter(r=>r.textContent.includes(charts.latestRunID)).length,8);
 const intake=d.querySelector('#surface-intake');intake.value='10';intake.dispatchEvent(new w.Event('change'));
 assert.match(d.querySelector('#surface-status').textContent,/Mesh intake 10°C/);
 assert.equal(chartLoads,1,'Changing intake uses the loaded read-only grid');
 assert.equal(d.querySelector('#surface-measurements').textContent,measured,'A display slice does not rewrite measurements');
 assert(labels.some(t=>t.includes('intake 10°C')),'Chart title identifies the selected physical slice');
 const metric=d.querySelector('#surface-metric');metric.value='systemInputKW';metric.dispatchEvent(new w.Event('change'));
 assert(labels.some(t=>t.includes('Electrical input')),'Metric selection redraws the requested axis');
 assert.match(d.querySelector('#surface-assumptions').textContent,/own recorded intake/);
 assert.match(d.querySelector('#surface-assumptions').textContent,/three-input observation hull/);
 d.querySelector('#tab-overview').click();assert.equal(d.querySelector('#heating-overview').hidden,false);
 d.querySelector('#tab-overview').dispatchEvent(new w.KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));
 assert.equal(d.querySelector('#tab-surface').getAttribute('aria-selected'),'true');assert.equal(chartLoads,1);
 assert(requests.every(r=>r.method==='GET'),'Chart interactions issue no equipment or settings commands');
 assert.deepEqual(errors,[]);w.close();
 console.log('PASS: chart DOM, lazy loading, intake/metric selection, unchanged observations, keyboard tabs and read-only requests');
})().catch(e=>{console.error(e);process.exit(1);});
