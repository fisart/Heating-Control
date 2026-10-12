<?php
declare(strict_types=1);

/** Read-only access to reviewed gas observations and sparse invoice references. */
final class LichtBlickGasHistory
{
    private LichtBlickConsumptionHistory $archive;

    public function __construct(private int $archiveID, private array $ids)
    {
        $this->archive = new LichtBlickConsumptionHistory($archiveID, []);
    }

    public static function metadata(int $id, array $ids, int $archive): array
    {
        if ($id <= 0 || !IPS_VariableExists($id)) throw new RuntimeException('Gas import metadata is not connected');
        $json = GetValue($id);
        if (!is_string($json) || strlen($json) > 262144) throw new RuntimeException('Invalid gas metadata variable');
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? '') !== 'Symcon.GasReviewedSnapshot.v1'
            || ($data['import_status'] ?? '') !== 'verified'
            || ($data['source_archive_id'] ?? null) !== $archive
            || ($data['target_variable_ids'] ?? []) !== $ids
            || ($ids['InvoicePeriods'] ?? 0) !== $id
            || !is_array($data['invoices'] ?? null) || !$data['invoices']) {
            throw new RuntimeException('Gas import identity, status or archive differs from the configuration');
        }
        $first = (new DateTimeImmutable($data['from']))->getTimestamp();
        $last = (new DateTimeImmutable($data['to']))->getTimestamp();
        if ($last <= $first || ($data['archive_read_end_epoch'] ?? null) !== $last) throw new RuntimeException('Invalid gas snapshot boundaries');
        $previousEnd = $first;
        foreach ($data['invoices'] as $invoice) {
            [$a, $b] = LichtBlickPriceStore::dates($invoice['from'] ?? '', $invoice['to'] ?? '', 400);
            if ($a !== $previousEnd || $b - 1 > $last) throw new RuntimeException('Invalid or noncontiguous gas invoice periods');
            foreach (['supplier_m3','supplier_kwh','cleaned_observed_m3','supply_gross_eur',
                'december_aid_gross_eur','price_brake_credit_eur','cost_after_aid_eur','supplier_minus_cleaned_m3'] as $key) {
                if (!is_numeric($invoice[$key] ?? null) || !is_finite((float)$invoice[$key])) throw new RuntimeException('Invalid gas invoice number');
            }
            if ($invoice['supplier_m3'] < 0 || $invoice['supplier_kwh'] < 0
                || abs($invoice['supply_gross_eur'] - $invoice['december_aid_gross_eur'] - $invoice['price_brake_credit_eur'] - $invoice['cost_after_aid_eur']) > .011
                || abs($invoice['supplier_m3'] - $invoice['cleaned_observed_m3'] - $invoice['supplier_minus_cleaned_m3']) > 1e-6) {
                throw new RuntimeException('Gas invoice reconciliation is inconsistent');
            }
            $previousEnd = $b;
        }
        return $data;
    }

    public function verifyReferences(array $data): void
    {
        $a = (new DateTimeImmutable($data['from']))->getTimestamp();
        $b = (new DateTimeImmutable($data['to']))->getTimestamp();
        $m3 = $this->archive->rows($this->ids['ReferenceCounterM3'], $a, $b);
        $kwh = $this->archive->rows($this->ids['ReferenceCounterKWh'], $a, $b);
        if (count($m3) !== count($data['invoices']) + 1 || count($kwh) !== count($m3)
            || !isset($m3[$a], $kwh[$a]) || abs($m3[$a]) > 1e-7 || abs($kwh[$a]) > 1e-7) {
            throw new RuntimeException('Gas invoice-reference archive boundaries are missing');
        }
        $totalM3 = $totalKWh = 0.0;
        foreach ($data['invoices'] as $invoice) {
            [, $end] = LichtBlickPriceStore::dates($invoice['from'], $invoice['to'], 400);
            $totalM3 += $invoice['supplier_m3']; $totalKWh += $invoice['supplier_kwh'];
            if (!isset($m3[$end-1], $kwh[$end-1])
                || abs($m3[$end-1] - $totalM3) > 1e-7 || abs($kwh[$end-1] - $totalKWh) > 1e-7) {
                throw new RuntimeException('Gas invoice-reference archives differ from the reviewed import');
            }
        }
    }

    /** Daily observations stay null on days without archived records. */
    public function prepared(array $data, int $from, int $to): array
    {
        $zone = new DateTimeZone('Europe/Berlin');
        $cutoff = (new DateTimeImmutable($data['to']))->getTimestamp();
        $end = min($to-1, $cutoff);
        if ($from > $end) return [];
        $daily = $this->archive->rows($this->ids['ObservedDailyM3'], $from, $end);
        $quality = $this->archive->rows($this->ids['QualityFlags'], $from, $end);
        $counter = $this->archive->rows($this->ids['CleanedCounterM3'], $from, $end);
        $baseline = AC_GetLoggedValues($this->archiveID, $this->ids['CleanedCounterM3'], 0, $from, 1);
        if (!is_array($baseline) || !$baseline || !is_numeric($baseline[0]['Value'])) throw new RuntimeException('Gas cleaned-counter baseline is missing');
        $previous = (float)$baseline[0]['Value']; $points = [];
        $day = (new DateTimeImmutable('@'.$from))->setTimezone($zone)->setTime(0, 0);
        while ($day->getTimestamp() <= $end) {
            $next = $day->modify('+1 day'); $stamp = min($next->getTimestamp()-1, $cutoff);
            if (!array_key_exists($stamp, $quality)) throw new RuntimeException('Gas daily quality flag is missing');
            $flag = $quality[$stamp];
            if ($flag < 0 || $flag !== floor($flag)) throw new RuntimeException('Invalid gas quality bitmask');
            $value = $daily[$stamp] ?? null;
            if ($value !== null) {
                if ($value < 0 || !isset($counter[$stamp]) || abs($counter[$stamp] - $previous - $value) > 1e-6 || ((int)$flag & 8)) {
                    throw new RuntimeException('Gas daily archive differs from its cleaned cumulative counter');
                }
                $previous = $counter[$stamp];
            } elseif (!((int)$flag & 8) || isset($counter[$stamp])) throw new RuntimeException('Gas missing-day provenance is inconsistent');
            $points[] = ['start'=>$day->getTimestamp(), 'end'=>$stamp+1, 'observedM3'=>$value,
                'qualityFlags'=>(int)$flag, 'source'=>'reviewed-archive'];
            $day = $next;
        }
        return $points;
    }

    /** Later raw events are observed differences, never a reset reconstruction. */
    public function later(int $id, float $scale, int $from, int $to): array
    {
        if ($from >= $to) return [];
        $zone = new DateTimeZone('Europe/Berlin'); $values = $this->archive->rows($id, $from, $to-1);
        $seed = AC_GetLoggedValues($this->archiveID, $id, 0, $from-1, 1);
        if (!is_array($seed) || !$seed) throw new RuntimeException('Later gas meter baseline is missing');
        $last = (int)$seed[0]['TimeStamp']; $previous = (float)$seed[0]['Value'];
        if (!is_finite($previous) || $last >= $from) throw new RuntimeException('Invalid later gas baseline');
        $daily = [];
        foreach ($values as $stamp=>$value) {
            $day = (new DateTimeImmutable('@'.$stamp))->setTimezone($zone)->format('Y-m-d');
            if (!isset($daily[$day])) $daily[$day] = ['sum'=>0.0,'flags'=>64|1024,'bad'=>false];
            $delta = ($value - $previous)*$scale;
            if ($delta < 0 || $value < 0) { $daily[$day]['bad']=true; $daily[$day]['flags']|=512; }
            elseif ($delta > 0) $daily[$day]['sum'] += $delta;
            if ($stamp-$last > 86400) $daily[$day]['flags'] |= 4;
            $previous = $value; $last = $stamp;
        }
        $points = []; $day = (new DateTimeImmutable('@'.$from))->setTimezone($zone)->setTime(0, 0);
        while ($day->getTimestamp() < $to) {
            $next = $day->modify('+1 day'); $key = $day->format('Y-m-d'); $entry = $daily[$key] ?? null;
            $flags = $entry['flags'] ?? (64|1024|8);
            if ($to < $next->getTimestamp()) $flags |= 128;
            $points[] = ['start'=>max($from, $day->getTimestamp()), 'end'=>min($to, $next->getTimestamp()),
                'observedM3'=>$entry && !$entry['bad'] ? $entry['sum'] : null,
                'qualityFlags'=>$flags, 'source'=>'later-meter-archive'];
            $day = $next;
        }
        return $points;
    }

    public static function report(array $points, array $data, int $from, int $to, string $resolution, array $tariffs = []): array
    {
        $zone = new DateTimeZone('Europe/Berlin'); $buckets = [];
        $totals = ['knownObservedM3'=>0.0,'missingObservationDays'=>0,'resetIntervalDays'=>0,
            'laterUnreviewedDays'=>0,'invoicedM3'=>0.0,'invoicedKWh'=>0.0,'supplyGrossEuro'=>0.0,
            'creditsEuro'=>0.0,'costAfterAidEuro'=>0.0,'completeInvoices'=>0,
            'knownConvertedKWh'=>0.0,'knownVariableCostEuro'=>0.0,'knownFixedCostEuro'=>0.0,
            'knownCalculatedCostEuro'=>0.0,'missingTariffDays'=>0,'incompleteCostDays'=>0,'estimatedConversionDays'=>0,'confirmedZeroDays'=>0];
        // Merge the snapshot's partial last day with later events without double counting.
        $days = [];
        foreach ($points as $point) {
            $key = (new DateTimeImmutable('@'.$point['start']))->setTimezone($zone)->format('Y-m-d');
            if (!isset($days[$key])) $days[$key] = ['start'=>$point['start'],'end'=>$point['end'],
                'knownObservedM3'=>0.0,'observedM3'=>0.0,'qualityFlags'=>0,'missingParts'=>0,
                'knownConvertedKWh'=>0.0,'knownVariableCostEuro'=>0.0,'knownFixedCostEuro'=>0.0,
                'knownCalculatedCostEuro'=>0.0,'missingTariffParts'=>0,'incompleteCostParts'=>0,'conversionEstimated'=>false,
                'unitGrossCtMin'=>null,'unitGrossCtMax'=>null,'confirmedNoConsumption'=>true];
            $d = &$days[$key]; $d['start']=min($d['start'],$point['start']); $d['end']=max($d['end'],$point['end']);
            $d['qualityFlags'] |= $point['qualityFlags'];
            $d['confirmedNoConsumption']=$d['confirmedNoConsumption'] && ($point['confirmedNoConsumption']??false);
            if ($point['observedM3'] === null) $d['missingParts']++;
            else { $d['knownObservedM3'] += $point['observedM3']; $d['observedM3'] += $point['observedM3']; }
            foreach (['convertedKWh'=>'knownConvertedKWh','variableCostEuro'=>'knownVariableCostEuro',
                'fixedCostEuro'=>'knownFixedCostEuro','knownCalculatedCostEuro'=>'knownCalculatedCostEuro'] as $input=>$output) {
                if (is_numeric($point[$input]??null)) $d[$output]+=$point[$input];
            }
            if ($point['tariffMissing']??true) $d['missingTariffParts']++;
            if (($point['calculatedCostEuro']??null)===null) $d['incompleteCostParts']++;
            $d['conversionEstimated']=$d['conversionEstimated']||($point['conversionEstimated']??false);
            if (is_numeric($point['unitGrossCt']??null)) {
                $d['unitGrossCtMin']=$d['unitGrossCtMin']===null ? $point['unitGrossCt'] : min($d['unitGrossCtMin'],$point['unitGrossCt']);
                $d['unitGrossCtMax']=$d['unitGrossCtMax']===null ? $point['unitGrossCt'] : max($d['unitGrossCtMax'],$point['unitGrossCt']);
            }
            unset($d);
        }
        ksort($days);
        foreach ($days as $key=>$day) {
            $date = new DateTimeImmutable($key.' 00:00:00', $zone);
            $bucket = match ($resolution) {
                'monthly'=>$date->modify('first day of this month')->format('Y-m-d'),
                'yearly'=>$date->setDate((int)$date->format('Y'),1,1)->format('Y-m-d'), default=>$key
            };
            if (!isset($buckets[$bucket])) $buckets[$bucket] = ['start'=>$day['start'],'end'=>$day['end'],
                'knownObservedM3'=>0.0,'observedM3'=>0.0,'qualityFlags'=>0,'missingObservationDays'=>0,
                'knownConvertedKWh'=>0.0,'knownVariableCostEuro'=>0.0,'knownFixedCostEuro'=>0.0,
                'knownCalculatedCostEuro'=>0.0,'calculatedCostEuro'=>null,'missingTariffDays'=>0,'incompleteCostDays'=>0,
                'estimatedConversionDays'=>0,'unitGrossCtMin'=>null,'unitGrossCtMax'=>null,'confirmedNoConsumption'=>true];
            $b = &$buckets[$bucket]; $b['end']=$day['end']; $b['qualityFlags']|=$day['qualityFlags'];
            $b['confirmedNoConsumption']=$b['confirmedNoConsumption'] && $day['confirmedNoConsumption'];
            if ($day['confirmedNoConsumption']) $totals['confirmedZeroDays']++;
            $b['knownObservedM3'] += $day['knownObservedM3']; $totals['knownObservedM3'] += $day['knownObservedM3'];
            if ($day['missingParts']) { $b['missingObservationDays']++; $totals['missingObservationDays']++; }
            else $b['observedM3'] += $day['observedM3'];
            if (!$day['confirmedNoConsumption'] && ($day['qualityFlags'] & (1|256|512))) $totals['resetIntervalDays']++;
            if ($day['qualityFlags'] & 1024) $totals['laterUnreviewedDays']++;
            foreach (['knownConvertedKWh','knownVariableCostEuro','knownFixedCostEuro','knownCalculatedCostEuro'] as $key) {
                $b[$key]+=$day[$key]; $totals[$key]+=$day[$key];
            }
            foreach (['missingTariffParts'=>'missingTariffDays','incompleteCostParts'=>'incompleteCostDays','conversionEstimated'=>'estimatedConversionDays'] as $input=>$output) {
                if ($day[$input]) { $b[$output]++; $totals[$output]++; }
            }
            if ($day['unitGrossCtMin']!==null) {
                $b['unitGrossCtMin']=$b['unitGrossCtMin']===null ? $day['unitGrossCtMin'] : min($b['unitGrossCtMin'],$day['unitGrossCtMin']);
                $b['unitGrossCtMax']=$b['unitGrossCtMax']===null ? $day['unitGrossCtMax'] : max($b['unitGrossCtMax'],$day['unitGrossCtMax']);
            }
            unset($b);
        }
        foreach ($buckets as &$bucket) {
            if ($bucket['missingObservationDays']) $bucket['observedM3']=null;
            if (!$bucket['incompleteCostDays']) $bucket['calculatedCostEuro']=$bucket['knownCalculatedCostEuro'];
        }
        unset($bucket);
        $invoices = [];
        foreach ($data['invoices'] as $invoice) {
            [$a, $b] = LichtBlickPriceStore::dates($invoice['from'], $invoice['to'], 400);
            if ($a >= $to || $b <= $from) continue;
            $full = $a >= $from && $b <= $to;
            $calc=null; $incomplete=false;
            if ($full && $tariffs) {
                $calc=0.0; $seconds=0;
                foreach ($points as $point) if ($point['start']>=$a && $point['end']<=$b) {
                    $seconds+=$point['end']-$point['start'];
                    if (($point['calculatedCostEuro']??null)===null) $incomplete=true;
                    $calc+=$point['knownCalculatedCostEuro']??0.0;
                }
                if ($seconds!==$b-$a) $calc=null;
            }
            $invoices[] = $invoice + ['wholeInvoiceSelected'=>$full,'calculatedKnownSupplyGrossEuro'=>$calc,
                'supplyMinusCalculatedKnownEuro'=>$calc===null ? null : $invoice['supply_gross_eur']-$calc,
                'calculationIncomplete'=>$incomplete];
            if ($full) {
                $totals['completeInvoices']++; $totals['invoicedM3'] += $invoice['supplier_m3'];
                $totals['invoicedKWh'] += $invoice['supplier_kwh']; $totals['supplyGrossEuro'] += $invoice['supply_gross_eur'];
                $totals['creditsEuro'] += $invoice['december_aid_gross_eur'] + $invoice['price_brake_credit_eur'];
                $totals['costAfterAidEuro'] += $invoice['cost_after_aid_eur'];
            }
        }
        return ['from'=>$from,'to'=>$to,'resolution'=>$resolution,'preparedThrough'=>$data['to'],
            'points'=>array_values($buckets),'invoices'=>$invoices,'totals'=>$totals,
            'qualityBits'=>$data['quality_bits'] + ['512'=>'later_unresolved_counter_drop','1024'=>'later_unreviewed_meter_archive',
                '2048'=>'dated_tariff_missing','4096'=>'conversion_factor_carried_forward_estimate',
                '8192'=>'user_confirmed_no_consumption','16384'=>'recorded_or_allocated_energy_conflicts_with_confirmed_no_use'],
            'tariffs'=>array_values(array_filter($tariffs,static fn($r)=>$r['start']<$to && $r['end']>$from)),
            'costsCalculationEnabled'=>(bool)$tariffs,
            'dailyReferenceAllocation'=>false,'dailyKWhConversion'=>(bool)$tariffs,'dailyCostAllocation'=>false];
    }
}

