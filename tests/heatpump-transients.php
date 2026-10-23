<?php
declare(strict_types=1);
require __DIR__.'/heatpump-fixture.php';
require_once __DIR__.'/../HeatingControl/HeatPumpTransient.php';

// Recorded temperatures, not a synthetic dip matching the implementation.
$replay=json_decode(file_get_contents(__DIR__.'/data/heatpump-transient-replay.json'),true,64,JSON_THROW_ON_ERROR);
foreach($replay['steps'] as $step) {
    $guard=[];$samples=[];
    foreach($step['samples'] as $sample){$samples[]=$sample;HeatPumpTransient::observe($guard,$samples,0,$sample['timestamp']);}
    check(count($guard['events'])===($step['powerPercent']===90?1:0),'Recorded replay detects only the 90% incident: '.$step['powerPercent']);
    if($step['powerPercent']===90) {
        $event=$guard['events'][0];
        check($event['startedAt']<=$event['onsetReferenceAt']-60&&$event['detectedAt']<500,'Pre-event bracket reaches before the temperature collapse');
        check($event['endedAt']===null&&!$event['confirmedDefrost'],'Original step ends before stable recovery; event remains suspected and unfinalized');
        $t=end($samples)['timestamp'];$last=end($samples);
        for($i=0;$i<60;$i++){$last['timestamp']=$t+=10;$samples[]=$last;HeatPumpTransient::observe($guard,$samples,0,$t);if(!HeatPumpTransient::active($guard))break;}
        $event=$guard['events'][0];
        check($event['endedAt']!==null&&$event['endedAt']-$event['recoveredAt']===120,'Recovered replay includes two minutes of post padding');
        check($event['recoveredAt']-$event['recoverySince']>=120,'Recovery must stay stable for two minutes before post padding');
    }
}
// Independent duration-weighted integration and non-overlapping interval union.
$guard=['events'=>[['startedAt'=>20,'endedAt'=>50],['startedAt'=>40,'endedAt'=>60]]];
$intervals=HeatPumpTransient::intervals($guard,0,90);
check($intervals===[['startedAt'=>0,'endedAt'=>20],['startedAt'=>60,'endedAt'=>90]],'Overlapping brackets are merged rather than double-subtracted');
$samples=[];foreach([0,10,20,30,40,50,60,70,80,90] as $t)$samples[]=['timestamp'=>$t,'v'=>$t<20?1:($t<60?100:3)];
check(abs(HeatPumpTransient::mean($samples,'v',$intervals)-2.2)<1e-10,'Thermal means are weighted by retained seconds, not sample count or excluded values');
$records=[['TimeStamp'=>0,'Value'=>0],['TimeStamp'=>20,'Value'=>20],['TimeStamp'=>60,'Value'=>100],['TimeStamp'=>90,'Value'=>130]];
check(HeatPumpTransient::energy($records,$intervals,1)===50.0,'Electrical energy sums precisely the retained intervals');
foreach([[['startedAt'=>0,'endedAt'=>60],['startedAt'=>40,'endedAt'=>90]],[['startedAt'=>-1,'endedAt'=>10]],[['startedAt'=>1,'endedAt'=>91]],[]] as $bad) {
    try {HeatPumpTransient::measurementIntervals(['stepStartedAt'=>0,'stepEndedAt'=>90,'measurementIntervals'=>$bad]);check(false,'Invalid interval must fail');}
    catch(RuntimeException $e){check(true,'Invalid retained interval cannot bridge or overlap measurement bounds');}
}
// Ordinary temperature modulation or an inlet-only change is not a defrost signal.
foreach(['normal','inlet'] as $kind) {
    $guard=[];$samples=[];
    for($t=0;$t<=600;$t+=10) {
        $r=['timestamp'=>$t,'incoming'=>$kind==='inlet'&&$t>=300?33.0:23.0,'outgoing'=>43.0+sin($t/50),'coil'=>48.0+sin($t/50)];
        $samples[]=$r;HeatPumpTransient::observe($guard,$samples,0,$t);
    }
    check(!$guard['events'],'No transient for '.$kind.' variation');
}

