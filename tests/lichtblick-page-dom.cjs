const {JSDOM}=require('jsdom');
const fs=require('node:fs'),assert=require('node:assert/strict');
const now=Math.floor(Date.now()/1000);
const state={updatedAt:now,enabled:true,recommendation:{source:1,valid:true,powerPercent:35,validUntil:now+600,reason:'Heat pump preferred'},
 values:{PriceValid:true,PriceAvailable:true,CurrentPriceCt:19.25,PriceStart:now-300,PriceEnd:now+600,PriceStatus:'OK',ReferenceThresholdCt:30,ThresholdCt:37.09,
 EffectiveThresholdCt:27.82,EntryThresholdCt:25.82,WeatherModelValid:true,WeatherHeatPumpPowerPercent:35,WeatherOutsideTemperatureC:7,
 WeatherCOP:5.78,WeatherSystemCOP:3.6125,WeatherHeatPumpKW:1,WeatherModelStatus:'Provisional curve',DailyCandidateCt:0,ThresholdCalculatedAt:0,LastSuccessfulFetch:now-100,DailyStatus:'Learning disabled',FetchStatus:'88 prices'},
 settings:{weatherEnabled:true,safetyMarginPercent:25,hysteresisCt:2,referenceTemperatureC:2,gasPriceCt:10.48,referenceGasPriceCt:10.48,fanKW:0.6,postalCode:'14167'},
 outside:{temperatureC:7,updatedAt:now-60},curve:[{outsideC:7,powerPercent:35,cop:5.78,heatPumpKW:1}],
 slots:[{start:now-300,end:now+600,price:19.25},{start:now+600,end:now+1500,price:25.84}],debug:'No errors'};
let code=200,fail=false,payload=state,calls=[];
let html=fs.readFileSync(__dirname+'/../LichtBlickElectricityPrice/page.html','utf8').replaceAll('{{NONCE}}','fixture').replaceAll('{{PATH}}','/hook/lichtblick_123').replaceAll('{{INSTANCE}}','123').replaceAll('{{PORTAL}}','/hook/alarm_portal_42').replaceAll('{{LOGIN}}','/hook/secrets_7?portal=1');
const dom=new JSDOM(html,{url:'https://prices.example/hook/lichtblick_123',runScripts:'dangerously',beforeParse(w){
 w.AbortController=AbortController;
 w.fetch=async(url,options)=>{calls.push({url,options});if(fail)throw new Error('Offline');return {status:code,ok:code===200,json:async()=>payload};};
}});
const settle=()=>new Promise(r=>setImmediate(r));
(async()=>{
 await settle();const d=dom.window.document;
 assert.equal(d.querySelector('#dashboard').hidden,false);
 assert.equal(d.querySelector('#portal').getAttribute('href'),'/hook/alarm_portal_42');
 assert.equal(d.querySelector('#portal').hidden,false);
 assert.equal(d.querySelector('#price').textContent,'19.25 ct/kWh');
 assert.equal(d.querySelector('#source').textContent,'Heat pump');
 assert.ok(d.querySelector('#power').textContent.includes('35%'));
 assert.equal(d.querySelector('#ceiling').textContent,'27.82 ct/kWh');
 assert.ok(d.querySelector('#entry').textContent.includes('25.82'));
 assert.equal(d.querySelectorAll('#curve tr').length,1);
 assert.equal(d.querySelectorAll('#schedule tr').length,2);
 assert.equal(d.querySelectorAll('#schedule tr.current').length,1);
 assert.equal(calls[0].url,'/hook/lichtblick_123?view=state');
 assert.equal(calls[0].options.credentials,'same-origin');
 assert.equal(calls[0].options.cache,'no-store');
 payload=structuredClone(state);payload.recommendation={source:2,valid:true,powerPercent:0,validUntil:now+600,reason:'Gas preferred'};
 payload.values.WeatherModelStatus='<img src=x onerror=alert(1)>';payload.debug='<script>alert(1)</script>';
 d.querySelector('#refresh').click();await settle();
 assert.equal(d.querySelector('#source').textContent,'Gas');assert.ok(d.querySelector('#power').textContent.includes('0%'));
 assert.ok(d.querySelector('#weather').textContent.includes('35%'),'Candidate shown even when gas chosen');
 assert.equal(d.querySelectorAll('img').length,0,'No injected markup');assert.equal(d.querySelectorAll('#debug script').length,0);
 payload.values.WeatherModelValid=false;payload.values.PriceValid=false;payload.values.PriceAvailable=false;payload.recommendation.valid=false;
 d.querySelector('#refresh').click();await settle();
 assert.equal(d.querySelector('#source').textContent,'Gas fallback');assert.equal(d.querySelector('#ceiling').textContent,'Unavailable');assert.equal(d.querySelector('#price').textContent,'Unavailable');
 code=401;d.querySelector('#refresh').click();await settle();
 assert.equal(d.querySelector('#dashboard').hidden,true,'Session expiry hides previous data');assert.equal(d.querySelector('#login').hidden,false);
 assert.ok(d.querySelector('#status').textContent.includes('expired'));
 code=200;payload=state;d.querySelector('#refresh').click();await settle();assert.equal(d.querySelector('#login').hidden,true);
 fail=true;d.querySelector('#refresh').click();await settle();assert.equal(d.querySelector('#dashboard').hidden,true,'Outage hides previous data');
 assert.equal(d.querySelector('#refresh').disabled,false,'Refresh recovers after failure');
 assert.ok(calls.every(c=>c.options.method==='GET'),'No commands or external requests');
 dom.window.close();console.log('LichtBlick page display, injection, refresh and session-expiry checks passed.');
})().catch(e=>{dom.window.close();console.error(e);process.exitCode=1;});
