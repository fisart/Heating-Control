<?php
declare(strict_types=1);

/** Dedicated circuit energy is never reduced by a household/standby baseline. */
trait HeatingControlHeatPumpMeter
{
    private function testDedicatedMeter(array $s): bool
    {
        // Persisted pre-upgrade tests keep their original measurement basis.
        return ($s['settings']['HeatPumpTestMeterSource']??'house')==='dedicated';
    }
    private function testElectricalMethod(array $s): string
    {
        return $this->testDedicatedMeter($s)?'dedicated-heatpump-import-counter':'house-counter-minus-pretest-baseline';
    }
    private function testBaselineSeconds(array $s): int {return $this->testDedicatedMeter($s)?120:900;}
    private function testMeterIDs(array $s): array
    {
        $keys=$this->testDedicatedMeter($s)
            ?['HeatPumpTestDedicatedPowerID','HeatPumpTestDedicatedCounterID','HeatPumpTestDedicatedExportID']
            :['HeatPumpTestHousePowerID','HeatPumpTestHouseCounterID'];
        return array_map(static fn($key)=>$s['settings'][$key],$keys);
    }
    private function testMeterFresh(array $s): void
    {
        $keys=$this->testDedicatedMeter($s)?['HeatPumpTestDedicatedPowerID','HeatPumpTestDedicatedCounterID']:['HeatPumpTestHousePowerID'];
        foreach($keys as $key) {
            $id=$s['settings'][$key];$this->testValue($id);
            $updated=(int)(IPS_GetVariable($id)['VariableUpdated']??0);$age=$this->sourceClock()-$updated;
            if($updated<=0||$age>180||$age < -30)throw new RuntimeException('Power/energy meter stale: '.$key);
        }
        if($this->testDedicatedMeter($s)) {
            $id=$s['settings']['HeatPumpTestDedicatedOnlineID'];
            if(!IPS_VariableExists($id)||IPS_GetVariable($id)['VariableType']!==0||!GetValue($id))throw new RuntimeException('Dedicated heat-pump meter is offline');
        }
    }
    private function testMeterSample(array $s): array
    {
        if(!$this->testDedicatedMeter($s))return [
            'houseWatts'=>$this->testValue($s['settings']['HeatPumpTestHousePowerID']),
            'houseCounter'=>$this->testValue($s['settings']['HeatPumpTestHouseCounterID']),
            'hpWatts'=>$this->testValue($s['settings']['HeatPumpTestHousePowerID']),'fanWatts'=>0.0,
            'electricalMethod'=>$this->testElectricalMethod($s)];
        $this->testMeterFresh($s);
        $watts=$this->testValue($s['settings']['HeatPumpTestDedicatedPowerID']);
        $counter=$this->testValue($s['settings']['HeatPumpTestDedicatedCounterID']);
        $export=$this->testValue($s['settings']['HeatPumpTestDedicatedExportID']);
        if($watts < -10||$watts>100000||$counter<0||$export<0)throw new RuntimeException('Invalid dedicated-meter reading or reversed power direction');
        if(isset($s['lastMeterSample'])) {
            $last=$s['lastMeterSample'];
            if($counter<$last['meterCounter']-1e-9||$export<$last['meterExportCounter']-1e-9)throw new RuntimeException('Dedicated-meter counter reset during test segment');
        }
        // Compare with this segment's initial export value, not its lifetime total.
        if(isset($s['meterSegmentExportStart'])&&($export-$s['meterSegmentExportStart'])*$s['settings']['HeatPumpTestDedicatedCounterScale']>0.001+1e-9)
            throw new RuntimeException('Dedicated meter reports exported energy during heating; check current-clamp direction and phase assignment');
        return ['hpWatts'=>$watts,'fanWatts'=>0.0,'meterCounter'=>$counter,'meterExportCounter'=>$export,
            'meterPowerID'=>$s['settings']['HeatPumpTestDedicatedPowerID'],'meterCounterID'=>$s['settings']['HeatPumpTestDedicatedCounterID'],
            'electricalMethod'=>$this->testElectricalMethod($s),
            'importUpdated'=>(int)(IPS_GetVariable($s['settings']['HeatPumpTestDedicatedCounterID'])['VariableUpdated']??0),
            'exportUpdated'=>(int)(IPS_GetVariable($s['settings']['HeatPumpTestDedicatedExportID'])['VariableUpdated']??0)];
    }
    private function testElectricalProvenance(array $s): array
    {
        if(!$this->testDedicatedMeter($s))return ['electricalMethod'=>$this->testElectricalMethod($s),
            'powerMeterID'=>$s['settings']['HeatPumpTestHousePowerID'],'counterID'=>$s['settings']['HeatPumpTestHouseCounterID'],
            'counterScale'=>$s['settings']['HeatPumpTestHouseCounterScale'],'meterScope'=>'household increment, including test equipment above pre-test baseline'];
        return ['electricalMethod'=>$this->testElectricalMethod($s),'powerMeterID'=>$s['settings']['HeatPumpTestDedicatedPowerID'],
            'counterID'=>$s['settings']['HeatPumpTestDedicatedCounterID'],'exportCounterID'=>$s['settings']['HeatPumpTestDedicatedExportID'],
            'counterScale'=>$s['settings']['HeatPumpTestDedicatedCounterScale'],
            'meterScope'=>'dedicated heat-pump circuit; equipment outside this circuit is excluded','baselineSubtracted'=>false];
    }
    private function captureTestMeterBoundaries(array &$s): void
    {
        if(!$this->testDedicatedMeter($s))return;
        $sample=[];
        foreach(['import'=>'HeatPumpTestDedicatedCounterID','export'=>'HeatPumpTestDedicatedExportID'] as $kind=>$key) {
            $id=$s['settings'][$key];
            if(!IPS_VariableExists($id))return; // Finalizer reports the missing sensor explicitly.
            $sample[$kind.'Updated']=(int)(IPS_GetVariable($id)['VariableUpdated']??0);
            $sample[$kind==='import'?'meterCounter':'meterExportCounter']=$this->testValue($id);
        }
        foreach($s['points'] as &$point)if(!($point['counterFinalized']??false)&&$this->sourceClock()<=$point['stepEndedAt']+75)$point['meterBoundaries'][]=$sample;
        unset($point);
    }
    private function finalizeTestCounterPoints(array &$s): void
    {
        if(!$this->testDedicatedMeter($s)){$this->finalizeHouseCounterPoints($s);return;}
        foreach($s['points'] as &$point) {
            if($point['counterFinalized']??false)continue;
            try {
                $start=$point['stepStartedAt'];$end=$point['stepEndedAt'];
                $intervals=HeatPumpTransient::measurementIntervals($point);$seconds=HeatPumpTransient::seconds($intervals);
                if($seconds<=0)throw new RuntimeException('Invalid dedicated-meter measurement interval');
                $energy=[];$fullEnergy=[];
                foreach(['import'=>'HeatPumpTestDedicatedCounterID','export'=>'HeatPumpTestDedicatedExportID'] as $kind=>$key) {
                    $records=AC_GetLoggedValues($s['settings']['HeatPumpTestArchiveID'],$s['settings'][$key],$start-90,min($end+75,$this->sourceClock()),0);
                    if(!is_array($records))throw new RuntimeException('Dedicated-meter history unavailable');
                    $records=array_values(array_filter($records,fn($r)=>$r['TimeStamp']>=$start-90&&$r['TimeStamp']<=min($end+75,$this->sourceClock())));
                    $archiveRecords=[];
                    foreach($records as $record)if(!isset($archiveRecords[$record['TimeStamp']]))$archiveRecords[$record['TimeStamp']]=$record;
                    $records=array_values($archiveRecords);
                    // Export may be unchanged for an entire step. Actual polled
                    // timestamps establish held values without inventing counter updates.
                    $boundaries=$point['meterBoundaries']??[];
                    $importID=$s['settings']['HeatPumpTestDedicatedCounterID'];$exportID=$s['settings']['HeatPumpTestDedicatedExportID'];
                    $boundaries[]=['meterCounter'=>$this->testValue($importID),'meterExportCounter'=>$this->testValue($exportID),
                        'importUpdated'=>(int)(IPS_GetVariable($importID)['VariableUpdated']??0),'exportUpdated'=>(int)(IPS_GetVariable($exportID)['VariableUpdated']??0)];
                    foreach($boundaries as $sample) {
                        $field=$kind==='import'?'meterCounter':'meterExportCounter';
                        $stamp=$sample[$kind.'Updated'];
                        if($stamp>=$start-90&&$stamp<=$end+75)$records[]=['TimeStamp'=>$stamp,'Value'=>$sample[$field]];
                    }
                    $ordered=[];
                    foreach($records as $record) {
                        if(!is_numeric($record['Value'])||!is_finite((float)$record['Value'])||$record['Value']<0)throw new RuntimeException('Invalid dedicated-meter counter history');
                        $stamp=(int)$record['TimeStamp'];
                        // Archive entries take precedence over separate polled boundary samples.
                        if(isset($archiveRecords[$stamp])&&isset($ordered[$stamp]))continue;
                        if(isset($ordered[$stamp])&&abs($ordered[$stamp]['Value']-$record['Value'])>1e-8)throw new RuntimeException('Conflicting dedicated-meter boundary samples');
                        $ordered[$stamp]=['TimeStamp'=>$stamp,'Value'=>(float)$record['Value']];
                    }
                    ksort($ordered);$previous=null;
                    foreach($ordered as $record){if($previous!==null&&$record['Value']<$previous)throw new RuntimeException('Dedicated-meter counter reset in measurement interval');$previous=$record['Value'];}
                    $scale=$s['settings']['HeatPumpTestDedicatedCounterScale'];
                    $fullEnergy[$kind]=(HeatPumpMatrix::counterAt(array_values($ordered),$end)-HeatPumpMatrix::counterAt(array_values($ordered),$start))*$scale;
                    $energy[$kind]=HeatPumpTransient::energy(array_values($ordered),$intervals,$scale);
                }
                if($fullEnergy['export']>0.001+1e-9)throw new RuntimeException('Dedicated meter exported energy; check clamp direction/phase assignment');
                if($energy['import']<=0)throw new RuntimeException('Dedicated-meter energy did not increase during the step');
                $point=array_replace($point,$this->testElectricalProvenance($s));
                if(isset($point['fullStepTemperatures']))$point['fullStep']=HeatPumpTransient::fullStep($point,$fullEnergy['import'],$fullEnergy['import'],$fullEnergy['export'],$s['settings']['HeatPumpTestAirflowM3h']);
                $point['systemInputKW']=$energy['import']*3600/$seconds;
                $point['estimatedHeatKW']=0.000335*$s['settings']['HeatPumpTestAirflowM3h']*$point['fullPeriodAirRise'];
                $point['estimatedSystemCOP']=$point['estimatedHeatKW']/$point['systemInputKW'];
                $point['estimatedHeatPumpCOP']=$point['estimatedSystemCOP'];
                $point['grossElectricityKWh']=$point['netElectricityKWh']=$energy['import'];
                $point['exportElectricityKWh']=$energy['export'];$point['estimatedHeatKWh']=$point['estimatedHeatKW']*$seconds/3600;
                $point['measurementSeconds']=$seconds;$point['baselineKW']=0.0;$point['lightingCorrectionApplied']=false;
                unset($point['hpWatts'],$point['fanWatts'],$point['heatPumpKW'],$point['fanIncrementKW']);
                $point['usable']=$point['stable']&&$point['airRise']>0&&$point['systemInputKW']>0.05&&$point['estimatedSystemCOP']>=0.5&&$point['estimatedSystemCOP']<=10;
            } catch(Throwable $error){$point['usable']=false;$point['counterError']=$error->getMessage();}
            $point['counterFinalized']=true;
        }unset($point);
    }
}
