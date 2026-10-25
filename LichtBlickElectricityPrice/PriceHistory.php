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

    private function storeQuotes(array $slots,bool $fillOnly=false): void
    {
        if (!$this->ReadPropertyBoolean('PriceHistoryEnabled')) return;
        if (!IPS_SemaphoreEnter('LBEP.History.' . $this->InstanceID, 1000)) throw new RuntimeException('Price-history store busy; retry on next fetch');
        try {
            $postcode = $this->ReadPropertyString('PostalCode'); $correction = $this->ReadPropertyFloat('PriceCorrectionCt'); $now = time();
            $slots = array_values(array_filter($slots, fn($slot) => $slot['start'] >= $this->historyStart()));
            if($fillOnly&&$slots) {
                $known=[];foreach($this->priceStore()->query($this->historyIdentity(),min(array_column($slots,'start')),max(array_column($slots,'end'))) as $r)
                    if(is_numeric($r['price']??null))$known[$r['start']]=true;
                $slots=array_values(array_filter($slots,static fn($r)=>!isset($known[$r['start']])));
            }
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
        $rows=$this->priceStore()->query($this->historyIdentity(),$from,$to);$previous=null;$error=null;
        try{$previous=$this->priceStore()->lastKnown($this->historyIdentity(),$from);}catch(Throwable $e){$error=$e->getMessage();}
        return json_encode(['schema' => 1, 'identity' => $this->historyIdentity(), 'from' => $from, 'to' => $to,
            'intervals'=>$rows,'previousInterval'=>$previous,'previousIntervalError'=>$error],JSON_THROW_ON_ERROR);
    }

    /** Attempt repair using original supplier quotes only. This does not
     * evaluate recommendations, replace the live cache or command equipment.
     * The public supplier response may not include the requested older dates. */
    public function RecoverPriceHistory(int $from,int $to): string
    {
        $before=json_decode($this->GetPriceHistory($from,$to),true,32,JSON_THROW_ON_ERROR);
        $missing=static function(array $rows) use ($from,$to): int {
            $count=0;$map=[];foreach($rows as $r)if(is_numeric($r['price']??null))$map[$r['start']]=true;
            for($t=intdiv($from,900)*900;$t<$to;$t+=900)if(!isset($map[$t]))$count++;
            return $count;
        };
        $wanted=$missing($before['intervals']);$error=null;$attempted=false;
        if($wanted>0)try {
            if(!$this->ReadPropertyBoolean('PriceHistoryEnabled'))throw new RuntimeException('Price-history storage is disabled');
            $postcode=$this->ReadPropertyString('PostalCode');
            if(!preg_match('/^\d{5}$/D',$postcode))throw new RuntimeException('Invalid delivery postcode');
            $attempted=true;
            $slots=LichtBlickEconomics::parsePrices($this->download($postcode),$this->ReadPropertyFloat('PriceCorrectionCt'));
            // Limit the write to actual holes; preserve all valid saved prices.
            $existing=[];foreach($before['intervals'] as $r)if(is_numeric($r['price']??null))$existing[$r['start']]=true;
            $slots=array_values(array_filter($slots,static fn($r)=>$r['start']<$to&&$r['end']>$from&&!isset($existing[$r['start']])));
            if($slots)$this->storeQuotes($slots,true);
        }catch(Throwable $e){$error=$e->getMessage();}
        $after=json_decode($this->GetPriceHistory($from,$to),true,32,JSON_THROW_ON_ERROR);
        return json_encode($after+['recovery'=>['downloadAttempted'=>$attempted,'missingBefore'=>$wanted,
            'missingAfter'=>$missing($after['intervals']),'error'=>$error,
            'note'=>'Only dates present in the original supplier response can be downloaded; older holes may remain.']],JSON_THROW_ON_ERROR);
    }

    private function historyChart(string $from, string $to): array
    {
        [$a, $b] = LichtBlickPriceStore::dates($from, $to);
        if ($a < $this->historyStart()) throw new InvalidArgumentException('Period precedes electricity contract start');
        return LichtBlickPriceStore::chart($this->priceStore()->query($this->historyIdentity(), $a, $b), $a, $b)
            + ['postalCode' => $this->ReadPropertyString('PostalCode'), 'status' => $this->GetValue('PriceHistoryStatus')];
    }

}