function transientFixture(string $source='dedicated'): TestHeating {
    $m=fixture();$m->properties['HeatPumpTestMeterSource']=$source;
    if($source==='dedicated') {
        foreach([28681=>100.0,22365=>10.0,57639=>0.48] as $id=>$v)$GLOBALS['variables'][$id]=['VariableType'=>2,'value'=>$v,'VariableUpdated'=>$m->clock];
        $GLOBALS['variables'][37456]=['VariableType'=>0,'value'=>true,'VariableUpdated'=>$m->clock];
        foreach([22365,57639] as $id)$GLOBALS['loggedValues'][$id]=[['TimeStamp'=>$m->clock,'Value'=>GetValue($id)]];
    }
    $GLOBALS['transientTrueEnergy']=10.0;
    return $m;
}
function transientTick(TestHeating $m,bool $cold=false,int $seconds=10,int $poll=10): void {
    $s=json_decode($m->attributes['HeatPumpTestState']?:'{}',true);
    if($m->properties['HeatPumpTestMeterSource']==='dedicated') {
        $GLOBALS['transientTrueEnergy']+=GetValue(28681)*$seconds/3600000;
        $m->clock+=$seconds;
        if($m->clock%$poll===0)foreach([22365,57639] as $id) {
            if($id===22365)$GLOBALS['variables'][$id]['value']=$GLOBALS['transientTrueEnergy'];
            $GLOBALS['variables'][$id]['VariableUpdated']=$m->clock;
            $GLOBALS['loggedValues'][$id][]=['TimeStamp'=>$m->clock,'Value'=>GetValue($id)];
        }
        $GLOBALS['variables'][28681]['value']=($s['phase']??'')==='step'?2000.0:100.0;
    } else {
        $GLOBALS['variables'][38023]['value']+=GetValue(13869)*$seconds/3600;$m->clock+=$seconds;
        $GLOBALS['loggedValues'][38023][]=['TimeStamp'=>$m->clock,'Value'=>GetValue(38023)];
        $GLOBALS['variables'][13869]['value']=($s['phase']??'')==='step'?2180.0:180.0;
    }
    if(($s['phase']??'')==='step')foreach(['IncomingAirTempID'=>23.0,'OutgoingAirTempID'=>$cold?20.0:35.0,'HeatPumpTempID'=>$cold?24.0:48.0] as $k=>$v)$GLOBALS['variables'][$m->properties[$k]]['value']=$v;
    foreach($GLOBALS['variables'] as $id=>&$v)if(!in_array($id,[22365,57639],true)||$m->properties['HeatPumpTestMeterSource']!=='dedicated')$v['VariableUpdated']=$m->clock;unset($v);
    $m->ProcessHeating();
}
function transientState(TestHeating $m):array{return json_decode($m->attributes['HeatPumpTestState']?:'{}',true);}
function reachTransient(TestHeating $m):void {
    for($i=0;$i<700&&$m->values['HeatPumpTestActive'];$i++) {
        $s=transientState($m);$cold=($s['phase']??'')==='step'&&$s['powers'][$s['index']]===90&&$m->clock-$s['phaseSince']>=100;
        transientTick($m,$cold);
        if(HeatPumpTransient::active(transientState($m)['stepTransient']??[]))return;
    }
    check(false,'Fixture reaches a transient');
}
foreach([['dedicated',10],['dedicated',60],['house',10]] as [$source,$poll]) {
    $m=transientFixture($source);$m->StartHeatPumpTest(5);$eventSeen=false;$powerHeld=true;$dropSamples=0;
    for($i=0;$i<800&&$m->values['HeatPumpTestActive'];$i++) {
        $s=transientState($m);$power=($s['phase']??'')==='step'?$s['powers'][$s['index']]:0;$elapsed=$m->clock-($s['phaseSince']??$m->clock);
        $cold=$power===90&&$elapsed>=100&&$elapsed<160;
        transientTick($m,$cold,10,$poll);$new=transientState($m);
        if($cold)$dropSamples++;
        if(HeatPumpTransient::active($new['stepTransient']??[])){$eventSeen=true;$powerHeld=$powerHeld&&GetValue(35931)&&GetValue(19207)===90&&!GetValue(32875);}
    }
    $report=json_decode($m->values['HeatPumpTestResults'],true);$p=$report['points'][6];
    check(!$m->values['HeatPumpTestActive']&&$report['complete']&&count($report['points'])===8,'All eight steps finish with a recovered transient: '.$source.' / '.$poll);
    check($eventSeen&&$powerHeld,'During quarantine heat pump remains enabled at the same power and gas stays off');
    check($p['stable']&&$p['usable']&&$p['excludedSeconds']>0&&$p['measurementSeconds']>=300,'Recovered power step yields a clean stable usable calibration');
    check(count($p['measurementIntervals'])===2&&count($p['excludedIntervals'])===1,'Clean measurements before AND after the interruption are retained');
    check($p['stepEndedAt']-$p['stepStartedAt']===$p['measurementSeconds']+$p['excludedSeconds'],'Wall time, retained time and excluded time reconcile');
    check(abs($p['systemInputKW']-2.0)<1e-6&&abs($p['estimatedSystemCOP']-4.02)<1e-6,'Matched clean heat and counter energy give the independently known COP');
    check($p['fullStep']['measurementSeconds']===$p['stepEndedAt']-$p['stepStartedAt']&&$p['fullStep']['estimatedSystemCOP']<$p['estimatedSystemCOP'],'Unfiltered full-step energy/COP is retained and includes the loss');
    check(abs($p['fullStep']['netElectricityKWh']-$p['fullStep']['measurementSeconds']*2/3600)<1e-6,'Full-step electricity includes all excluded seconds');
    check($p['counterFinalized']&&count(HeatPumpMatrix::validate([$p]))===1,'Strict provenance and interval validation accepts recovered calibration');
    $raw=file($root.'/media/heatpump-tests/1/'.$report['runID'].'.jsonl');$rows=array_map(static fn($s)=>json_decode($s,true),$raw);
    check(count(array_filter($rows,static fn($r)=>$r['kind']==='sample'&&($r['sample']['powerPercent']??0)===90&&$r['sample']['outgoing']===20))===$dropSamples,'Excluded cold samples remain in append-only raw readings');
    check(count(array_filter($rows,static fn($r)=>$r['kind']==='transient_exclusion_started'))===1&&count($report['transientLog'])===3,'Start, stable recovery and end are audited');
    $last=end($rows);check($last['kind']==='footer'&&$last['report']===$report,'Raw footer and report agree');
    $wrong=$p;$wrong['measurementSeconds']+=10;
    try{HeatPumpMatrix::validate([$wrong]);check(false,'Inconsistent retained duration must fail');}catch(InvalidArgumentException $e){check(true,'Retained duration cannot be altered independently');}
}
// A relapse just after release reuses the known reference; overlapping padding is safe.
$guard=[];$samples=[];
for($t=0;$t<=420;$t+=10) {
    $cold=($t>=100&&$t<160)||$t>=410;
    $samples[]=['timestamp'=>$t,'incoming'=>23.0,'outgoing'=>$cold?20.0:35.0,'coil'=>$cold?24.0:48.0];
    HeatPumpTransient::observe($guard,$samples,0,$t);
}
check(count($guard['events'])===2&&HeatPumpTransient::active($guard),'An immediate relapse is detected without waiting for a new minute of warm history');
check($guard['events'][1]['startedAt']<$guard['events'][0]['endedAt'],'Immediate-relapse safety brackets can overlap');

