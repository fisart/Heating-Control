<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';

function IPS_InstanceExists($id) {return isset($GLOBALS['smtpInstances'][$id]);}
function IPS_GetInstance($id) {return ['ModuleInfo'=>['ModuleID'=>$GLOBALS['smtpInstances'][$id]['module']]];}
function IPS_GetProperty($id,$name) {return $GLOBALS['smtpInstances'][$id][$name]??'';}
function SMTP_SendMail($id,$subject,$body) {
    $GLOBALS['sentEmails'][]=[$id,$subject,$body];
    if ($GLOBALS['smtpThrow']??false) throw new RuntimeException('Fixture SMTP outage');
    return $GLOBALS['smtpSuccess']??true;
}
function reportFixture(): array {
    $report=['streams'=>[],'waterHouseIncludesGarden'=>true];
    foreach (['electricity'=>[18.42,5.34],'gas'=>[44.73,4.98],'house'=>[.386,1.79],'garden'=>[null,0.],'well'=>[0.,0.]] as $k=>[$volume,$cost])
        $report['streams'][$k]=['knownVolume'=>$volume,'knownCostEuro'=>$cost,'unit'=>in_array($k,['electricity','gas'],true)?'kWh':'m³',
            'incompleteVolumeDays'=>0,'incompleteCostDays'=>0,'provisionalDays'=>in_array($k,['gas','house'],true)?1:0];
    $report['streams']['garden']['referenceOnlyVolume']=true;
    return $report;
}
class FixtureReport extends FixturePrices {
    public int $now;
    public array $reportData=[],$reportQueries=[];
    public bool $reportFailure=false;
    protected function consumptionReportNow(): int {return $this->now;}
    public function GetTotalConsumption(string $from,string $through,string $resolution='auto'): string {
        if ($this->reportFailure) throw new RuntimeException('Fixture archive outage');
        $this->reportQueries[]=[$from,$through,$resolution];return json_encode($this->reportData,JSON_THROW_ON_ERROR);
    }
}
$z=new DateTimeZone('Europe/Berlin');$stamp=fn($s)=>(new DateTimeImmutable($s,$z))->getTimestamp();
foreach (['2026-03-29'=>23,'2026-10-25'=>25,'2026-10-13'=>24] as $date=>$hours)
    check(LichtBlickDailyReport::nextMidnight($stamp($date))- $stamp($date)===$hours*3600,'Schedule follows Berlin calendar through clock changes');
