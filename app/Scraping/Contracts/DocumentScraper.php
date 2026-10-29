<?php

namespace App\Scraping\Contracts;

interface DocumentScraper
{
    public function fetch(string $externalId, ?string $listingStatus = null): array;

    /** The callback commits page work before the next page is fetched. False stops at the bounded run limit. */
    public function discover(array $segment, callable $onPage, int $pageBudget): array;
}
