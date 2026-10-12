<?php
declare(strict_types=1);

trait LichtBlickPriceHistory
{
    protected function priceHistoryDirectory(): string
    {
        return rtrim(IPS_GetKernelDir(), '/\\') . '/media/lichtblick-price-history/' . $this->InstanceID;
    }

    private function priceStore(): LichtBlickPriceStore
    {
        return new LichtBlickPriceStore($this->priceHistoryDirectory());
    }

    private function historyIdentity(): string
    {
        return LichtBlickPriceStore::identity($this->ReadPropertyString('PostalCode'), $this->ReadPropertyFloat('PriceCorrectionCt'));
    }

    private function historyStart(): int
    {
        return LichtBlickPriceStore::dates($this->ReadPropertyString('PriceHistoryStartDate'), $this->ReadPropertyString('PriceHistoryStartDate'))[0];
    }

    private function storeQuotes(array $slots): void
    {
        if (!$this->ReadPropertyBoolean('PriceHistoryEnabled')) return;
        if (!IPS_SemaphoreEnter('LBEP.History.' . $this->InstanceID, 1000)) throw new RuntimeException('Price-history store busy; retry on next fetch');
        try {
            $postcode = $this->ReadPropertyString('PostalCode'); $correction = $this->ReadPropertyFloat('PriceCorrectionCt'); $now = time();
            $slots = array_values(array_filter($slots, fn($slot) => $slot['start'] >= $this->historyStart()));
            foreach ($slots as &$slot) $slot += ['source' => 'lichtblick-quote', 'postcode' => $postcode,
                'rawPriceCt' => $slot['price'] - $correction, 'correctionCt' => $correction,
                'verified' => $this->ReadPropertyBoolean('TariffVerified'), 'fetchedAt' => $now, 'resolutionSeconds' => 900];
            unset($slot);
            $count = $this->priceStore()->merge($this->historyIdentity(), $slots);
            $this->SetValue('PriceHistoryStatus', 'Stored LichtBlick schedule: ' . count($slots) . ' intervals; ' . $count . ' new/changed. Previous months retained.');
        } finally { IPS_SemaphoreLeave('LBEP.History.' . $this->InstanceID); }
    }

    /** Raw, read-only history contract for the future consumption module. End is exclusive. */
    public function GetPriceHistory(int $from, int $to): string
    {
        if ($to - $from > 32 * 86400) throw new InvalidArgumentException('Request at most 31 calendar days of raw intervals');
        if ($from < $this->historyStart()) throw new InvalidArgumentException('Period precedes electricity contract start');
        return json_encode(['schema' => 1, 'identity' => $this->historyIdentity(), 'from' => $from, 'to' => $to,
            'intervals' => $this->priceStore()->query($this->historyIdentity(), $from, $to)], JSON_THROW_ON_ERROR);
    }

    private function historyChart(string $from, string $to): array
    {
        [$a, $b] = LichtBlickPriceStore::dates($from, $to);
        if ($a < $this->historyStart()) throw new InvalidArgumentException('Period precedes electricity contract start');
        return LichtBlickPriceStore::chart($this->priceStore()->query($this->historyIdentity(), $a, $b), $a, $b)
            + ['postalCode' => $this->ReadPropertyString('PostalCode'), 'status' => $this->GetValue('HistoricalFetchStatus')];
    }