$guard=[];$samples=[];$limited=false;
for($t=0;$t<=1200;$t+=10) {
    $cold=false;foreach([100,410,720,1030] as $drop)if($t>=$drop&&$t<$drop+60)$cold=true;
    $samples[]=['timestamp'=>$t,'incoming'=>23.0,'outgoing'=>$cold?20.0:35.0,'coil'=>$cold?24.0:48.0];
    try{HeatPumpTransient::observe($guard,$samples,0,$t);}catch(RuntimeException $e){$limited=str_contains($e->getMessage(),'More than three');break;}
}
check($limited&&count($guard['events'])===3,'A fourth event cannot extend the same power step indefinitely');

foreach(['reset','export','offline'] as $fault) {
    $m=transientFixture();$m->StartHeatPumpTest(5);reachTransient($m);$m->clock+=10;
    if($fault==='reset')$GLOBALS['variables'][22365]['value']=0.0;
    if($fault==='export')$GLOBALS['variables'][57639]['value']+=.01;
    if($fault==='offline')$GLOBALS['variables'][37456]['value']=false;
    $m->ProcessHeating();
    for($i=0;$i<10&&$m->values['HeatPumpTestActive'];$i++)transientTick($m,true);
    check(!$m->values['HeatPumpTestActive']&&!GetValue(35931),'Thermal exclusion cannot hide a meter fault: '.$fault);
}

