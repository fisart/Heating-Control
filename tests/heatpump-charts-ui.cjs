// Local PHP fixtures in real Chromium, with the page's actual CSP.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process'),{chromium}=require('playwright');
(async()=>{
 const php=process.env.PHP_BIN||'php',fixture=JSON.parse(execFileSync(php,[path.join(__dirname,'webhook-ui-fixture.php')],{encoding:'utf8'}));
 const charts=JSON.parse(execFileSync(php,['-r',`require '${path.join(__dirname,'../HeatingControl/HeatPumpSurface.php')}'; echo json_encode(HeatPumpSurface::chartData());`],{encoding:'utf8'}));
 const out=path.join(__dirname,'artifacts/heatpump-charts');fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_BIN||undefined,headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[];let loads=0;
 page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
 await page.route('https://heating.example:46900/**',route=>{
  const url=route.request().url();if(url.includes('?view=state'))return route.fulfill({contentType:'application/json',body:JSON.stringify(fixture.state)});
  if(url.includes('?view=heatpump-surface')){loads++;return route.fulfill({contentType:'application/json',body:JSON.stringify(charts)});}
  return route.fulfill({headers:fixture.page.headers,body:fixture.page.body});
 });
 await page.goto('https://heating.example:46900/hook/heating_1');await page.locator('#calibration-status[data-state="idle"]').waitFor();assert.equal(loads,0,'Overview does not fetch the chart grid');
 await page.getByRole('tab',{name:'3D charts',exact:true}).click();await page.locator('#surface-status').filter({hasText:'47 fitted observations'}).waitFor();
 assert.equal(loads,1);assert.equal(await page.locator('#heating-overview').isVisible(),false);assert.equal(await page.locator('#surface-measurements tr').count(),47);
 assert.equal(await page.locator('#surface-measurements tr').filter({hasText:charts.latestRunID}).count(),8);
 assert.equal(await page.locator('#calibration-status').isVisible(),true,'Calibration status stays visible in the chart tab');
 const first=await page.locator('#surface-canvas').evaluate(c=>c.toDataURL());
 await page.selectOption('#surface-intake','10');assert.notEqual(await page.locator('#surface-canvas').evaluate(c=>c.toDataURL()),first,'Intake selection redraws the model at fixed intake');
 assert.equal(loads,1,'Intake changes use the loaded read-only slices');
 assert.match(await page.locator('#surface-status').textContent(),/Mesh intake 10°C/);
 assert.equal(await page.locator('#surface-measurements tr').count(),47,'Intake selection preserves every recorded observation');
 await page.selectOption('#surface-intake','15');
 await page.selectOption('#surface-metric','systemInputKW');assert.notEqual(await page.locator('#surface-canvas').evaluate(c=>c.toDataURL()),first,'Metric change redraws exact electrical input grid');
 await page.locator('#surface-canvas').focus();await page.keyboard.press('ArrowRight');await page.keyboard.press('+');
 await page.screenshot({path:path.join(out,'desktop.png'),fullPage:true});
 await page.setViewportSize({width:390,height:844});await page.selectOption('#surface-metric','estimatedSystemCOP');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'No mobile page overflow');
 await page.screenshot({path:path.join(out,'mobile.png'),fullPage:true});
 await page.getByRole('tab',{name:'Heating overview',exact:true}).click();assert.equal(await page.locator('#heating-overview').isVisible(),true);
 await page.getByRole('tab',{name:'Heating overview',exact:true}).press('ArrowRight');assert.equal(await page.getByRole('tab',{name:'3D charts',exact:true}).getAttribute('aria-selected'),'true');assert.equal(loads,1,'Returning to chart tab reuses the current model');
 assert.deepEqual(errors,[]);await browser.close();console.log('PASS: 3D charts, lazy loading, all eight new points, metric switch, rotation, keyboard tabs, CSP and mobile layout');
})().catch(e=>{console.error(e);process.exit(1);});
