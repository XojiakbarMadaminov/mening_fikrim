<?php

namespace App\Services\Scraping;

use App\Scraping\LexUz\LexRequestException;

class PilotStoppedException extends LexRequestException
{
    public function __construct(string $reason)
    {
        parent::__construct('Pilot stopped: '.$reason, 0, 3600);
    }
}
