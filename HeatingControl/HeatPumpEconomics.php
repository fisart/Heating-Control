<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpMatrix.php';
trait HeatingControlHeatPumpEconomics
{
    private function createHeatPumpEconomics(): void
    {
        $this->RegisterPropertyFloat('HeatPumpGasEfficiencyPercent',100.0);
        $this->RegisterPropertyFloat('HeatPumpMatrixTemperatureToleranceC',3.0);
        $this->RegisterPropertyString('HeatPumpMeasuredMatrix','[]');
        $this->RegisterAttributeString('HeatPumpEconomicSource','2');
        $this->RegisterVariableString('HeatPumpEconomicsJSON','Measured heat-pump price decision (JSON)','~TextBox',151);
        foreach(['HeatPumpBreakEvenEuro'=>'Electricity break-even price (EUR/kWh)',
            'HeatPumpEntryEuro'=>'Heat-pump entry below (EUR/kWh)','HeatPumpExitEuro'=>'Heat-pump exit at (EUR/kWh)'] as $ident=>$name)$this->RegisterVariableFloat($ident,$name,'',152);
    }
    private function measuredMatrix(): array
    {
        $configured=json_decode($this->ReadPropertyString('HeatPumpMeasuredMatrix'),true,64,JSON_THROW_ON_ERROR);
        $rows=$configured?:HeatPumpMatrix::seed();
        $model=json_decode($this->ReadAttributeString('HeatPumpPerformanceModel')?:'{}',true,64,JSON_THROW_ON_ERROR);
        // Never allow the old circuit-meter model to overwrite the reviewed calibration.
        if(($model['schema']??0)===2)$rows=HeatPumpMatrix::merge($rows,$model['points']??[]);
        $rows=HeatPumpMatrix::validate($rows);
        return $rows;
    }
    private function measuredEconomicChoice(string &$reason): int
    {
        foreach(['HeatPumpBreakEvenEuro','HeatPumpEntryEuro','HeatPumpExitEuro'] as $id)$this->SetValue($id,0.0);
        try {
            $instance=$this->ReadPropertyInteger('ElectricityPriceInstanceID');
            if(!$this->priceRecommendationID())throw new RuntimeException('Select an updated LichtBlick instance');
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
            $point=HeatPumpMatrix::choose($this->measuredMatrix(),$outside,$incoming,$req['target'],
                $this->ReadPropertyFloat('HeatPumpSupplyAboveTargetC'),$this->ReadPropertyFloat('HeatPumpMinimumAirRiseC'),
                $this->intValue('FanDesiredSpeedID'),$this->ReadPropertyFloat('HeatPumpTestAirflowM3h'),$tolerance,$this->ReadPropertyInteger('HeatPumpTestExcludedFlapID'));
            if(!$point)throw new RuntimeException('No qualified point at current outside/intake temperature, fan and supply requirement; run a test at these conditions');
            $e=HeatPumpMatrix::economics($point,(float)$q['gasPriceCt'],$this->ReadPropertyFloat('HeatPumpGasEfficiencyPercent'),
                (float)$q['safetyMarginPercent'],(float)$q['hysteresisCt'],(float)$q['priceCt'],(int)$this->ReadAttributeString('HeatPumpEconomicSource'));
            $this->WriteAttributeString('HeatPumpEconomicSource',(string)$e['source']);
            foreach(['HeatPumpBreakEvenEuro'=>'breakEvenEuro','HeatPumpEntryEuro'=>'entryEuro','HeatPumpExitEuro'=>'exitEuro'] as $ident=>$key)$this->SetValue($ident,(float)$e[$key]);
            $reason=sprintf('Measured %d%%, estimated COP %.2f at %.1f°C outside / %.1f°C intake; break-even %.4f EUR/kWh, entry < %.4f, exit ≥ %.4f; current %.4f; %s',
                $point['powerPercent'],$point['estimatedSystemCOP'],$outside,$incoming,$e['breakEvenEuro'],$e['entryEuro'],$e['exitEuro'],$q['priceCt']/100,$e['source']===1?'heat pump':'gas');
            $snapshot=['schema'=>1,'updatedAt'=>$now,'validUntil'=>$q['validUntil'],'valid'=>true,'powerPercent'=>$point['powerPercent'],
                'outside'=>$outside,'incoming'=>$incoming,'target'=>$req['target'],'targetMinusIncoming'=>$req['target']-$incoming,'point'=>$point,'reason'=>$reason]+$e;
            $this->SetValue('HeatPumpEconomicsJSON',json_encode($snapshot,JSON_THROW_ON_ERROR));
            $this->SetValue('HeatPumpPerformanceStatus',$reason.'; estimated airflow; room demand still controls heating');
            return $e['source']===1?(int)$point['powerPercent']:0;
        } catch(Throwable $error) {
            $this->WriteAttributeString('HeatPumpEconomicSource','2');
            $reason='Automatic gas fallback: '.$error->getMessage();
            $this->SetValue('HeatPumpEconomicsJSON',json_encode(['schema'=>1,'updatedAt'=>$this->sourceClock(),'valid'=>false,'source'=>2,'reason'=>$reason],JSON_THROW_ON_ERROR));
            $this->SetValue('HeatPumpPerformanceStatus',$reason);return 0;
        }
    }
}
