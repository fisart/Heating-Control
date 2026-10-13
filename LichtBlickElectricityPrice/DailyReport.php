<?php
declare(strict_types=1);

/** Email view of the reviewed daily totals. It never allocates manual period readings to days. */
final class LichtBlickDailyReport
{
    public const SMTP_MODULE = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

    public static function nextMidnight(int $now): int
    {
        return (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Berlin'))->setTime(0,0)->modify('+1 day')->getTimestamp();
    }

    public static function model(string $date, array $report, bool $sample=false): array
    {
        $rows=[]; $cents=0; $costParts=0; $complete=true; $provisional=false;
        foreach (['electricity'=>'Electricity','gas'=>'Gas','house'=>'House water','well'=>'Well water'] as $key=>$label) {
            $s=$report['streams'][$key];
            $cost=$s['knownCostEuro'];
            $costComplete=$s['incompleteCostDays']===0 && $cost!==null;
            $estimated=($s['provisionalDays']??0)>0;
            // In the main-meter view the hidden garden entry is a tariff credit, not another billable stream.
            if ($key==='house' && ($report['streams']['garden']['referenceOnlyVolume']??false)) {
                $g=$report['streams']['garden'];
                if ($cost!==null) $cost+=$g['knownCostEuro']??0.;
                $costComplete=$costComplete && $g['incompleteCostDays']===0;
                $estimated=$estimated || ($g['provisionalDays']??0)>0;
            }
            if ($key==='well') {$cost=0.; $costComplete=true;}
            $volumeComplete=$s['incompleteVolumeDays']===0 && $s['knownVolume']!==null;
            $notes=[];
            if (!$volumeComplete) $notes[]=$s['knownVolume']===null?'Readings unavailable':'Partial readings';
            if (!$costComplete) $notes[]=$cost===null?'Cost unavailable':'Known cost only';
            if ($estimated) $notes[]='Provisional tariff / conversion';
            if ($key==='well') $notes[]='No water charge';
            if (!$notes) $notes[]='Complete data';
            $rowCents=$cost===null?null:(int)round($cost*100);
            if ($rowCents!==null) {$cents+=$rowCents; $costParts++;}
            $rows[]=['key'=>$key,'label'=>$label,'volume'=>$s['knownVolume'],'unit'=>$s['unit'],
                'costCents'=>$rowCents,'notes'=>implode(' · ',$notes),'volumeComplete'=>$volumeComplete,'costComplete'=>$costComplete];
            $complete=$complete && $costComplete; $provisional=$provisional || $estimated;
        }
        return ['date'=>$date,'rows'=>$rows,'costCents'=>$costParts?$cents:null,'completeCost'=>$complete,
            'provisional'=>$provisional,'mainIncludesGarden'=>$report['waterHouseIncludesGarden']??false,'sample'=>$sample];
    }

    public static function html(array $model): string
    {
        $escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $money=static fn(?int $v):string=>$v===null?'Unavailable':number_format($v/100,2,',','.').' €';
        $date=(new DateTimeImmutable($model['date'],new DateTimeZone('Europe/Berlin')))->format('d.m.Y');
        $body='';
        foreach ($model['rows'] as $r) {
            $quantity=$r['volume']===null?'Unavailable':number_format($r['volume'],$r['unit']==='m³'?3:2,',','.').' '.$r['unit'];
            $body.='<tr><td style="padding:17px 20px;border-bottom:1px solid #e8edf2;"><strong>'.$escape($r['label']).'</strong><br><span style="font-size:12px;color:#67768a;">'.$escape($r['notes']).'</span></td><td style="padding:17px 12px;text-align:right;border-bottom:1px solid #e8edf2;white-space:nowrap;">'.$escape($quantity).'</td><td style="padding:17px 20px;text-align:right;border-bottom:1px solid #e8edf2;white-space:nowrap;font-weight:bold;">'.$escape($money($r['costCents'])).'</td></tr>';
        }
        $sample=$model['sample']?'<div style="padding:12px 20px;background:#fff3d6;color:#71510c;font-size:13px;"><strong>SAMPLE FOR APPROVAL</strong> · Illustrative figures, not live readings.</div>':'';
        $totalLabel=$model['completeCost']?'Total calculated cost':'Known subtotal · incomplete costs';
        $status=$model['provisional']?'Includes provisional tariffs or conversion factors.':'Calculated from available dated tariffs.';
        $main=$model['mainIncludesGarden']?'House water is the main meter and includes garden use. There is no separate garden-water row or double counting. Manual period readings are not spread over days.':'Garden water is excluded from this report.';
        return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Daily consumption · '.$escape($date).'</title></head><body style="margin:0;padding:24px 8px;background:#eff3f7;font-family:Arial,Helvetica,sans-serif;color:#172b41;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #e1e7ee;border-radius:12px;overflow:hidden;">'
            .'<tr><td colspan="3">'.$sample.'<div style="padding:30px 24px;background:#143b52;color:#ffffff;"><div style="font-size:12px;letter-spacing:2px;color:#b5d7e3;">VERBRAUCH &amp; KOSTEN</div><h1 style="font-size:27px;margin:12px 0 8px;">Your daily consumption</h1><div style="font-size:15px;">'.$escape($date).' · 00:00–24:00 · Europe/Berlin</div></div></td></tr>'
            .'<tr style="background:#f7f9fc;color:#67768a;font-size:12px;"><th align="left" style="padding:12px 20px;">CONSUMPTION</th><th align="right" style="padding:12px;">QUANTITY</th><th align="right" style="padding:12px 20px;">COST · EUR</th></tr>'.$body
            .'<tr><td colspan="3" style="padding:24px;background:#eaf4f3;"><div style="font-size:13px;color:#37645f;">'.$escape($totalLabel).'</div><div style="font-size:34px;font-weight:bold;margin:8px 0;">'.$escape($money($model['costCents'])).'</div><div style="font-size:12px;color:#37645f;">'.$escape($status).'</div></td></tr>'
            .'<tr><td colspan="3" style="padding:22px 24px;font-size:12px;line-height:1.7;color:#67768a;">Gross calculated supply costs, including documented daily fixed charges. Later electricity readings currently include energy charges only; undocumented standing charges are not added. Invoice aid and billing reconciliation remain separate.<br><br>'.$escape($main).'<br><br>Missing values remain unavailable. Partial amounts are labelled as known costs. Well water has no water charge; pump electricity is included in the electricity meter.</td></tr></table><div style="padding:16px;font-size:11px;color:#8793a1;">Daily report · Scheduled for 00:00 Europe/Berlin</div></td></tr></table></body></html>';
    }
}

trait LichtBlickDailyReportAccess
{
    protected function consumptionReportNow(): int {return time();}

