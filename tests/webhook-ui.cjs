// Run: PHP_BIN=/path/to/php node tests/webhook-ui.cjs
// Playwright's Chromium must be installed. All HTTP replies are local fixtures.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');
(async()=>{
 const fixture=JSON.parse(execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'webhook-ui-fixture.php')],{encoding:'utf8'}));
 const options={executablePath:process.env.CHROMIUM_BIN||undefined,headless:true,args:['--no-sandbox']};
 const browser=await chromium.launch(options);
 const page=await browser.newPage({viewport:{width:1440,height:1050}}),errors=[],posts=[];
 let current=structuredClone(fixture.state),revoked=false;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://heating.example:46900/**',async route=>{
  const request=route.request();
  if(request.method()==='POST'){
   const payload=request.postDataJSON();posts.push(payload);assert.ok(request.headers()['x-heating-csrf']);
   current.controls[payload.control].value=payload.value;
   if(payload.control==='room:0')current.rooms[0].target=payload.value;
   if(payload.control==='RecoveryEnableID')current.residual=payload.value;
   return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,confirmed:true,state:current})});
  }
  if(request.url().includes('?view=state'))return route.fulfill({status:revoked?401:200,contentType:'application/json',body:JSON.stringify(revoked?{error:'Passkey session expired.'}:current)});
  return route.fulfill({status:200,headers:fixture.page.headers,body:fixture.page.body});
 });
 await page.goto('https://heating.example:46900/hook/heating_1');
 await page.locator('#rooms article').nth(3).waitFor();
 assert.equal(await page.locator('#rooms article').count(),4);
 assert.equal(await page.locator('#portal').getAttribute('href'),'/hook/secrets_7?portal=1');
 assert.equal(await page.locator('#environment .reading').count(),6);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.screenshot({path:'/tmp/heating-dashboard-desktop.png',fullPage:true});
 await page.locator('#rooms article').first().locator('input').fill('24.5');
 await page.locator('#rooms article').first().getByRole('button',{name:'Apply'}).click();
 await page.waitForFunction(()=>document.querySelector('#message')?.textContent.includes('Setting updated'));
 assert.deepEqual(posts[0],{control:'room:0',value:24.5});
 const recovery=page.locator('#controls .control').filter({hasText:'Allow residual heat recovery'});
 await recovery.getByRole('button',{name:'Turn off'}).click();
 await page.waitForFunction(()=>document.querySelector('#recovery').textContent.includes('disabled'));
 assert.deepEqual(posts[1],{control:'RecoveryEnableID',value:false});
 current.dryRun=true;current.editable=false;
 await page.getByRole('button',{name:'Refresh',exact:true}).click();
 await page.waitForFunction(()=>document.querySelector('#decision').textContent.includes('Dry run'));
 assert.equal(await page.locator('#rooms button:enabled,#controls button:enabled,#parameters button:enabled').count(),0);
 current.dryRun=false;current.editable=true;current.rooms[0].name='<img src=x onerror=alert(1)>';current.log='<script>throw Error("XSS")</script>';
 await page.getByRole('button',{name:'Refresh',exact:true}).click();
 await page.waitForFunction(()=>document.querySelector('#rooms h3').textContent.includes('<img'));
 assert.equal(await page.locator('#rooms img,#log script').count(),0);
 await page.setViewportSize({width:390,height:844});
 await page.screenshot({path:'/tmp/heating-dashboard-mobile.png',fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 revoked=true;await page.getByRole('button',{name:'Refresh',exact:true}).click();
 await page.getByRole('link',{name:'Sign in again'}).waitFor();
 assert.equal(await page.locator('#rooms button:enabled,#controls button:enabled,#parameters button:enabled').count(),0);
 assert.deepEqual(errors,[]);
 await browser.close();console.log('Heating UI: desktop/mobile layout, controls, dry run, escaped labels, and expired sessions passed.');
})().catch(e=>{console.error(e);process.exit(1);});
