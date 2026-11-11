// Run with PHP and Playwright Chromium installed. All replies are local fixtures.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');
(async()=>{
 const fixture=JSON.parse(execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'webhook-ui-fixture.php')],{encoding:'utf8'}));
 const screenshots=path.join(__dirname,'artifacts/calibration-banner');fs.mkdirSync(screenshots,{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_BIN||undefined,headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];let state=structuredClone(fixture.state),unavailable=false;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://heating.example:46900/**',async route=>{
  if(route.request().url().includes('?view=state'))return route.fulfill({status:unavailable?503:200,contentType:'application/json',body:JSON.stringify(unavailable?{error:'Disconnected'}:state)});
  return route.fulfill({status:200,headers:fixture.page.headers,body:fixture.page.body});
 });
 await page.goto('https://heating.example:46900/hook/heating_1');
 await page.locator('#calibration-status[data-state="idle"]').waitFor();
 assert.equal(await page.locator('#ventilation-link').getAttribute('href'),'/hook/ventilation_70');assert(await page.locator('#ventilation-link').isVisible());
 await page.screenshot({path:path.join(screenshots,'desktop-idle.png')});
 state.heatPumpTest={...state.heatPumpTest,active:true,phase:'step',powerPercent:50,cleanMeasurementSeconds:420,stepMinutes:15,status:'Step 3/8: 50%; measuring; clean 420/900 seconds; excluded 0 seconds; master bedroom closed'};
 await page.locator('#refresh').click();await page.locator('#calibration-status[data-state="active"]').waitFor();
 assert.equal(await page.locator('#calibration-progress').getAttribute('value'),'420');
 await page.screenshot({path:path.join(screenshots,'desktop-running.png')});
 await page.setViewportSize({width:390,height:844});
 assert(await page.locator('#calibration-title').isVisible());
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'No mobile horizontal overflow');
 await page.screenshot({path:path.join(screenshots,'mobile-running.png')});
 unavailable=true;await page.locator('#refresh').click();await page.locator('#calibration-status[data-state="unknown"]').waitFor();
 assert((await page.locator('#calibration-context').textContent()).includes('Last confirmed: heat-pump calibration active'));
 assert.deepEqual(errors,[]);await browser.close();console.log('Chromium desktop/mobile: idle, running, progress, disconnect and overflow checks passed.');
})().catch(e=>{console.error(e);process.exit(1)});
