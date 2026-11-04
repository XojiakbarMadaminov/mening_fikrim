<?php

namespace App\Services\Scraping;

use RuntimeException;

class ScrapingDependencyException extends RuntimeException
{
    public function __construct(public readonly array $issues)
    {
        parent::__construct('OCR_DEPENDENCY_MISSING: '.implode(', ', $issues));
    }
}
