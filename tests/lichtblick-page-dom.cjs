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
const chart={from:now-86400,to:now+86400,resolution:'15-minute intervals',storedIntervals:2,expectedIntervals:192,retailIntervals:2,confirmedIntervals:1,missingIntervals:190,points:[{start:now-900,end:now,price:-2.5,marketCt:-5,source:'lichtblick-quote',verified:true},{start:now+900,end:now+1800,price:29.75,marketCt:10,source:'smard-reconstructed',verified:false}]};
let code=200,fail=false,payload=state,calls=[];
let html=fs.readFileSync(__dirname+'/../LichtBlickElectricityPrice/page.html','utf8').replaceAll('{{NONCE}}','fixture').replaceAll('{{PATH}}','/hook/lichtblick_123').replaceAll('{{INSTANCE}}','123').replaceAll('{{PORTAL}}','/hook/alarm_portal_42').replaceAll('{{LOGIN}}','/hook/secrets_7?portal=1');
const dom=new JSDOM(html,{url:'https://prices.example/hook/lichtblick_123',runScripts:'dangerously',beforeParse(w){
 w.AbortController=AbortController;
 w.fetch=async(url,options)=>{calls.push({url,options});if(fail)throw new Error('Offline');return {status:code,ok:code===200,json:async()=>url.includes('view=price-history')?chart:payload};};
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
 assert.equal(d.querySelectorAll('#price-chart rect').length,2,'Historical prices, including negatives, drawn');
 assert.equal(d.querySelectorAll('#history-table tr').length,2,'Accessible provenance table');
 assert.equal(d.querySelector('#history-from').min,'2026-10-01','Contract boundary in date input');
 assert.ok(d.querySelector('#history-summary').textContent.includes('190 missing'));
 assert.ok([...d.querySelectorAll('#price-chart title')].some(e=>e.textContent.includes('-2.500')),'Negative price tooltip');
 d.querySelector('#history-price').value='market';d.querySelector('#history-price').dispatchEvent(new dom.window.Event('change'));
 assert.ok(d.querySelector('#price-chart title').textContent.includes('wholesale'));
 d.querySelector('#history-range').value='contract';d.querySelector('#history-range').dispatchEvent(new dom.window.Event('change'));await settle();
 assert.equal(d.querySelector('#history-from').value,'2026-10-01','Since-contract preset');
 assert.ok(calls.some(c=>c.url.includes('view=price-history&from=2026-10-01')));
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
 assert.equal(d.querySelector('#dashboard').hidden,true,'Session expiry hides previous data');assert.equal(d.querySelectorAll('#price-chart rect').length,0,'Expired session clears old chart');assert.equal(d.querySelector('#login').hidden,false);
 assert.ok(d.querySelector('#status').textContent.includes('expired'));
 code=200;payload=state;d.querySelector('#refresh').click();await settle();assert.equal(d.querySelector('#login').hidden,true);
 fail=true;d.querySelector('#refresh').click();await settle();assert.equal(d.querySelector('#dashboard').hidden,true,'Outage hides previous data');
 assert.equal(d.querySelector('#refresh').disabled,false,'Refresh recovers after failure');
 fail=false;payload=state;chart.wholesaleOnlyIntervals=1;chart.storedIntervals=3;chart.points.push({start:now-86400,end:now-85500,price:null,marketCt:10,source:'smard-wholesale',verified:false});
 d.querySelector('#history-price').value='retail';d.querySelector('#refresh').click();await settle();
 assert.equal(d.querySelector('#history-notice').hidden,false,'Partial retail history visibly explained even with prices today');
 assert.ok(d.querySelector('#history-notice-text').textContent.includes('standalone script'));
 assert.ok(!d.querySelector('#history-notice-text').textContent.includes('HistoricalTariffs'));
 assert.equal(d.querySelector('#history-show-market').hidden,false);d.querySelector('#history-show-market').click();
 assert.equal(d.querySelector('#history-price').value,'market');assert.equal(d.querySelectorAll('#price-chart rect').length,3,'Available earlier wholesale data visible');
 assert.equal(d.querySelector('#history-notice').hidden,true,'Retail warning hidden for wholesale selection');
 chart.estimatedIntervals=1;chart.points[1].source='app-normalized-estimate';
 d.querySelector('#history-price').value='retail';d.querySelector('#refresh').click();await settle();
 assert.equal(d.querySelector('#estimate-notice').hidden,false,'Imported estimate qualification visible');
 assert.ok(d.querySelector('#history-summary').textContent.includes('1 imported estimates'));
 assert.ok(d.querySelector('#history-table').textContent.includes('imported estimate, not tariff-confirmed'));
 assert.ok(calls.every(c=>c.options.method==='GET'),'No commands or external requests');
 dom.window.close();console.log('LichtBlick page display, injection, refresh and session-expiry checks passed.');
})().catch(e=>{dom.window.close();console.error(e);process.exitCode=1;});
