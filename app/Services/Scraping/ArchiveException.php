<?php

namespace App\Services\Scraping;

use RuntimeException;

class ArchiveException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
