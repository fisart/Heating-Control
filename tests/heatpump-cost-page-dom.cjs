const assert=require('node:assert/strict'),path=require('node:path');
const {execFileSync}=require('node:child_process'),{JSDOM,VirtualConsole}=require('jsdom');
(async()=>{
 const fixture=JSON.parse(execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'webhook-ui-fixture.php')],{encoding:'utf8'}));
 const errors=[],calls=[];const vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(e.message));
 const dom=new JSDOM(fixture.page.body,{url:'https://heating.example:46900/hook/heating_1',runScripts:'outside-only',virtualConsole:vc});
 const w=dom.window,d=w.document;w.setInterval=()=>0;
 const totals={matchedSeconds:86400,comparisonCoveragePercent:100,comparisonHeatPumpCostEuro:4.8,equivalentGasCostEuro:8,savingEuro:3.2,savingPercent:40,matchedElectricityKWh:24,matchedNetHeatKWh:80,achievedCOP:3.333333,heatPumpCostEuro:4.8,knownHeatPumpCostEuro:4.8,costSeconds:86400,completeComparison:true,provisional:false};
 let report={schema:1,fromDate:'2026-10-23',throughDate:'2026-10-23',from:1792706400,to:1792792800,timezone:'Europe/Berlin',summary:totals,days:[{date:'2026-10-23',totals,issues:{}}],assumptions:{airflowM3h:2000,fanCommand:90,gasEfficiencyPercent:100},warnings:[],issues:{}};
 let expired=false,pending=null;
 w.fetch=async(url,options={})=>{
  calls.push({url,options});const u=new URL(url,w.location.href);
  if(u.searchParams.get('view')==='heatpump-costs'){
   if(pending)return new Promise(resolve=>pending.resolve=resolve);
   return {ok:!expired,status:expired?401:200,json:async()=>expired?{error:'Passkey session expired.'}:structuredClone(report)};
  }
  return {ok:true,status:200,json:async()=>fixture.state};
 };
 w.eval(d.querySelector('script').textContent);
 const settle=()=>new Promise(r=>setTimeout(r,25));await settle();
 assert.equal(d.querySelector('#cost-from').disabled,true,'Preset dates cannot be edited accidentally');
 assert.equal(d.querySelector('#cost-csv').hidden,true,'No stale export before a calculation');
 assert.equal(calls.some(c=>c.url.includes('view=heatpump-costs')),false,'Normal ten-second page refresh does not run expensive historical analysis');
 d.querySelector('#cost-period').value='yesterday';d.querySelector('#cost-period').dispatchEvent(new w.Event('change'));d.querySelector('#cost-calculate').click();await settle();
 const query=new URL(calls.find(c=>c.url.includes('view=heatpump-costs')).url,w.location.href);
 assert.match(query.searchParams.get('from'),/^\d{4}-\d{2}-\d{2}$/);assert.equal(query.searchParams.get('from'),query.searchParams.get('through'));
 assert.ok(d.querySelector('#cost-hp').textContent.includes('4.80'));
 assert.ok(d.querySelector('#cost-gas').textContent.includes('8.00'));
 assert.ok(d.querySelector('#cost-saving').textContent.includes('3.20'));
 assert.ok(d.querySelector('#cost-saving-percent').textContent.includes('40.0% cheaper'));
 assert.ok(d.querySelector('#cost-details').textContent.includes('24.00 kWh'));
 assert.ok(d.querySelector('#cost-assumptions').textContent.includes('Shared fan electricity is excluded'));
 assert.equal(d.querySelectorAll('#cost-chart rect').length,2);
 d.querySelector('#cost-chart rect').dispatchEvent(new w.Event('mouseenter'));
 assert.ok(d.querySelector('#cost-tooltip').textContent.includes('Heat pump 4.80'));
 d.querySelector('#cost-toggle-gas').click();assert.equal(d.querySelectorAll('#cost-chart rect').length,1,'Each dataset can be viewed separately');
 assert.equal(d.querySelector('#cost-toggle-gas').getAttribute('aria-pressed'),'false');
 d.querySelector('#cost-toggle-gas').click();assert.equal(d.querySelectorAll('#cost-chart rect').length,2);
 assert.ok(d.querySelector('#cost-csv').href.includes('view=heatpump-costs-csv&from=2026-10-23&through=2026-10-23'));
 assert.equal(d.querySelectorAll('#cost-days tr').length,1);
 d.querySelector('#cost-period').value='custom';d.querySelector('#cost-period').dispatchEvent(new w.Event('change'));
 assert.equal(d.querySelector('#cost-from').disabled,false);assert.equal(d.querySelector('#cost-csv').hidden,true,'Changing dates clears the earlier report');
 d.querySelector('#cost-from').value='2026-10-20';d.querySelector('#cost-through').value='2026-10-23';
 report=structuredClone(report);Object.assign(report.summary,{comparisonCoveragePercent:50,completeComparison:false,savingEuro:-1.2,savingPercent:-15});report.warnings=['Unverified tariff <img src=x onerror=alert(1)>'];report.issues={'Meter boundary unavailable':60};
 d.querySelector('#cost-calculate').click();await settle();
 assert.equal(d.querySelector('#cost-saving-label').textContent,'Additional heat-pump cost');
 assert.ok(d.querySelector('#cost-saving').textContent.includes('1.20'));
 assert.ok(d.querySelector('#cost-status').textContent.includes('Partial comparison'));
 assert.equal(d.querySelectorAll('#cost-issues img').length,0,'Warnings are escaped as text');
 assert.ok(d.querySelector('#cost-issues').textContent.includes('onerror=alert'));
 // Zero values remain visible and the chart stays within its coordinate box.
 Object.assign(report.summary,{savingEuro:0,savingPercent:null,comparisonHeatPumpCostEuro:0,equivalentGasCostEuro:0});report.days[0].totals=report.summary;
 d.querySelector('#cost-calculate').click();await settle();
 assert.ok(d.querySelector('#cost-hp').textContent.includes('0.00'));
 for(const rect of d.querySelectorAll('#cost-chart rect'))assert.ok(Number(rect.getAttribute('y'))>=0);
 // A newer date choice invalidates a late historical response.
 pending={};d.querySelector('#cost-calculate').click();await settle();
 d.querySelector('#cost-from').value='2026-10-21';d.querySelector('#cost-from').dispatchEvent(new w.Event('change'));
 pending.resolve({ok:true,status:200,json:async()=>report});pending=null;await settle();
 assert.equal(d.querySelector('#cost-hp').textContent,'—');assert.equal(d.querySelector('#cost-csv').hidden,true);
 expired=true;d.querySelector('#cost-calculate').click();await settle();
 assert.ok(d.querySelector('#cost-status').textContent.includes('expired'));
 assert.equal(d.querySelector('#cost-hp').textContent,'—');assert.equal(d.querySelector('#cost-csv').hidden,true);
 assert.ok(d.querySelector('#message a').textContent.includes('Sign in again'));
 assert.equal(calls.some(c=>c.options.method==='POST'),false,'Period analysis never sends a heating control command');
 assert.deepEqual(errors,[]);w.close();
 console.log('Cost page DOM: period controls, matched figures, missing/zero/extra cost, chart tooltips and toggles, CSV, escaping, request races and passkey expiry passed.');
})().catch(e=>{console.error(e);process.exit(1)});
