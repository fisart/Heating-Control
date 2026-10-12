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
            + ['postalCode' => $this->ReadPropertyString('PostalCode'), 'status' => $this->GetValue('PriceHistoryStatus')];
    }

}
