<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpCostAnalysis.php';
require_once __DIR__.'/../LichtBlickElectricityPrice/PriceStore.php';
require_once __DIR__.'/../LichtBlickElectricityPrice/GasCosts.php';

trait HeatingControlPeriodCosts
{
    public function GetHeatPumpCostAnalysis(string $from,string $through): string
    {
        return json_encode($this->heatPumpPeriodCosts($from,$through),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    private function heatPumpPeriodCosts(string $from,string $through): array
    {
        $now=$this->sourceClock();[$a,$b]=HeatPumpCostAnalysis::dates($from,$through,$now);
        $archive=$this->ReadPropertyInteger('HeatPumpTestArchiveID');
        if(!function_exists('AC_GetLoggedValues')||!function_exists('AC_GetLoggingStatus')||!IPS_InstanceExists($archive)
            ||strtoupper(IPS_GetInstance($archive)['ModuleInfo']['ModuleID'])!==self::ARCHIVE_MODULE)
            throw new InvalidArgumentException('Select the existing test Archive Control for this report');
        $settings=['counterScale'=>$this->ReadPropertyFloat('HeatPumpTestDedicatedCounterScale'),
            'airflowM3h'=>$this->ReadPropertyFloat('HeatPumpTestAirflowM3h'),
            'fanCommand'=>$this->ReadPropertyInteger('HeatPumpTestFanSpeed'),
            'temperatureMaxAge'=>max(0,$this->ReadPropertyInteger('MaxSensorAgeSeconds')),
            'gasEfficiencyPercent'=>$this->ReadPropertyFloat('HeatPumpGasEfficiencyPercent'),'fallbackGasPriceCt'=>null];
        foreach(['counterScale'=>[.000001,1],'airflowM3h'=>[100,10000],'fanCommand'=>[20,100],'gasEfficiencyPercent'=>[50,100]] as $k=>$limits)
            if(!is_finite((float)$settings[$k])||$settings[$k]<$limits[0]||$settings[$k]>$limits[1])throw new InvalidArgumentException('Invalid existing analysis setting: '.$k);
        $inputs=['counter'=>[$this->ReadPropertyInteger('HeatPumpTestDedicatedCounterID'),'counter'],
            'hpOn'=>[$this->id('HeatPumpOnID'),'state'],'heating'=>[$this->id('HeatPumpHeatModeID'),'state'],
            'gasOn'=>[$this->id('GasPumpID'),'state'],'fanOn'=>[$this->id('FanOnID'),'state'],
            'fanSpeed'=>[$this->id('FanSpeedID'),'fan'],'incoming'=>[$this->id('IncomingAirTempID'),'temperature'],
            'outgoing'=>[$this->id('OutgoingAirTempID'),'temperature']];
        $provider=$this->ReadPropertyInteger('ElectricityPriceInstanceID');$prices=[];$tariffs=[];$warnings=[];
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
                    $map=[];
                    foreach($raw['intervals'] as $p) {
                        if(!is_int($p['start']??null)||($p['start']%900)!==0||($p['end']??0)!==$p['start']+900
                            ||(!is_null($p['price']??null)&&(!is_numeric($p['price'])||!is_finite((float)$p['price'])||abs((float)$p['price'])>1000)))
                            throw new RuntimeException('Invalid historical electricity price interval');
                        if(isset($map[$p['start']]))throw new RuntimeException('Duplicate historical electricity price interval');
                        if($p['start']<$b&&$p['end']>$a)$map[$p['start']]=$p;
                    }
                    ksort($map,SORT_NUMERIC);$prices=array_values($map);
                }
            } catch(Throwable $e){$warnings[]='Electricity price history unavailable: '.$e->getMessage();}
        }
        $reader=new HeatPumpCostArchive($archive,$now);$days=[];$totals=HeatPumpCostAnalysis::emptyTotals();$issues=[];
        $zone=new DateTimeZone('Europe/Berlin');$day=(new DateTimeImmutable('@'.$a))->setTimezone($zone);
        while($day->getTimestamp()<$b) {
            $next=$day->modify('+1 day');$start=$day->getTimestamp();$end=min($b,$next->getTimestamp());$series=[];
            foreach($inputs as $key=>[$id,$kind]) {
                try {$series[$key]=$reader->read($id,$kind,max(1,$start-($key==='counter'?180:7200)),$end+($key==='counter'?180:0));}
                catch(Throwable $e){$series[$key]=[];$warnings[]=$key.': '.$e->getMessage();}
            }
            $result=HeatPumpCostAnalysis::day($start,$end,$series,$prices,$tariffs,$settings);
            $result['date']=$day->format('Y-m-d');$days[]=$result;
            foreach($totals as $key=>$v)$totals[$key]+=$result['totals'][$key];
            foreach($result['issues'] as $why=>$seconds)$issues[$why]=($issues[$why]??0)+$seconds;
            $day=$next;
        }
        if($totals['assumedGasPriceSeconds'])$warnings[]='For dates without a stored gas tariff, the currently configured gas price is used as an explicit estimate.';
        if($totals['unverifiedPriceSeconds'])$warnings[]='Some historical electricity prices are unverified; those costs are provisional.';
        $gasPrices=[];foreach($tariffs as $rate)if($rate['start']<$b&&$rate['end']>$a)$gasPrices[]=$rate['unitGrossCt'];
        if($totals['assumedGasPriceSeconds']&&$settings['fallbackGasPriceCt']!==null)$gasPrices[]=$settings['fallbackGasPriceCt'];
        return ['schema'=>1,'from'=>$a,'to'=>$b,'fromDate'=>$from,'throughDate'=>$through,'timezone'=>'Europe/Berlin',
            'updatedAt'=>$now,'summary'=>HeatPumpCostAnalysis::totals($totals),'days'=>$days,'issues'=>$issues,
            'warnings'=>array_values(array_unique($warnings)),
            'assumptions'=>$settings+['meterCounterID'=>$inputs['counter'][0],'archiveID'=>$archive,
                'gasPriceMinCt'=>$gasPrices?min($gasPrices):null,'gasPriceMaxCt'=>$gasPrices?max($gasPrices):null,
                'fanCostIncluded'=>false,'fixedChargesIncluded'=>false,'defrostElectricityIncluded'=>true,'baselineSubtracted'=>false],
            'scope'=>'Dedicated heat-pump circuit electricity, including startup, defrost and standby. Fan electricity and fixed charges are excluded. Gas is estimated for the same net delivered air heat, only over intervals with matching data. Signed air-temperature differences retain cooling during defrost. Identifiable heat-pump fan run-on is included; gas heat and gas run-on are excluded. Airflow and gas efficiency use the existing configured assumptions. Unchanged archived temperature/state values are held; missing history is never replaced by current live values.'];
    }

    private function heatPumpPeriodCostCSV(array $report): string
    {
        $stream=fopen('php://temp','r+');
        if($stream===false)throw new RuntimeException('Cannot prepare cost report download');
        try {
            fputcsv($stream,['Date (Europe/Berlin)','Matched heat-pump electricity kWh','Matched net delivered heat kWh (estimated)','Matched heat-pump cost EUR','Equivalent gas cost EUR (estimated)','Saving EUR','Saving percent','Matched time percent','Known full-period heat-pump cost EUR','Full-period cost complete','Provisional tariff','Data remarks','Airflow assumption m3/h','Fan command','Gas efficiency percent','Period gas rate minimum ct/kWh','Period gas rate maximum ct/kWh'],',','"','');
            foreach($report['days'] as $day) {
                $t=$day['totals'];$remarks=implode('; ',array_keys($day['issues']));
                fputcsv($stream,[$day['date'],$t['matchedSeconds']?$t['matchedElectricityKWh']:null,
                    $t['matchedSeconds']?$t['matchedNetHeatKWh']:null,$t['comparisonHeatPumpCostEuro'],
                    $t['equivalentGasCostEuro'],$t['savingEuro'],$t['savingPercent'],$t['comparisonCoveragePercent'],
                    $t['costSeconds']?$t['knownHeatPumpCostEuro']:null,$t['heatPumpCostEuro']!==null?'yes':'no',$t['provisional']?'yes':'no',$remarks,$report['assumptions']['airflowM3h'],$report['assumptions']['fanCommand'],$report['assumptions']['gasEfficiencyPercent'],$report['assumptions']['gasPriceMinCt'],$report['assumptions']['gasPriceMaxCt']],',','"','');
            }
            rewind($stream);return stream_get_contents($stream);
        } finally {fclose($stream);}
    }
}
