<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
$checks=0;
function check($truth,$why) { global $checks; if (!$truth) throw new RuntimeException($why); $checks++; }
function stateOf($m) { return json_decode(response($m,'GET',['view'=>'state'])['body'],true); }
function history($m,$id='25911',$range='24h',$resolution='auto') { return response($m,'GET',['view'=>'history','id'=>$id,'range'=>$range,'resolution'=>$resolution]); }
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
check($h['aggregation']==='hourly'&&end($GLOBALS['historyCalls'])[2]===0&&end($GLOBALS['historyCalls'])[5]===801,'Pre-aggregated hourly data with a hard limit');
foreach (['1h','6h','24h','7d','30d'] as $range) check(history($m,'25911',$range)['status']===200,'Supported period '.$range);
check(end($GLOBALS['historyCalls'])[2]===1,'30-day view uses daily pre-aggregation');
check($h['name']==='Outside temperature · Variable 25911','History names the actual sensor and its environmental role');
check($s['rooms'][0]['sensors'][0]['name']==='Living and Dining Room · Variable 57694','Room sensor name includes room context');
$now=time();
$GLOBALS['loggedValues'][25911]=[
    ['TimeStamp'=>$now-120,'Value'=>21.2,'Duration'=>120],
    ['TimeStamp'=>$now-900,'Value'=>20.9,'Duration'=>780],
    ['TimeStamp'=>$now-3700,'Value'=>99],
    ['TimeStamp'=>$now-600,'Value'=>NAN],
];
$h=json_decode(history($m,'25911','1h')['body'],true);$call=end($GLOBALS['historyCalls']);
check($h['to']-$h['from']===3600&&$call[4]-$call[3]===3600,'Last hour is exactly the preceding 60 minutes');
check($h['aggregation']==='recorded'&&$call[2]==='recorded'&&count($h['points'])===2,'Last hour automatic resolution uses valid archived readings');
check($h['points'][0]['value']===20.9&&$h['points'][0]['min']===20.9&&$h['points'][0]['max']===20.9,'Raw readings are not labelled as averages');
foreach (['recorded','hourly','daily'] as $resolution) {
    foreach (['1h','6h','24h','7d','30d'] as $range) {
        $r=history($m,'25911',$range,$resolution);$h=json_decode($r['body'],true);$call=end($GLOBALS['historyCalls']);
        check($r['status']===200&&$h['aggregation']===$resolution&&$call[2]===['recorded'=>'recorded','hourly'=>0,'daily'=>1][$resolution],'Explicit resolution '.$resolution.' for '.$range);
        check($h['to']-$h['from']===['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000][$range],'Period is independent of resolution');
    }
}
foreach (['minute','999',['hourly']] as $resolution) {
    $before=count($GLOBALS['historyCalls']);check(history($m,'25911','1h',$resolution)['status']===422&&count($GLOBALS['historyCalls'])===$before,'Invalid resolution rejected before archive access');
}
$GLOBALS['archiveValues'][25911]=[];
for ($i=0;$i<=720;$i++) $GLOBALS['archiveValues'][25911][]=['TimeStamp'=>$t-$i*3600,'Avg'=>20,'Duration'=>3600];
$h=json_decode(history($m,'25911','30d','hourly')['body'],true);
check(count($h['points'])===721&&!$h['truncated'],'Full 30-day hourly history fits without truncation');
$GLOBALS['loggedValues'][25911]=array_fill(0,801,['TimeStamp'=>time()-10,'Value'=>20]);
$h=json_decode(history($m,'25911','1h','recorded')['body'],true);
check(count($h['points'])===800&&$h['truncated'],'Raw history is bounded and truncation is explicit');
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
$GLOBALS['archiveValues'][25911]=array_fill(0,801,['TimeStamp'=>$t,'Avg'=>20]);$h=json_decode(history($m)['body'],true);
check($h['truncated']===true&&count($h['points'])===800,'History response bounded even if archive returns too many rows');
$GLOBALS['authenticated']=false;$before=count($GLOBALS['historyCalls']);check(history($m)['status']===401&&count($GLOBALS['historyCalls'])===$before,'Unauthenticated requests cannot query history');
$GLOBALS['authenticated']=true;check(response($m,'GET',['view'=>'history','id'=>'25911'],null,['HTTP_HOST'=>'evil.example'])['status']===403,'History shares HTTPS host protection');
$m->properties['DryRun']=true;$m->properties['Enabled']=false;check(history($m)['status']===200,'Charts remain available for a read-only disabled controller');
$m->properties['WebEnabled']=false;check(history($m)['status']===503,'Disabled web access blocks archive reads');
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Archive links, state and chart reads never write variables or activate equipment');
echo "Archive charts: $checks assertions passed.\n";
