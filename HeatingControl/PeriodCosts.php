<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpCostAnalysis.php';
require_once __DIR__.'/HeatPumpDecisionAudit.php';
require_once __DIR__.'/../LichtBlickElectricityPrice/PriceStore.php';
require_once __DIR__.'/../LichtBlickElectricityPrice/GasCosts.php';

trait HeatingControlPeriodCosts
{
    private function ensureCostArchiveLogging(): void
    {
        if(!function_exists('AC_SetLoggingStatus')||!function_exists('AC_GetLoggingStatus'))return;
        try {
            $archive=$this->ReadPropertyInteger('HeatPumpTestArchiveID');
            if(!IPS_InstanceExists($archive)||strtoupper(IPS_GetInstance($archive)['ModuleInfo']['ModuleID'])!==self::ARCHIVE_MODULE)return;
            $ids=[$this->ReadPropertyInteger('HeatPumpTestDedicatedCounterID')];
            foreach(['HeatPumpOnID','HeatPumpHeatModeID','GasPumpID','FanOnID','IncomingAirTempID','OutgoingAirTempID','OperatingModeID'] as $key)$ids[]=$this->id($key);
            foreach(['HeatPumpDecisionHistoryJSON','AutomaticSourceSelection','HeatPumpTestActive','FlapCalibrationActive'] as $ident)$ids[]=@IPS_GetObjectIDByIdent($ident,$this->InstanceID);
            foreach(array_unique($ids) as $id)if($id>1&&IPS_VariableExists($id)&&!AC_GetLoggingStatus($archive,$id)) {
                if(!AC_SetLoggingStatus($archive,$id,true))throw new RuntimeException('Cannot enable report logging for #'.$id);
                // Establish an explicit present-time baseline, never backdate
                // current values into a missing historical interval.
                if(function_exists('AC_AddLoggedValues')&&!AC_AddLoggedValues($archive,$id,[['TimeStamp'=>$this->sourceClock(),'Value'=>GetValue($id)]]))
                    throw new RuntimeException('Cannot seed report logging for #'.$id);
            }
        }catch(Throwable $e){$this->debug('Cost/backtest archive logging: '.$e->getMessage());}
    }

