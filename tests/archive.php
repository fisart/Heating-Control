<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
$checks=0;
function check($truth,$why) { global $checks; if (!$truth) throw new RuntimeException($why); $checks++; }
function stateOf($m) { return json_decode(response($m,'GET',['view'=>'state'])['body'],true); }
function history($m,$id='25911',$range='24h') { return response($m,'GET',['view'=>'history','id'=>$id,'range'=>$range]); }
$m=heatingFixture();$GLOBALS['authenticated']=true;
if (($argv[1] ?? '')==='--no-archive-api') {
    check(!stateOf($m)['environment']['OutsideTempID']['chart'],'Missing archive API disables chart links');
    check(history($m)['status']===422,'Missing archive API cannot serve history');
    echo "Missing archive API checks passed.\n";exit;
}
$s=stateOf($m);
check(!$s['environment']['OutsideTempID']['chart']&&!$s['rooms'][0]['sensors'][0]['chart'],'Unrecorded sensors have no chart');
check($s['environment']['OutsideTempID']['id']===25911,'Sensor ID retained for chart requests');
check(history($m)['status']===422&&!$GLOBALS['historyCalls'],'Unrecorded history rejected before archive query');
$GLOBALS['archiveLogging'][9]=[25911=>true,57694=>true,12594=>true,29718=>true,12345=>true];
$s=stateOf($m);
check($s['environment']['OutsideTempID']['chart']&&$s['rooms'][0]['sensors'][0]['chart'],'Only actively logged sensor values are clickable');
check(!$s['environment']['IncomingAirTempID']['chart']&&!$s['equipment']['FanSpeedID']['chart'],'Other sensors and device settings not made clickable');
check($s['equipment']['HeatPumpHeatModeID']['value']===false&&isset($s['mixerOpen'],$s['mixerClosed']),'Heat/cool mode and configured mixer semantics supplied');
check($s['rooms'][0]['flapClosed']===true,'Room flap closed state supplied');
$t=(int)(floor(time()/3600)*3600);
$GLOBALS['archiveValues'][25911]=[
    ['TimeStamp'=>$t,'Avg'=>20.5,'Min'=>20.1,'Max'=>20.8,'Duration'=>3600],
    ['TimeStamp'=>$t-3600,'Avg'=>19.5,'Min'=>19.1,'Max'=>19.8,'Duration'=>3600],
    ['TimeStamp'=>$t+7200,'Avg'=>99], // Out-of-range records are not plotted.
    ['TimeStamp'=>$t-7200,'Avg'=>NAN],
];
$r=history($m);$h=json_decode($r['body'],true);
check($r['status']===200&&count($h['points'])===2,'Bounded valid history returned');
check($h['points'][0]['time']<$h['points'][1]['time']&&$h['points'][0]['value']===19.5,'Archive reverse order sorted chronologically');
check($h['points'][0]['min']===19.1&&$h['points'][0]['max']===19.8,'Min/max envelope retained');
check($h['aggregation']==='hourly'&&end($GLOBALS['historyCalls'])[2]===0&&end($GLOBALS['historyCalls'])[5]===513,'Pre-aggregated hourly data with a hard limit');
foreach (['6h','24h','7d','30d'] as $range) check(history($m,'25911',$range)['status']===200,'Supported period '.$range);
check(end($GLOBALS['historyCalls'])[2]===1,'30-day view uses daily pre-aggregation');
foreach (['12345','12594','29718','57844','1','-1','25911abc',['25911']] as $id) {
    $before=count($GLOBALS['historyCalls']);check(history($m,$id)['status']===422&&count($GLOBALS['historyCalls'])===$before,'Arbitrary/non-sensor IDs never reach archive');
}
foreach (['1y','0','999999',['24h']] as $range) check(history($m,'25911',$range)['status']===422,'Unbounded/invalid period rejected');
$GLOBALS['archiveLogging'][9][25911]=false;
check(history($m)['status']===422&&!stateOf($m)['environment']['OutsideTempID']['chart'],'Disabling logging revokes stale chart links server-side');
$GLOBALS['archiveLogging'][9][25911]=true;$GLOBALS['archiveTypes'][9][25911]=1;
check(history($m)['status']===422&&!stateOf($m)['environment']['OutsideTempID']['chart'],'Counter aggregation cannot masquerade as temperature history');
$GLOBALS['archiveTypes'][9][25911]=0;$GLOBALS['instanceModules'][10]=$GLOBALS['instanceModules'][9];$GLOBALS['archiveLogging'][10][25911]=true;
check(history($m)['status']===422&&!stateOf($m)['environment']['OutsideTempID']['chart'],'Ambiguous archive ownership fails closed');unset($GLOBALS['instanceModules'][10]);
$GLOBALS['archiveThrows']=true;check(history($m)['status']===422&&response($m)['status']===200,'Archive outage leaves dashboard available without history');$GLOBALS['archiveThrows']=false;
$GLOBALS['archiveValues'][25911]=[];$h=json_decode(history($m)['body'],true);check($h['points']===[],'Empty period is explicit; no invented live/history points');
$GLOBALS['archiveValues'][25911]=array_fill(0,513,['TimeStamp'=>$t,'Avg'=>20]);$h=json_decode(history($m)['body'],true);
check($h['truncated']===true&&count($h['points'])===512,'History response bounded even if archive returns too many rows');
$GLOBALS['authenticated']=false;$before=count($GLOBALS['historyCalls']);check(history($m)['status']===401&&count($GLOBALS['historyCalls'])===$before,'Unauthenticated requests cannot query history');
$GLOBALS['authenticated']=true;check(response($m,'GET',['view'=>'history','id'=>'25911'],null,['HTTP_HOST'=>'evil.example'])['status']===403,'History shares HTTPS host protection');
$m->properties['DryRun']=true;$m->properties['Enabled']=false;check(history($m)['status']===200,'Charts remain available for a read-only disabled controller');
$m->properties['WebEnabled']=false;check(history($m)['status']===503,'Disabled web access blocks archive reads');
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Archive links, state and chart reads never write variables or activate equipment');
echo "Archive charts: $checks assertions passed.\n";
