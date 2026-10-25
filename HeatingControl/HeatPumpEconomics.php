<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpMatrix.php';
require_once __DIR__.'/HeatPumpSurface.php';
trait HeatingControlHeatPumpEconomics
{
    private function createHeatPumpEconomics(): void
    {
        $this->RegisterPropertyFloat('HeatPumpGasEfficiencyPercent',100.0);
        $this->RegisterPropertyString('HeatPumpCOPMethod','measured');
        $this->RegisterPropertyFloat('HeatPumpMatrixTemperatureToleranceC',3.0);
        $this->RegisterPropertyString('HeatPumpMeasuredMatrix','[]');
        $this->RegisterAttributeString('HeatPumpEconomicSource','2');
        $this->RegisterVariableString('HeatPumpEconomicsJSON','Measured heat-pump price decision (JSON)','~TextBox',151);
        $this->RegisterVariableString('HeatPumpDecisionHistoryJSON','Compact switchover decision history (JSON)','~TextBox',153);
        foreach(['HeatPumpBreakEvenEuro'=>'Electricity break-even price (EUR/kWh)',
            'HeatPumpEntryEuro'=>'Heat-pump entry below (EUR/kWh)','HeatPumpExitEuro'=>'Heat-pump exit at (EUR/kWh)'] as $ident=>$name)$this->RegisterVariableFloat($ident,$name,'',152);
    }
    private function recordEconomicDecision(array $snapshot): void
    {
        $record=array_intersect_key($snapshot,array_flip(['schema','updatedAt','validUntil','valid','source','previousEconomicSource',
            'currentPriceCt','entryCt','exitCt','breakEvenCt','safetyMarginPercent','hysteresisCt','powerPercent','outside','incoming','target','reason']));
        if(isset($snapshot['point']['estimatedSystemCOP']))$record['point']=['estimatedSystemCOP'=>$snapshot['point']['estimatedSystemCOP']];
        $previous=json_decode((string)$this->GetValue('HeatPumpDecisionHistoryJSON'),true)??[];$compare=$record;
        unset($previous['updatedAt'],$compare['updatedAt']);
        if($previous!=$compare)$this->SetValue('HeatPumpDecisionHistoryJSON',json_encode($record,JSON_THROW_ON_ERROR));
    }
    public function SetHeatPumpCOPMethod(string $method): void
    {
        if(!in_array($method,['measured','surface'],true))throw new InvalidArgumentException('COP method must be measured or surface');
        $lock='HeatingControl.'.$this->InstanceID;
        if(!IPS_SemaphoreEnter($lock,1000))throw new RuntimeException('Heating controller busy');
        try {
            if($this->testState())throw new InvalidArgumentException('Cancel the active or paused test before changing COP method');
            if($method==='surface'){HeatPumpSurface::observations();}
            IPS_SetProperty($this->InstanceID,'HeatPumpCOPMethod',$method);
            $this->WriteAttributeString('HeatPumpEconomicSource','2');
        } finally {IPS_SemaphoreLeave($lock);}
        IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('HeatPumpCOPMethod','value',$method);
        $this->ProcessHeating();
    }
    private function copMethodView(): array
    {
        $m=HeatPumpSurface::definition();
        return ['selected'=>$this->ReadPropertyString('HeatPumpCOPMethod'),'measuredToleranceC'=>$this->ReadPropertyFloat('HeatPumpMatrixTemperatureToleranceC'),
            'surfaceVersion'=>$m['version'],'outsideMinC'=>$m['domain']['outside_min_C'],'outsideMaxC'=>$m['domain']['outside_max_C'],
            'powerStepPercent'=>1,'electricalMethod'=>$m['reference_basis']['electrical_method'],
            'validationRMSECOP'=>$m['metrics']['leave_one_run_out_rmse_COP'],'acceptedPointCount'=>$m['metrics']['accepted_points'],
            'dedicatedPointCount'=>$m['metrics']['dedicated_meter_points'],'legacyPointCount'=>$m['metrics']['legacy_house_meter_points'],
            'note'=>'Expanded 39-point surface including 16 dedicated-meter measurements and 23 household-counter points. All 30 original rows are preserved; six runs supply calibration or thermal-only capacity evidence. Surface mode qualifies air rise against target plus supply margin minus intake; absolute intake matching is retained only in measured mode.'];
    }
    private function measuredMatrix(): array
    {
        $configured=json_decode($this->ReadPropertyString('HeatPumpMeasuredMatrix'),true,64,JSON_THROW_ON_ERROR);
        $rows=$configured?:HeatPumpMatrix::seed();
        $model=json_decode($this->ReadAttributeString('HeatPumpPerformanceModel')?:'{}',true,64,JSON_THROW_ON_ERROR);
        // Only schema-2 points with explicitly validated meter provenance may extend calibration.
        if(($model['schema']??0)===2)$rows=HeatPumpMatrix::merge($rows,$model['points']??[]);
        // Recover only exact reviewed measurements from the last report.
        // Its original report and raw files are never changed.
        $report=json_decode($this->ReadAttributeString('HeatPumpTestLastReport')?:'{}',true,64,JSON_THROW_ON_ERROR);
        foreach($report['points']??[] as $point) {
            $reviewed=HeatPumpMatrix::applyPointReview($point);
            $partialReviewed=isset($reviewed['reviewedPartialRunReason'])&&($report['schema']??0)===1
                &&($report['complete']??null)===false&&($report['reason']??'')===$reviewed['reviewedPartialRunReason']
                &&($report['runID']??'')===($point['runID']??null);
            if(isset($reviewed['reviewNote'])&&(($report['modelQualified']??false)===true||$partialReviewed))
                $rows=HeatPumpMatrix::merge($rows,[$reviewed]);
        }
        $rows=HeatPumpMatrix::validate(HeatPumpMatrix::applySupersessions($rows));
        return $rows;
    }
    private function measuredEconomicChoice(string &$reason): int
    {
        foreach(['HeatPumpBreakEvenEuro','HeatPumpEntryEuro','HeatPumpExitEuro'] as $id)$this->SetValue($id,0.0);
        $conditions=['copMethod'=>$this->ReadPropertyString('HeatPumpCOPMethod')];
        try {
            $copMethod=$this->ReadPropertyString('HeatPumpCOPMethod');
            if(!in_array($copMethod,['measured','surface'],true))throw new RuntimeException('Invalid COP method; select measured or surface');
            $instance=$this->ReadPropertyInteger('ElectricityPriceInstanceID');
            if(!$this->priceQuoteID())throw new RuntimeException('Select an updated LichtBlick instance');
            $id=IPS_GetObjectIDByIdent('PriceQuoteJSON',$instance);
            if(!IPS_VariableExists($id)||IPS_GetVariable($id)['VariableType']!==3)throw new RuntimeException('Update LichtBlick for verified price snapshots');
            $q=json_decode(GetValue($id),true,16,JSON_THROW_ON_ERROR);$now=$this->sourceClock();
            if(($q['schema']??0)!==1||($q['valid']??false)!==true||($q['enabled']??false)!==true
                ||!is_int($q['updatedAt']??null)||$q['updatedAt']>$now+30||$now-$q['updatedAt']>180
                ||!is_int($q['validUntil']??null)||$q['validUntil']<=$now)throw new RuntimeException('Verified current electricity quote unavailable or expired');
            foreach(['priceCt','gasPriceCt','safetyMarginPercent','hysteresisCt'] as $k)if(!is_numeric($q[$k]??null))throw new RuntimeException('Missing economic quote input');
            $req=$this->heatPumpRequirement($this->rooms());
            $outside=$this->temperature($this->id('OutsideTempID'));$incoming=$this->temperature($this->id('IncomingAirTempID'));
            $tolerance=$this->ReadPropertyFloat('HeatPumpMatrixTemperatureToleranceC');
            if($tolerance<0||$tolerance>3)throw new RuntimeException('Matrix temperature tolerance must be 0–3°C');
            foreach(['HeatPumpSupplyAboveTargetC','HeatPumpMinimumAirRiseC'] as $key){$value=$this->ReadPropertyFloat($key);if(!is_finite($value)||$value<1||$value>20)throw new RuntimeException('Air supply requirements must be 1–20°C');}
            $fan=$this->intValue('FanDesiredSpeedID');$airflow=$this->ReadPropertyFloat('HeatPumpTestAirflowM3h');
            $margin=$this->ReadPropertyFloat('HeatPumpSupplyAboveTargetC');$minRise=$this->ReadPropertyFloat('HeatPumpMinimumAirRiseC');
            $flap=$this->ReadPropertyInteger('HeatPumpTestExcludedFlapID');$matrix=$copMethod==='measured'?$this->measuredMatrix():[];
            $conditions=['copMethod'=>$copMethod,'outside'=>$outside,'incoming'=>$incoming,'target'=>$req['target'],'targetMinusIncoming'=>$req['target']-$incoming,
                'fanSpeed'=>$fan,'airflowM3h'=>$airflow,'temperatureToleranceC'=>$tolerance,'requiredSupplyC'=>$req['target']+$margin,
                'requiredAirRiseC'=>max($minRise,$req['target']+$margin-$incoming),'minimumAirRiseC'=>$minRise,'gasEfficiencyPercent'=>$this->ReadPropertyFloat('HeatPumpGasEfficiencyPercent')];
            if($copMethod==='surface') {
                $point=HeatPumpSurface::choose($outside,$incoming,$req['target'],$margin,$minRise,$fan,$airflow,$tolerance,$flap);
                $conditions['surfaceVersion']=HeatPumpSurface::definition()['version'];
                if(!$point)throw new RuntimeException('No qualified surface candidate: '.HeatPumpSurface::unavailableReason($outside,$incoming,$req['target'],$margin,$fan,$airflow,$flap));
            } else {
                $point=HeatPumpMatrix::choose($matrix,$outside,$incoming,$req['target'],$margin,$minRise,$fan,$airflow,$tolerance,$flap);
                if(!$point)throw new RuntimeException('No qualified point: '.HeatPumpMatrix::unavailableReason($matrix,$outside,$incoming,$req['target'],$margin,$minRise,$fan,$airflow,$tolerance,$flap).'. Repeat calibration at these conditions; heat-pump prices cannot be extrapolated from an unsupported test point.');
            }
            $previousEconomicSource=(int)$this->ReadAttributeString('HeatPumpEconomicSource');
            $e=HeatPumpMatrix::economics($point,(float)$q['gasPriceCt'],$this->ReadPropertyFloat('HeatPumpGasEfficiencyPercent'),
                (float)$q['safetyMarginPercent'],(float)$q['hysteresisCt'],(float)$q['priceCt'],$previousEconomicSource);
            $this->WriteAttributeString('HeatPumpEconomicSource',(string)$e['source']);
            foreach(['HeatPumpBreakEvenEuro'=>'breakEvenEuro','HeatPumpEntryEuro'=>'entryEuro','HeatPumpExitEuro'=>'exitEuro'] as $ident=>$key)$this->SetValue($ident,(float)$e[$key]);
            $reason=sprintf(($copMethod==='surface'?'COP surface (modeled) ':'Measured ').'%d%%, estimated COP %.2f at %.1f°C outside / %.1f°C intake; break-even %.2f ct/kWh, entry < %.2f ct/kWh, exit ≥ %.2f ct/kWh; current %.2f ct/kWh; %s',
                $point['powerPercent'],$point['estimatedSystemCOP'],$outside,$incoming,$e['breakEvenCt'],$e['entryCt'],$e['exitCt'],$q['priceCt'],$e['source']===1?'heat pump':'gas');
            $snapshot=['copMethod'=>$copMethod,'schema'=>1,'previousEconomicSource'=>$previousEconomicSource,'updatedAt'=>$now,'validUntil'=>$q['validUntil'],'valid'=>true,'powerPercent'=>$point['powerPercent'],
                'outside'=>$outside,'incoming'=>$incoming,'target'=>$req['target'],'targetMinusIncoming'=>$req['target']-$incoming,'point'=>$point,'reason'=>$reason]+$e+$conditions;
            $this->SetValue('HeatPumpEconomicsJSON',json_encode($snapshot,JSON_THROW_ON_ERROR));
            $this->recordEconomicDecision($snapshot);
            $this->SetValue('HeatPumpPerformanceStatus',$reason.'; estimated airflow; room demand still controls heating');
            return $e['source']===1?(int)$point['powerPercent']:0;
        } catch(Throwable $error) {
            $this->WriteAttributeString('HeatPumpEconomicSource','2');
            $reason='Automatic gas fallback: '.$error->getMessage();
            $snapshot=['schema'=>1,'updatedAt'=>$this->sourceClock(),'valid'=>false,'source'=>2,'reason'=>$reason]+$conditions;
            $this->SetValue('HeatPumpEconomicsJSON',json_encode($snapshot,JSON_THROW_ON_ERROR));
            $this->recordEconomicDecision($snapshot);
            $this->SetValue('HeatPumpPerformanceStatus',$reason);return 0;
        }
    }
}

