<?php

namespace Tests\Fixtures;

use App\Scraping\LexUz\LexHttpClient;

class VirtualLexHttpClient extends LexHttpClient
{
    public float $clock = 1000;

    public array $waits = [];

    protected function monotonic(): float
    {
        return $this->clock;
    }

    protected function wallTime(): float
    {
        return 1700000000 + $this->clock;
    }

    protected function pause(float $seconds): void
    {
        $this->waits[] = $seconds;
        $this->clock += $seconds;
    }
}