    public function RetrieveHistoricalPrices(string $from, string $to): string
    {
        try {
            [$a, $b] = LichtBlickPriceStore::dates($from, $to);
            if ($a < $this->historyStart()) throw new InvalidArgumentException('Do not import before contract start ' . $this->ReadPropertyString('PriceHistoryStartDate'));
            $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->getTimestamp();
            if ($b > $today) throw new InvalidArgumentException('Historical imports require completed calendar days');
            if (!$this->ReadPropertyBoolean('PriceHistoryEnabled') || !$this->ReadPropertyBoolean('HistoricalFetchEnabled')) throw new RuntimeException('Enable price history and SMARD historical retrieval first');
            if (!preg_match('/^\d{5}$/D', $this->ReadPropertyString('PostalCode'))) throw new RuntimeException('Set your delivery postcode first');
            if (!IPS_SemaphoreEnter('LBEP.History.' . $this->InstanceID, 1000)) throw new RuntimeException('History busy; retry');
            try {
                if ($this->ReadAttributeString('HistoricalImportQueue') !== '') throw new RuntimeException('A historical import is already queued');
                $queue = ['schema' => 1, 'from' => $a, 'next' => $a, 'to' => $b, 'identity' => $this->historyIdentity(), 'imported' => 0, 'missing' => 0];
                $this->WriteAttributeString('HistoricalImportQueue', json_encode($queue, JSON_THROW_ON_ERROR));
                $this->SetBuffer('HistoryRetryAt', '');
                $this->SetValue('HistoricalFetchStatus', 'Queued historical prices: ' . $from . ' through ' . $to . '. Runs in weekly batches.');
                $this->SetTimerInterval('Tick', 60000);
                return (string)$this->GetValue('HistoricalFetchStatus');
            } finally { IPS_SemaphoreLeave('LBEP.History.' . $this->InstanceID); }
        } catch (Throwable $e) { return 'Historical import not queued: ' . $e->getMessage(); }
    }

    public function CancelHistoricalImport(): void
    {
        if (!IPS_SemaphoreEnter('LBEP.History.' . $this->InstanceID, 1000)) return;
        try {
            $this->WriteAttributeString('HistoricalImportQueue', '');
            $this->SetValue('HistoricalFetchStatus', 'Historical import cancelled. Previously stored prices retained.');
        } finally { IPS_SemaphoreLeave('LBEP.History.' . $this->InstanceID); }
    }

    /** Fixed public SMARD host and filter; callers cannot supply a URL. */
    protected function downloadMarket(?int $week): string
    {
        $path = $week === null ? 'index_quarterhour.json' : '4169_DE_quarterhour_' . $week . '.json';
        return $this->downloadPriceJSON('https://www.smard.de/app/chart_data/4169/DE/' . $path);
    }

    private function historyTick(): void
    {
        if (!$this->ReadPropertyBoolean('PriceHistoryEnabled') || !$this->ReadPropertyBoolean('HistoricalFetchEnabled')
            || !preg_match('/^\d{5}$/D', $this->ReadPropertyString('PostalCode'))) return;
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        if ($today->getTimestamp() <= $this->historyStart()) return;
        if ($this->ReadAttributeString('HistoricalImportQueue') === '' && $this->ReadAttributeString('HistoryAutomaticDay') !== $today->format('Y-m-d')) {
            // First use retrieves from contract start; later runs revisit seven days to recover gaps/revisions.
            $previous = $this->ReadAttributeString('HistoryAutomaticDay');
            $from = $previous === '' ? $this->ReadPropertyString('PriceHistoryStartDate')
                : max($this->ReadPropertyString('PriceHistoryStartDate'), min($previous, $today->modify('-7 days')->format('Y-m-d')));
            $until = min($today, (new DateTimeImmutable($from, new DateTimeZone('Europe/Berlin')))->modify('+366 days'));
            $result = $this->RetrieveHistoricalPrices($from, $until->modify('-1 day')->format('Y-m-d'));
            if (!str_starts_with($result, 'Historical import not queued:')) $this->WriteAttributeString('HistoryAutomaticDay', $until->format('Y-m-d'));
        }
        if ($this->ReadAttributeString('HistoricalImportQueue') !== '' && time() >= (int)$this->GetBuffer('HistoryRetryAt')) $this->ProcessHistoricalImport();
    }