    /** Preview only: no SMTP call and no scheduling or archive writes. */
    public function GetDailyConsumptionReport(string $date=''): string
    {
        $now=$this->consumptionReportNow();
        if ($date==='') [$date]=LichtBlickEconomics::previousDay($now);
        [$from,$to]=LichtBlickPriceStore::dates($date,$date,1);
        if ($to>$now) throw new InvalidArgumentException('Use a completed Berlin calendar day');
        $data=json_decode($this->GetTotalConsumption($date,$date,'daily'),true,64,JSON_THROW_ON_ERROR);
        return LichtBlickDailyReport::html(LichtBlickDailyReport::model($date,$data));
    }

    private function consumptionReportSMTP(): int
    {
        $id=$this->ReadPropertyInteger('ConsumptionReportSMTPID');
        if ($id<=1 || !IPS_InstanceExists($id) || (IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')!==LichtBlickDailyReport::SMTP_MODULE)
            throw new RuntimeException('Select an SMTP instance from the object tree');
        if (trim((string)IPS_GetProperty($id,'Recipient'))==='') throw new RuntimeException('Set the recipient in the selected SMTP instance');
        if (!function_exists('SMTP_SendMail')) throw new RuntimeException('Symcon SMTP API unavailable');
        return $id;
    }

    private function configureConsumptionReport(): void
    {
        $this->SetTimerInterval('ConsumptionReport',0);
        if (!$this->ReadPropertyBoolean('ConsumptionReportEnabled')) {
            $this->WriteAttributeString('ConsumptionReportNextDue','');
            $this->SetValue('ConsumptionReportStatus','Disabled · preview does not send email'); return;
        }
        try {
            $this->consumptionReportSMTP();
            if (IPS_GetKernelRunlevel()!==10103) return;
            $now=$this->consumptionReportNow();
            $due=(int)$this->ReadAttributeString('ConsumptionReportNextDue');
            if (!$due) {$due=LichtBlickDailyReport::nextMidnight($now); $this->WriteAttributeString('ConsumptionReportNextDue',(string)$due);}
            $this->SetTimerInterval('ConsumptionReport',max(1000,($due-$now)*1000));
            $this->SetValue('ConsumptionReportStatus','Scheduled · next Berlin midnight; recipient configured in selected SMTP instance');
        } catch (Throwable $e) {$this->SetValue('ConsumptionReportStatus','Not scheduled · '.$e->getMessage());}
    }

    /** Independent of the heating recommendation timer. One persisted attempt per day prevents repeated emails. */
    public function ConsumptionReportTick(): void
    {
        if (!IPS_SemaphoreEnter('LBEP.ConsumptionReport.'.$this->InstanceID,100)) return;
        try {
            $this->SetTimerInterval('ConsumptionReport',0);
            if (!$this->ReadPropertyBoolean('ConsumptionReportEnabled')) {$this->WriteAttributeString('ConsumptionReportNextDue',''); return;}
            $now=$this->consumptionReportNow();
            $due=(int)$this->ReadAttributeString('ConsumptionReportNextDue');
            if (!$due) {$this->configureConsumptionReport(); return;}
            if ($due>$now) return;
            // After an outage only the previous complete day is reported, without a backlog of emails.
            [$date]=LichtBlickEconomics::previousDay($now);
            if ($this->ReadAttributeString('ConsumptionReportLastAttemptDay')!==$date) {
                $this->WriteAttributeString('ConsumptionReportLastAttemptDay',$date);
                $html=$this->GetDailyConsumptionReport($date);
                $this->SetValue('ConsumptionReportHTML',$html);
                $smtp=$this->consumptionReportSMTP();
                $subject='Verbrauch & Kosten · '.$date.' · Daily consumption';
                if (!SMTP_SendMail($smtp,$subject,$html)) throw new RuntimeException('SMTP did not confirm sending; check the SMTP instance. No automatic repeat for this day.');
                $this->WriteAttributeString('ConsumptionReportLastSentDay',$date);
                $this->SetValue('ConsumptionReportStatus','Sent · '.$date.' · SMTP accepted report');
            }
        } catch (Throwable $e) {$this->SetValue('ConsumptionReportStatus','Report failed · '.$e->getMessage());}
        finally {
            if ($this->ReadPropertyBoolean('ConsumptionReportEnabled')) {
                $now=$this->consumptionReportNow(); $due=LichtBlickDailyReport::nextMidnight($now);
                $this->WriteAttributeString('ConsumptionReportNextDue',(string)$due);
                $this->SetTimerInterval('ConsumptionReport',max(1000,($due-$now)*1000));
            }
            IPS_SemaphoreLeave('LBEP.ConsumptionReport.'.$this->InstanceID);
        }
    }
}