// Failure and existing safety controls remain effective during quarantine.
foreach(['cancel','disabled','hot-room','timer','timeout'] as $fault) {
    $m=transientFixture();$m->StartHeatPumpTest(5);reachTransient($m);
    if($fault==='cancel')$m->CancelHeatPumpTest();
    elseif($fault==='disabled'){$m->properties['Enabled']=false;$m->ProcessHeating();}
    elseif($fault==='hot-room'){$GLOBALS['variables'][57694]['value']=28.0;transientTick($m,true);}
    elseif($fault==='timer')transientTick($m,true,100);
    else for($i=0;$i<150&&$m->values['HeatPumpTestActive'];$i++)transientTick($m,true);
    for($i=0;$i<10&&$m->values['HeatPumpTestActive'];$i++)transientTick($m,true);
    check(!$m->values['HeatPumpTestActive']&&($fault==='disabled'||!GetValue(35931)),'Safety/cancel or disabled release ends transient ownership: '.$fault);
    if($fault==='timeout')check(str_contains($m->values['HeatPumpTestStatus'],'recovery timed out'),'Unrecovered transient times out explicitly');
}
// Reboot preserves the interruption audit but repeats a fresh measurement; no bridge across shutdown.
$m=transientFixture();$m->StartHeatPumpTest(5);reachTransient($m);$s=transientState($m);$s['nextRebootAt']=$m->clock+600;$s['settings']['HeatPumpTestRebootGuardEnabled']=true;
$call=new ReflectionMethod(HeatingControl::class,'testNeedsRebootPause');
check($call->invoke($m,$s,$m->clock),'Remaining clean time plus recovery bracket prevents crossing scheduled reboot');
$pause=new ReflectionMethod(HeatingControl::class,'beginTestRebootPause');$pause->invokeArgs($m,[&$s]);
check(isset($s['rebootPause']['discardedTransient'])&&!isset($s['stepTransient'],$s['cleanMeasurementSeconds']),'Planned reboot retains transient audit and discards the partial measurement state');
$m->attributes['HeatPumpTestState']=json_encode($s);$m->CancelHeatPumpTest();for($i=0;$i<10&&$m->values['HeatPumpTestActive'];$i++)transientTick($m);

// Guard off keeps the historical fixed-duration behavior.
$m=transientFixture();$m->properties['HeatPumpTestTransientGuardEnabled']=false;$m->StartHeatPumpTest(5);
for($i=0;$i<600&&$m->values['HeatPumpTestActive'];$i++) {
    $s=transientState($m);$power=($s['phase']??'')==='step'?$s['powers'][$s['index']]:0;$elapsed=$m->clock-($s['phaseSince']??$m->clock);
    transientTick($m,$power===90&&$elapsed>=100&&$elapsed<160);
}
$r=json_decode($m->values['HeatPumpTestResults'],true);
check($r['complete']&&!$r['transientLog']&&$r['points'][6]['excludedSeconds']===0,'Explicitly disabled guard never removes samples');
echo "PASS: $checks temperature-transient replay, matched intervals, recovery and safety checks\n";

