// Run with jsdom installed: PHP_BIN=/path/to/php node tests/page-dom.cjs
// Tests rendered DOM and interactions; this does not replace browser layout/CSP tests.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process'),{JSDOM,VirtualConsole}=require('jsdom');
(async()=>{
 const fixture=JSON.parse(execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'webhook-ui-fixture.php')],{encoding:'utf8'}));
 const errors=[],calls=[];let state=fixture.state,empty=false,expired=false,resolveHistory=null;
 const vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(e.message));
 const dom=new JSDOM(fixture.page.body,{url:'https://heating.example:46900/hook/heating_1',runScripts:'outside-only',virtualConsole:vc});
 const w=dom.window,d=w.document;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
 w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 w.setInterval=()=>0;
 const stamp=Math.floor(Date.now()/3600000)*3600;
 w.fetch=async(url,options={})=>{
  calls.push({url,options});const u=new URL(url,w.location.href);
  if(u.searchParams.get('view')==='history'){
   if(resolveHistory)return new Promise(resolve=>{resolveHistory.resolve=resolve;});
   return {ok:!expired,status:expired?401:200,json:async()=>expired?{error:'Passkey session expired.'}:{name:'Outside temperature · Outdoor sensor',range:u.searchParams.get('range'),aggregation:u.searchParams.get('resolution')==='auto'?(u.searchParams.get('range')==='1h'?'recorded':'hourly'):u.searchParams.get('resolution'),from:stamp-86400,to:stamp+1,truncated:false,points:empty?[]:[{time:stamp-7200,value:19,min:18.8,max:19.2,duration:3600},{time:stamp-3600,value:20,min:19.8,max:20.2,duration:3600},{time:stamp,value:21,min:20.8,max:21.2,duration:3600}]}};
  }
  return {ok:true,status:200,json:async()=>state};
 };
 w.eval(d.querySelector('script').textContent);
 const settle=()=>new Promise(r=>setTimeout(r,20));await settle();
 assert.equal(d.querySelectorAll('#heat-pump-devices .device-row').length,4);
 assert.equal(d.querySelectorAll('#gas-devices .device-row').length,5);
 assert.equal(d.querySelectorAll('#airflow-devices .device-row').length,7);
 assert.ok(d.querySelector('#airflow-devices').textContent.includes('Current fan speed'));
 assert.ok(d.querySelector('#heat-pump-devices').textContent.includes('Heating / cooling'));
 assert.ok(d.querySelector('#gas-devices').textContent.includes('Gas mixerClosed (100)'));
 assert.equal(d.querySelectorAll('[data-sensor-id]').length,2);
 assert.equal(d.querySelectorAll('#rooms article')[1].querySelectorAll('.sensor-value').length,0,'Unrecorded room has plain temperature');
 d.querySelector('#environment [data-sensor-id="25911"]').click();await settle();
 assert.equal(d.querySelector('#sensor-chart').open,true);
 assert.equal(d.querySelector('#chart-title').textContent,'Outside temperature · Outdoor sensor');
 assert.ok(d.querySelector('.chart-sensor-name').textContent.includes('Outside temperature · Outdoor sensor'));
 assert.equal(d.querySelectorAll('#chart-plot circle').length,3);
 assert.equal(d.querySelectorAll('#chart-rows tr').length,3);
 assert.ok(calls.at(-1).url.includes('id=25911&range=24h'));
 assert.ok(d.querySelector('#chart-summary').textContent.includes('18.80'));
 d.querySelector('#chart-period').value='7d';d.querySelector('#chart-period').dispatchEvent(new w.Event('change'));await settle();
 assert.ok(calls.at(-1).url.includes('range=7d'));
 d.querySelector('#chart-period').value='1h';d.querySelector('#chart-period').dispatchEvent(new w.Event('change'));await settle();
 assert.ok(calls.at(-1).url.includes('range=1h&resolution=auto'));
 assert.ok(d.querySelector('#chart-status').textContent.includes('Recorded readings'));
 assert.equal(d.querySelector('#chart-details th:nth-child(2)').textContent,'Temperature °C');
 for(const resolution of ['hourly','daily','recorded']){
  d.querySelector('#chart-resolution').value=resolution;d.querySelector('#chart-resolution').dispatchEvent(new w.Event('change'));await settle();
  assert.ok(calls.at(-1).url.includes('range=1h&resolution='+resolution));
  assert.ok(d.querySelector('.chart-sensor-name').textContent.includes(resolution==='recorded'?'Recorded readings':resolution==='hourly'?'Hourly averages':'Daily averages'));
 }
 empty=true;d.querySelector('#chart-period').value='6h';d.querySelector('#chart-period').dispatchEvent(new w.Event('change'));await settle();
 assert.ok(d.querySelector('#chart-plot').textContent.includes('No recorded values'));
 assert.equal(d.querySelectorAll('#chart-plot circle').length,0);
 assert.equal(d.querySelector('#chart-details').hidden,true);
 d.querySelector('#chart-close').click();assert.equal(d.querySelector('#sensor-chart').open,false);
 // A multi-sensor average is never linked to an arbitrary constituent sensor.
 state=structuredClone(state);state.rooms[0].sensors.push({id:60001,name:'Second sensor',chart:false,value:25,stale:false});
 state.rooms[0].actual=24.2;state.rooms[0].name='<img src=x onerror=alert(1)>';
 d.querySelector('#refresh').click();await settle();
 const room=d.querySelector('#rooms article');assert.equal(room.querySelector('.temperature button'),null);
 assert.equal(room.querySelectorAll('.sensor-values button').length,1);
 assert.equal(room.querySelectorAll('img').length,0);
 // Delayed replies must not reopen or repopulate a closed chart.
 resolveHistory={};d.querySelector('#environment .sensor-value').click();await settle();d.querySelector('#chart-close').click();
 resolveHistory.resolve({ok:true,status:200,json:async()=>({name:'Late',aggregation:'hourly',from:stamp-10,to:stamp,points:[{time:stamp,value:99,min:99,max:99,duration:1}]})});resolveHistory=null;await settle();
 assert.equal(d.querySelector('#sensor-chart').open,false);assert.equal(d.querySelectorAll('#chart-plot circle').length,0);
 expired=true;d.querySelector('#environment .sensor-value').click();await settle();
 assert.ok(d.querySelector('#chart-status').textContent.includes('expired'));
 assert.ok(d.querySelector('#message a').textContent.includes('Sign in again'));
 assert.equal(d.querySelectorAll('#rooms button:enabled:not(.sensor-value),#controls button:enabled').length,0);
 assert.deepEqual(errors,[]);
 dom.window.close();console.log('Page DOM: device groups, archived-only links, sensor names, chart periods and resolutions, empty results, multi-sensor values, escaping and expired sessions passed.');
})().catch(e=>{console.error(e);process.exit(1);});