trait LichtBlickGasHistoryAccess
{
    private function gasHistoryIDs(): array
    {
        return ['CleanedCounterM3'=>$this->ReadPropertyInteger('GasHistoryCleanedCounterID'),
            'ObservedDailyM3'=>$this->ReadPropertyInteger('GasHistoryDailyID'),
            'ReferenceCounterM3'=>$this->ReadPropertyInteger('GasHistoryReferenceM3ID'),
            'ReferenceCounterKWh'=>$this->ReadPropertyInteger('GasHistoryReferenceKWhID'),
            'QualityFlags'=>$this->ReadPropertyInteger('GasHistoryQualityID'),
            'InvoicePeriods'=>$this->ReadPropertyInteger('GasHistoryMetadataID')];
    }

    private function gasHistorySettings(): array
    {
        $settings = ['gasHistoryEnabled'=>$this->ReadPropertyBoolean('GasHistoryEnabled'),
            'gasHistoryFrom'=>'','gasHistoryThrough'=>'','gasHistoryError'=>''];
        if (!$settings['gasHistoryEnabled']) return $settings;
        try {
            $data = LichtBlickGasHistory::metadata($this->ReadPropertyInteger('GasHistoryMetadataID'), $this->gasHistoryIDs(), $this->ReadPropertyInteger('ArchiveID'));
            $settings['gasHistoryFrom']=substr($data['from'],0,10); $settings['gasHistoryThrough']=substr($data['to'],0,10);
        } catch (Throwable $e) { $settings['gasHistoryError']=$e->getMessage(); }
        return $settings;
    }