    public function GetHeatPumpCostAnalysis(string $from,string $through,string $excludedPeriods=''): string
    {
        return json_encode($this->heatPumpPeriodCosts($from,$through,$excludedPeriods),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    private function heatPumpPeriodCosts(string $from,string $through,string $excludedPeriods=''): array
    {
        $now=$this->sourceClock();[$a,$b]=HeatPumpCostAnalysis::dates($from,$through,$now);
        $archive=$this->ReadPropertyInteger('HeatPumpTestArchiveID');
        if(!function_exists('AC_GetLoggedValues')||!function_exists('AC_GetLoggingStatus')||!IPS_InstanceExists($archive)
            ||strtoupper(IPS_GetInstance($archive)['ModuleInfo']['ModuleID'])!==self::ARCHIVE_MODULE)
            throw new InvalidArgumentException('Select the existing test Archive Control for this report');
        $settings=['counterScale'=>$this->ReadPropertyFloat('HeatPumpTestDedicatedCounterScale'),
            'airflowM3h'=>2000.0,
            'fanCommand'=>$this->ReadPropertyInteger('HeatPumpTestFanSpeed'),
            'temperatureMaxAge'=>max(0,$this->ReadPropertyInteger('MaxSensorAgeSeconds')),
            'gasEfficiencyPercent'=>$this->ReadPropertyFloat('HeatPumpGasEfficiencyPercent'),'fallbackGasPriceCt'=>null,
            'exclusions'=>[]];
        foreach(['counterScale'=>[.000001,1],'gasEfficiencyPercent'=>[50,100]] as $k=>$limits)
            if(!is_finite((float)$settings[$k])||$settings[$k]<$limits[0]||$settings[$k]>$limits[1])throw new InvalidArgumentException('Invalid existing analysis setting: '.$k);
        $inputs=['counter'=>[$this->ReadPropertyInteger('HeatPumpTestDedicatedCounterID'),'counter'],
            'hpOn'=>[$this->id('HeatPumpOnID'),'state'],'heating'=>[$this->id('HeatPumpHeatModeID'),'state'],
            'gasOn'=>[$this->id('GasPumpID'),'state'],'fanOn'=>[$this->id('FanOnID'),'state'],
            'incoming'=>[$this->id('IncomingAirTempID'),'temperature'],
            'outgoing'=>[$this->id('OutgoingAirTempID'),'temperature']];
        $provider=$this->ReadPropertyInteger('ElectricityPriceInstanceID');$prices=[];$tariffs=[];$warnings=[];$recovery=null;
        $settings['exclusions']=$this->costExclusions($excludedPeriods,$a,$b,$warnings);
        foreach(['testActive'=>['HeatPumpTestActive','state'],'flapActive'=>['FlapCalibrationActive','state'],
            'decision'=>['HeatPumpDecisionHistoryJSON','json'],'automatic'=>['AutomaticSourceSelection','state']] as $key=>[$ident,$kind]) {
            $id=@IPS_GetObjectIDByIdent($ident,$this->InstanceID);
            if($key==='decision'&&($id<=1||!AC_GetLoggingStatus($archive,$id)))$id=@IPS_GetObjectIDByIdent('HeatPumpEconomicsJSON',$this->InstanceID);
            if($id>1&&IPS_VariableExists($id)&&AC_GetLoggingStatus($archive,$id))$inputs[$key]=[$id,$kind];
            else $warnings[]=$ident.': historical logging unavailable; '.($key==='decision'||$key==='automatic'?'past switching decisions cannot be fully verified.':'saved test summaries and manual exclusions are used where available.');
        }
        $inputs['selectedMode']=[$this->id('OperatingModeID'),'number'];
        $connected=$provider>1&&IPS_InstanceExists($provider)&&strtoupper(IPS_GetInstance($provider)['ModuleInfo']['ModuleID'])===self::PRICE_MODULE_ID;
        if(!$connected)$warnings[]='Select the existing LichtBlick instance for historical electricity prices and gas tariffs.';
        else {
            try {
                $fallback=IPS_GetProperty($provider,'GasPriceCt');
                if(is_numeric($fallback)&&is_finite((float)$fallback)&&$fallback>0&&$fallback<=100)$settings['fallbackGasPriceCt']=(float)$fallback;
                $tariffs=LichtBlickGasCosts::tariffs((string)IPS_GetProperty($provider,'GasHistoryTariffs'));
            } catch(Throwable $e){$warnings[]='Gas tariff history unavailable: '.$e->getMessage();$settings['fallbackGasPriceCt']=null;}
            try {
                if(!function_exists('LBEP_GetPriceHistory'))throw new RuntimeException('Update LichtBlick to provide GetPriceHistory');
                $historyDate=(string)IPS_GetProperty($provider,'PriceHistoryStartDate');
                [$historyStart]=LichtBlickPriceStore::dates($historyDate,$historyDate);
                if($b>max($a,$historyStart)) {
                    $raw=json_decode(LBEP_GetPriceHistory($provider,max($a,$historyStart),$b),true,32,JSON_THROW_ON_ERROR);
                    if(($raw['schema']??0)!==1||!is_array($raw['intervals']??null)||count($raw['intervals'])>4000)throw new RuntimeException('Invalid historical price response');
                    $known=[];foreach($raw['intervals'] as $p)if(is_numeric($p['price']??null))$known[$p['start']]=true;
                    $missing=false;for($at=intdiv(max($a,$historyStart),900)*900;$at<$b;$at+=900)if(!isset($known[$at])){$missing=true;break;}
                    if($missing) {
                        if(function_exists('LBEP_RecoverPriceHistory'))try {
                            $repair=json_decode(LBEP_RecoverPriceHistory($provider,max($a,$historyStart),$b),true,32,JSON_THROW_ON_ERROR);
                            if(($repair['schema']??0)!==1||!is_array($repair['intervals']??null)||count($repair['intervals'])>4000)throw new RuntimeException('Invalid price recovery response');
                            $raw=$repair;$recovery=$repair['recovery']??null;
                        }catch(Throwable $e){$warnings[]='Price download recovery failed: '.$e->getMessage();}
                        else $warnings[]='Update LichtBlick to enable supplier-price recovery; last known historical prices are used where available.';
                    }
                    $prices=HeatPumpCostAnalysis::prices($raw['intervals'],$a,$b,$raw['previousInterval']??null);
                    if($raw['previousIntervalError']??null)$warnings[]='Pre-period price seed unavailable: '.$raw['previousIntervalError'];
                }
            } catch(Throwable $e){$warnings[]='Electricity price history unavailable: '.$e->getMessage();}
        }
        $reader=new HeatPumpCostArchive($archive,$now);$days=[];$totals=HeatPumpCostAnalysis::emptyTotals();$issues=[];$readFailures=[];$decisions=[];
        $zone=new DateTimeZone('Europe/Berlin');$day=(new DateTimeImmutable('@'.$a))->setTimezone($zone);
        while($day->getTimestamp()<$b) {
            $next=$day->modify('+1 day');$start=$day->getTimestamp();$end=min($b,$next->getTimestamp());$series=[];
            foreach($inputs as $key=>[$id,$kind]) {
                if($key==='decision')continue; // Read only snapshots needed at selection/fan-start events, not a day of large JSON.
                try {$series[$key]=$reader->read($id,$kind,max(1,$start-($key==='counter'?180:7200)),$end+($key==='counter'?180:0));}
                catch(Throwable $e){$series[$key]=[];$message=$key.' (#'.$id.', '.$day->format('Y-m-d').'): '.$e->getMessage();$warnings[]=$message;$readFailures[]=['date'=>$day->format('Y-m-d'),'channel'=>$key,'variableID'=>$id,'from'=>$start,'to'=>$end,'reason'=>$e->getMessage()];}
            }
            if(isset($inputs['decision'])) {
                $eventTimes=[$start=>true];
                foreach($series['selectedMode']??[] as $stamp=>$value)if($stamp>$start&&$stamp<$end)$eventTimes[$stamp]=true;
                foreach($series['fanOn']??[] as $stamp=>$value)if($value===true&&$stamp>$start&&$stamp<$end)$eventTimes[$stamp]=true;
                ksort($eventTimes,SORT_NUMERIC);$series['decision']=[];
                if(count($eventTimes)>2000)$warnings[]=$day->format('Y-m-d').': decision audit limited to 2000 selection events.';
                foreach(array_slice(array_keys($eventTimes),0,2000) as $stamp)try {
                    foreach($reader->read($inputs['decision'][0],'json',$stamp,$stamp) as $t=>$v)$series['decision'][$t]=$v;
                }catch(Throwable $e){$warnings[]='Decision snapshot #'.$inputs['decision'][0].' at '.$stamp.': '.$e->getMessage();}
                ksort($series['decision'],SORT_NUMERIC);
            }
            $result=HeatPumpCostAnalysis::day($start,$end,$series,$prices,$tariffs,$settings);
            if($result['issueIntervalsTruncated'])$warnings[]=$day->format('Y-m-d').': detailed issue list reached its 2000-row limit; aggregate issue durations remain complete.';
            foreach(HeatPumpDecisionAudit::review($start,$end,$series,$settings['exclusions'],
                $this->ReadPropertyInteger('HeatPumpModeValue'),$this->ReadPropertyInteger('GasModeValue')) as $decision)$decisions[]=$decision;
            $result['date']=$day->format('Y-m-d');$days[]=$result;
            foreach($totals as $key=>$v)$totals[$key]+=$result['totals'][$key];
            foreach($result['issues'] as $why=>$seconds)$issues[$why]=($issues[$why]??0)+$seconds;
            $day=$next;
        }
        if($totals['assumedGasPriceSeconds'])$warnings[]='For dates without a stored gas tariff, the currently configured gas price is used as an explicit estimate.';
        if($totals['unverifiedPriceSeconds'])$warnings[]='Some historical electricity prices are unverified; those costs are provisional.';
        $gasPrices=[];foreach($tariffs as $rate)if($rate['start']<$b&&$rate['end']>$a)$gasPrices[]=$rate['unitGrossCt'];
        if($totals['assumedGasPriceSeconds']&&$settings['fallbackGasPriceCt']!==null)$gasPrices[]=$settings['fallbackGasPriceCt'];
        if($totals['carriedPriceSeconds'])$warnings[]='Missing supplier prices use the last preceding known price and are marked as estimates; no carried prices are written as original quotes.';
        if(count($reader->duplicates)>=2000)$warnings[]='Duplicate timestamp diagnostics reached the 2000-entry limit; last-entry resolution still applies to all records.';
        if($recovery&&($recovery['missingAfter']??0)>0)$warnings[]='Supplier download could not recover all older intervals. '.($recovery['error']??$recovery['note']??'');
        return ['schema'=>1,'calculationVersion'=>3,'from'=>$a,'to'=>$b,'fromDate'=>$from,'throughDate'=>$through,'timezone'=>'Europe/Berlin',
            'updatedAt'=>$now,'summary'=>HeatPumpCostAnalysis::totals($totals),'days'=>$days,'issues'=>$issues,
            'excludedPeriods'=>$excludedPeriods,'exclusions'=>$settings['exclusions'],'priceRecovery'=>$recovery,
            'inputVariables'=>array_map(static fn($input)=>$input[0],$inputs),
            'archiveDuplicates'=>array_values($reader->duplicates),'archiveReadFailures'=>$readFailures,'decisions'=>$decisions,
            'warnings'=>array_values(array_unique($warnings)),
            'assumptions'=>$settings+['meterCounterID'=>$inputs['counter'][0],'archiveID'=>$archive,
                'gasPriceMinCt'=>$gasPrices?min($gasPrices):null,'gasPriceMaxCt'=>$gasPrices?max($gasPrices):null,
                'fanCostIncluded'=>false,'fixedChargesIncluded'=>false,'defrostElectricityIncluded'=>true,'baselineSubtracted'=>false,'fanOnOnly'=>true,'fanSpeedIgnored'=>true],
            'scope'=>'Only fan-ON periods outside known tests are analysed. Airflow is fixed at 2000 m3/h; fan-speed records are ignored. Dedicated heat-pump meter import is used without a household baseline. Fan-OFF periods, fan electricity and fixed charges are excluded. Duplicate timestamps use the last archive-response entry. Supplier prices are recovered where available, otherwise the preceding historical price is carried forward as an estimate. Meter shutdown gaps are not allocated across time; their whole measured counter increases are exposed separately. Cooling during enabled heating remains signed. Positive heat-pump run-on ends at zero air rise. Source transitions can contain residual heat from both exchangers; exact separation is not claimed.'];
    }

    private function costExclusions(string $text,int $from,int $to,array &$warnings): array
    {
        if(strlen($text)>4096)throw new InvalidArgumentException('Too many excluded test periods');
        $out=[];$zone=new DateTimeZone('Europe/Berlin');$lines=preg_split('/\r?\n/',trim($text));
        if(count($lines)>16)throw new InvalidArgumentException('Use at most 16 excluded test periods');
        foreach($lines as $line)if(trim($line)!=='') {
            $pair=explode('/',trim($line));if(count($pair)!==2)throw new InvalidArgumentException('Excluded periods: YYYY-MM-DD HH:MM / YYYY-MM-DD HH:MM (Berlin)');
            $stamps=[];foreach($pair as $value){$value=trim($value);$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value,$zone);
                if(!$date||$date->format('Y-m-d H:i')!==$value)throw new InvalidArgumentException('Invalid excluded test date/time');$stamps[]=$date->getTimestamp();}
            if($stamps[1]<=$stamps[0])throw new InvalidArgumentException('Excluded period must end after its start');
            $out[]=['from'=>$stamps[0],'to'=>$stamps[1],'source'=>'Manual test exclusion'];
        }
        $files=function_exists('IPS_GetKernelDir')?(glob($this->testDirectory().'/*.summary.json')?:[]):[];
        if(count($files)>512){$warnings[]='More than 512 saved test summaries; enter test exclusions manually.';$files=[];}
        foreach($files as $file) {
            if(filesize($file)>2097152){$warnings[]='Test summary too large; enter its exclusion manually.';continue;}
            try{$r=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
                if(is_int($r['startedAt']??null)&&is_int($r['finishedAt']??null)&&$r['finishedAt']>$r['startedAt']&&$r['startedAt']<$to&&$r['finishedAt']>$from)
                    $out[]=['from'=>$r['startedAt'],'to'=>$r['finishedAt'],'source'=>'Saved heat-pump test '.$r['runID']];
            }catch(Throwable $e){$warnings[]='Unreadable test summary: '.basename($file);}
        }
        $last=json_decode($this->ReadAttributeString('HeatPumpTestLastReport')?:'{}',true);
        if(is_int($last['startedAt']??null)&&is_int($last['finishedAt']??null)&&$last['finishedAt']>$last['startedAt']&&$last['startedAt']<$to&&$last['finishedAt']>$from)
            $out[]=['from'=>$last['startedAt'],'to'=>$last['finishedAt'],'source'=>'Latest saved heat-pump test'];
        foreach(['HeatPumpTestState','FlapCalibrationState'] as $attribute) {
            $r=json_decode($this->ReadAttributeString($attribute)?:'{}',true);
            if(is_int($r['startedAt']??null)&&$r['startedAt']<$to)$out[]=['from'=>$r['startedAt'],'to'=>$to,'source'=>'Active '.$attribute];
        }
        return $out;
    }

    private function heatPumpPeriodCostCSV(array $report): string
    {
        $stream=fopen('php://temp','r+');
        if($stream===false)throw new RuntimeException('Cannot prepare cost report download');
        try {
            fputcsv($stream,['Date (Europe/Berlin)','Matched heat-pump electricity kWh','Matched net delivered heat kWh (estimated)','Matched heat-pump cost EUR','Equivalent gas cost EUR (estimated)','Saving EUR','Saving percent','Matched eligible fan-on time percent','Known eligible fan-on heat-pump cost EUR','Eligible fan-on cost complete','Provisional tariff','Data remarks','Airflow assumption m3/h','Fan command (ignored)','Gas efficiency percent','Period gas rate minimum ct/kWh','Period gas rate maximum ct/kWh','Positive enabled heat kWh (estimated)','Positive run-on heat kWh (estimated)','Enabled cooling heat kWh (deduction)','Electricity cost while enabled EUR','Electricity cost while off EUR','Electricity cost with unknown on-state EUR','Calculation version','Eligible fan-on minutes','Excluded fan-off minutes','Unknown fan minutes','Excluded test minutes','Carried price minutes','Issue intervals JSON (UTC timestamps)','Whole meter gaps JSON (not allocated)','Archive duplicate resolution JSON','Archive read failures JSON','Manual excluded test periods'],',','"','');
            foreach($report['days'] as $day) {
                $t=$day['totals'];$remarks=implode('; ',array_keys($day['issues']));
                $failures=array_values(array_filter($report['archiveReadFailures'],static fn($r)=>$r['date']===$day['date']));
                foreach($failures as $r)$remarks.='; '.$r['channel'].' (#'.$r['variableID'].'): '.$r['reason'];
                fputcsv($stream,[$day['date'],$t['matchedSeconds']?$t['matchedElectricityKWh']:null,
                    $t['matchedSeconds']?$t['matchedNetHeatKWh']:null,$t['comparisonHeatPumpCostEuro'],
                    $t['equivalentGasCostEuro'],$t['savingEuro'],$t['savingPercent'],$t['comparisonCoveragePercent'],
                    $t['costSeconds']?$t['knownHeatPumpCostEuro']:null,$t['heatPumpCostEuro']!==null?'yes':'no',$t['provisional']?'yes':'no',$remarks,$report['assumptions']['airflowM3h'],$report['assumptions']['fanCommand'],$report['assumptions']['gasEfficiencyPercent'],$report['assumptions']['gasPriceMinCt'],$report['assumptions']['gasPriceMaxCt'],
                    $t['matchedSeconds']?$t['matchedHeatingHeatKWh']:null,$t['matchedSeconds']?$t['matchedRunOnHeatKWh']:null,$t['matchedSeconds']?$t['matchedCoolingHeatKWh']:null,
                    $t['matchedSeconds']?$t['matchedEnabledCostEuro']:null,$t['matchedSeconds']?$t['matchedOffCostEuro']:null,$t['matchedSeconds']?$t['matchedUnknownStateCostEuro']:null,$report['calculationVersion'],
                    $t['eligibleSeconds']/60,$t['fanOffSeconds']/60,$t['unknownFanSeconds']/60,$t['testSeconds']/60,$t['carriedPriceSeconds']/60,
                    json_encode($day['issueIntervals']),json_encode($day['meterGaps']),json_encode(array_values(array_filter($report['archiveDuplicates'],static fn($r)=>$r['timestamp']>=$day['from']-7200&&$r['timestamp']<$day['to']))),
                    json_encode($failures),$report['excludedPeriods']],',','"','');
            }
            rewind($stream);return stream_get_contents($stream);
        } finally {fclose($stream);}
    }
}