    public function ProcessHistoricalImport(): void
    {
        if (!$this->ReadPropertyBoolean('PriceHistoryEnabled') || !$this->ReadPropertyBoolean('HistoricalFetchEnabled')) return;
        if (!IPS_SemaphoreEnter('LBEP.History.' . $this->InstanceID, 100)) return;
        try {
            $raw = $this->ReadAttributeString('HistoricalImportQueue'); if ($raw === '') return;
            $queue = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (($queue['schema'] ?? 0) !== 1 || $queue['identity'] !== $this->historyIdentity()) {
                $this->WriteAttributeString('HistoricalImportQueue', ''); throw new RuntimeException('Import cancelled because price region/correction changed');
            }
            $index = json_decode($this->GetBuffer('SMARDIndex') ?: '{}', true);
            if (!is_array($index) || ($index['fetchedAt'] ?? 0) < time() - 86400) {
                $body = json_decode($this->downloadMarket(null), true, 32, JSON_THROW_ON_ERROR);
                $times = $body['timestamps'] ?? null;
                if (!is_array($times) || !array_is_list($times) || !$times || count($times) > 2000) throw new RuntimeException('Invalid SMARD index');
                foreach ($times as $t) if (!is_int($t) || $t <= 0 || $t % 1000 !== 0) throw new RuntimeException('Invalid SMARD week timestamp');
                $times = array_values(array_unique($times)); sort($times, SORT_NUMERIC);
                $index = ['fetchedAt' => time(), 'timestamps' => $times];
                $this->SetBuffer('SMARDIndex', json_encode($index, JSON_THROW_ON_ERROR));
            }
            $week = null; $end = $queue['to'];
            foreach ($index['timestamps'] as $t) {
                if (intdiv($t, 1000) <= $queue['next']) $week = $t;
                elseif ($week !== null) { $end = min($end, intdiv($t, 1000)); break; }
            }
            if ($week === null) throw new RuntimeException('Requested date precedes available SMARD history');
            // The final index entry has no following week; never advance beyond its seven-day calendar block.
            $weekEnd = (new DateTimeImmutable('@' . intdiv($week, 1000)))->setTimezone(new DateTimeZone('Europe/Berlin'))->modify('+7 days')->getTimestamp();
            $end = min($end, $weekEnd);
            if ($end <= $queue['next']) throw new RuntimeException('SMARD has not published this period yet');
            $slots = LichtBlickPriceStore::market($this->downloadMarket($week), $queue['next'], $end);
            $tariffs = LichtBlickPriceStore::tariffs($this->ReadPropertyString('HistoricalTariffs'));
            $rows = LichtBlickPriceStore::reconstruct($slots, $tariffs, $this->ReadPropertyString('PostalCode'), time());
            $this->priceStore()->merge($queue['identity'], $rows);
            $queue['imported'] += count($rows); $queue['missing'] += (int)(($end - $queue['next']) / 900) - count($rows);
            $queue['next'] = $end;
            $done = $end >= $queue['to'];
            $this->WriteAttributeString('HistoricalImportQueue', $done ? '' : json_encode($queue, JSON_THROW_ON_ERROR));
            $this->SetValue('LastHistoricalFetch', time());
            $this->SetValue('HistoricalFetchStatus', ($done ? 'Completed' : 'Importing') . ': ' . $queue['imported']
                . ' SMARD intervals; ' . $queue['missing'] . ' unavailable. Retail prices require a dated verified tariff; direct LichtBlick quotes retained.');
            $this->SetBuffer('HistoryRetryAt', (string)(time() + 60));
        } catch (Throwable $e) {
            $this->SetBuffer('HistoryRetryAt', (string)(time() + 900));
            $this->SetValue('HistoricalFetchStatus', 'Historical retrieval failed; retry in 15 minutes: ' . $e->getMessage());
            $this->debug((string)$this->GetValue('HistoricalFetchStatus'));
        } finally { IPS_SemaphoreLeave('LBEP.History.' . $this->InstanceID); }
    }
}