check(LichtBlickDailyReport::nextMidnight($stamp('2026-12-31 23:59:59'))===$stamp('2027-01-01'),'New-year rollover');
$m=new FixtureReport();$m->now=$stamp('2026-10-13 15:00:00');$m->Create();$GLOBALS['instance']=$m;$m->reportData=reportFixture();$m->ApplyChanges();
check($m->updates['timers']['ConsumptionReport']===0 && empty($GLOBALS['sentEmails']),'Email disabled by default');
$before=serialize([$m->properties,$m->attributes,$m->values]);$html=$m->GetDailyConsumptionReport();
check($before===serialize([$m->properties,$m->attributes,$m->values]) && empty($GLOBALS['sentEmails']),'Preview does not mutate or send');
check($m->reportQueries[0]===['2026-10-12','2026-10-12','daily'],'Preview queries exactly yesterday in Berlin');
check(str_contains($html,'12,11 €') && !str_contains($html,'<strong>Garden water</strong>'),'Four displayed rows sum to total without a garden row');
check(str_contains($html,'Provisional tariff / conversion') && str_contains($html,'0,000 m³') && str_contains($html,'0,00 €'),'Provisional values and measured free well zero remain explicit');
throws(fn()=>$m->GetDailyConsumptionReport('2026-10-13'),'Reject current incomplete day');
throws(fn()=>$m->GetDailyConsumptionReport('2026-02-30'),'Reject invalid day');
$m->properties['ConsumptionReportEnabled']=true;$m->ApplyChanges();
check($m->updates['timers']['ConsumptionReport']===0 && str_contains($m->values['ConsumptionReportStatus'],'Select an SMTP'),'Enabled without SMTP cannot send');
$GLOBALS['smtpInstances'][8001]=['module'=>'wrong','Recipient'=>'fixture@example.invalid'];$m->properties['ConsumptionReportSMTPID']=8001;$m->ApplyChanges();
check($m->updates['timers']['ConsumptionReport']===0,'Reject non-SMTP instance');
$GLOBALS['smtpInstances'][8001]['module']=LichtBlickDailyReport::SMTP_MODULE;$GLOBALS['smtpInstances'][8001]['Recipient']='';$m->ApplyChanges();
check(str_contains($m->values['ConsumptionReportStatus'],'recipient') && $m->updates['timers']['ConsumptionReport']===0,'SMTP recipient required');
$GLOBALS['smtpInstances'][8001]['Recipient']='fixture@example.invalid';$identity=$m->attributes['LearningIdentity'];$m->ApplyChanges();
check($identity===$m->attributes['LearningIdentity'],'Email configuration leaves heating learning intact');
check((int)$m->attributes['ConsumptionReportNextDue']===$stamp('2026-10-14') && $m->updates['timers']['ConsumptionReport']===9*3600*1000 && empty($GLOBALS['sentEmails']),'First enable waits for midnight independently of disabled heating');
$m->ConsumptionReportTick();check(empty($GLOBALS['sentEmails']),'Early/manual timer call does not send');
$m->now=$stamp('2026-10-14');$m->ConsumptionReportTick();
check(count($GLOBALS['sentEmails'])===1 && $m->attributes['ConsumptionReportLastSentDay']==='2026-10-13','Exactly midnight sends previous complete day');
check($GLOBALS['sentEmails'][0][0]===8001 && str_contains($GLOBALS['sentEmails'][0][1],'2026-10-13') && str_contains($GLOBALS['sentEmails'][0][2],'<html>'),'Selected SMTP receives HTML report');
check($m->values['ConsumptionReportHTML']===$GLOBALS['sentEmails'][0][2],'HTML child variable stores exactly the content sent');
$m->ConsumptionReportTick();$m->ApplyChanges();$m->ConsumptionReportTick();
check(count($GLOBALS['sentEmails'])===1,'Repeated callbacks and ApplyChanges do not duplicate email');
$restart=new FixtureReport();$restart->now=$stamp('2026-10-16 03:00:00');$restart->Create();$restart->properties=$m->properties;$restart->attributes=$m->attributes;$restart->reportData=reportFixture();$GLOBALS['instance']=$restart;$restart->ApplyChanges();
check($restart->updates['timers']['ConsumptionReport']===1000,'Overdue persisted midnight runs soon after restart');
$restart->ConsumptionReportTick();check(count($GLOBALS['sentEmails'])===2 && $restart->attributes['ConsumptionReportLastSentDay']==='2026-10-15','Restart sends yesterday only, without backlog');
$restart->now=$stamp('2026-10-17');$GLOBALS['smtpSuccess']=false;$restart->ConsumptionReportTick();
check(count($GLOBALS['sentEmails'])===3 && str_contains($restart->values['ConsumptionReportStatus'],'Report failed') && $restart->attributes['ConsumptionReportLastSentDay']==='2026-10-15','SMTP rejection stays failed, not sent');
check($restart->values['ConsumptionReportHTML']===$GLOBALS['sentEmails'][2][2] && str_contains($restart->values['ConsumptionReportHTML'],'16.10.2026'),'Generated daily HTML remains stored when sending fails');
$restart->ConsumptionReportTick();check(count($GLOBALS['sentEmails'])===3,'Failed attempt is not automatically repeated');
$restart->now=$stamp('2026-10-18');$GLOBALS['smtpThrow']=true;$restart->ConsumptionReportTick();$restart->ConsumptionReportTick();
check(count($GLOBALS['sentEmails'])===4 && str_contains($restart->values['ConsumptionReportStatus'],'Fixture SMTP outage'),'Thrown SMTP errors do not repeat and keep next midnight scheduled');
$restart->now=$stamp('2026-10-19');$restart->reportFailure=true;$restart->ConsumptionReportTick();
check(count($GLOBALS['sentEmails'])===4 && str_contains($restart->values['ConsumptionReportStatus'],'archive outage'),'Archive error cannot send a fabricated report');
$backup=$restart->ExportConfig();check(str_starts_with($restart->ImportConfig($backup),'Imported') && !$restart->properties['ConsumptionReportEnabled'] && $restart->updates['timers']['ConsumptionReport']===0,'Config restore disables reports');
$data=reportFixture();$data['streams']['house']['knownCostEuro']=null;$data['streams']['house']['incompleteCostDays']=1;$data['streams']['well']['knownVolume']=null;$data['streams']['well']['incompleteVolumeDays']=1;
$model=LichtBlickDailyReport::model('2026-10-12',$data);$html=LichtBlickDailyReport::html($model);
check(!$model['completeCost'] && $model['costCents']===1032 && str_contains($html,'Known subtotal') && str_contains($html,'Readings unavailable'),'Missing cost yields honest subtotal; missing well quantity stays unavailable, free cost stays zero');
$data=reportFixture();$data['streams']['garden']['knownCostEuro']=-.30;
check(LichtBlickDailyReport::model('2026-10-12',$data)['costCents']===1181,'Included garden tariff credit folds into main cost once');
$data['streams']['garden']['incompleteCostDays']=1;
check(!LichtBlickDailyReport::model('2026-10-12',$data)['completeCost'],'Unallocated garden adjustment does not imply complete main cost');
$data['streams']['garden']['referenceOnlyVolume']=false;
check(LichtBlickDailyReport::model('2026-10-12',$data)['costCents']===1211,'Separate garden charges are excluded');
$data=reportFixture();foreach(['electricity','gas','house'] as $key)$data['streams'][$key]['knownCostEuro']=.005;
check(LichtBlickDailyReport::model('2026-10-12',$data)['costCents']===3,'Total matches individually rounded rows');
$model=LichtBlickDailyReport::model('2026-10-12',reportFixture());$model['rows'][0]['notes']='<script>bad</script>';
check(!str_contains(LichtBlickDailyReport::html($model),'<script>'),'HTML escapes dynamic text');
$data=reportFixture();$data['streams']['electricity']['knownCostEuro']=-2.;
check(LichtBlickDailyReport::model('2026-10-12',$data)['costCents']===477,'Negative electricity prices remain signed in total cost');
$real=newModule();$before=serialize([$real->properties,$real->attributes,$real->values,$GLOBALS['history']??[]]);
$realPreview=$real->GetDailyConsumptionReport('2024-10-27');
check(str_contains($realPreview,'Known subtotal') && $before===serialize([$real->properties,$real->attributes,$real->values,$GLOBALS['history']??[]]),'Actual daily backend on Berlin 25-hour day stays read-only and missing sources do not look complete');
$form=json_decode(file_get_contents(__DIR__.'/../LichtBlickElectricityPrice/form.json'),true,32,JSON_THROW_ON_ERROR);$selector=null;
foreach($form['elements'] as $element)foreach($element['items']??[] as $field)if(($field['name']??'')==='ConsumptionReportSMTPID')$selector=$field;
check($selector['type']==='SelectInstance' && $selector['validModules']===[LichtBlickDailyReport::SMTP_MODULE],'Native selector filters object tree to SMTP');
$test=new FixtureReport();$test->now=$stamp('2026-10-13 12:00:00');$test->Create();$test->reportData=reportFixture();$test->properties['ConsumptionReportSMTPID']=8001;$test->ApplyChanges();
$GLOBALS['smtpSuccess']=true;$GLOBALS['smtpThrow']=false;$count=count($GLOBALS['sentEmails']);
$before=serialize([$test->attributes,$test->properties,$test->updates,$test->values['ConsumptionReportStatus']]);
check(str_contains($test->SendDailyConsumptionReportTest(),'accepted by SMTP') && count($GLOBALS['sentEmails'])===$count+1,'Explicit test button can send while daily reports are disabled');
check(str_starts_with($GLOBALS['sentEmails'][$count][1],'[TEST]') && $GLOBALS['sentEmails'][$count][0]===8001 && $test->values['ConsumptionReportHTML']===$GLOBALS['sentEmails'][$count][2],'Test uses selected SMTP, a marked subject and stored identical HTML');
check($before===serialize([$test->attributes,$test->properties,$test->updates,$test->values['ConsumptionReportStatus']]),'Test cannot enable, reschedule or consume daily send markers');
$test->properties['ConsumptionReportEnabled']=true;$test->ApplyChanges();$before=serialize([$test->attributes,$test->updates,$test->values['ConsumptionReportStatus']]);$test->SendDailyConsumptionReportTest();
check($before===serialize([$test->attributes,$test->updates,$test->values['ConsumptionReportStatus']]),'Test also preserves an enabled midnight schedule');
$GLOBALS['smtpSuccess']=false;check(str_contains($test->SendDailyConsumptionReportTest(),'Test report failed'),'SMTP test rejection is reported in the form result');
$GLOBALS['smtpThrow']=true;check(str_contains($test->SendDailyConsumptionReportTest(),'Fixture SMTP outage'),'SMTP test exceptions are caught');
$count=count($GLOBALS['sentEmails']);$test->properties['ConsumptionReportSMTPID']=0;
check(str_contains($test->SendDailyConsumptionReportTest(),'Select an SMTP') && count($GLOBALS['sentEmails'])===$count,'Unselected SMTP cannot send a test');
check(count(array_filter($form['actions'],fn($a)=>($a['onClick']??'')==='echo LBEP_SendDailyConsumptionReportTest($id);'))===1,'Form exposes the explicit test-email action');
if(($argv[1]??'')==='--sample')file_put_contents($argv[2],LichtBlickDailyReport::html(LichtBlickDailyReport::model('2026-10-12',reportFixture(),true)));
echo "Daily report: preview isolation, Berlin midnight/DST, SMTP selection, restart and duplicate handling, failures, free well, garden exclusion, incomplete/provisional costs and cent rounding passed.\n";