    public function GetGasHistory(string $from, string $through, string $resolution = 'auto'): string
    {
        return json_encode($this->gasHistoryChart($from,$through,$resolution),JSON_THROW_ON_ERROR);
    }

    private function gasHistoryChart(string $from, string $through, string $resolution): array
    {
        if (!$this->ReadPropertyBoolean('GasHistoryEnabled')) throw new RuntimeException('Reviewed gas history is not connected');
        if (!in_array($resolution,['auto','daily','monthly','yearly'],true)) throw new InvalidArgumentException('Select a valid gas-history resolution');
        [$a,$b] = LichtBlickPriceStore::dates($from,$through,2192);
        $data = LichtBlickGasHistory::metadata($this->ReadPropertyInteger('GasHistoryMetadataID'), $this->gasHistoryIDs(), $this->ReadPropertyInteger('ArchiveID'));
        $first=(new DateTimeImmutable($data['from']))->getTimestamp(); $last=(new DateTimeImmutable($data['to']))->getTimestamp();
        if ($a<$first) throw new InvalidArgumentException('Reviewed gas history starts on '.substr($data['from'],0,10));
        if ($resolution==='auto') $resolution=$b-$a>100*86400?'monthly':'daily';
        $adapter=new LichtBlickGasHistory($this->ReadPropertyInteger('ArchiveID'),$this->gasHistoryIDs());
        $adapter->verifyReferences($data);
        $points=$adapter->prepared($data,$a,min($b,$last+1));
        $laterStart=max($a,$last+1); $now=time()+1;
        if ($laterStart<min($b,$now)) $points=array_merge($points,$adapter->later(
            $this->ReadPropertyInteger('GasHistoryLaterCounterID'),$this->ReadPropertyFloat('GasHistoryLaterCounterScale'),$laterStart,min($b,$now)));
        $points=LichtBlickNoConsumption::apply($points,LichtBlickNoConsumption::periods($this->ReadPropertyString('ConsumptionZeroPeriods')),'gas');
        $tariffs=LichtBlickGasCosts::tariffs($this->ReadPropertyString('GasHistoryTariffs'));
        $points=LichtBlickGasCosts::apply($points,$tariffs);
        return LichtBlickGasHistory::report($points,$data,$a,$b,$resolution,$tariffs);
    }
}
