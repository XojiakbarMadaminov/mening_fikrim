<?php

namespace App\Scraping\LexUz;

use RuntimeException;

class LexRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
