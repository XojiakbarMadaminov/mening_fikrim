<?php

namespace App\Services\Scraping;

use App\Scraping\LexUz\LexHttpClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

/** Deliberately has no transport fallback: the existing parser can only read this verified map. */
class ArchivedLexHttpClient extends LexHttpClient
{
    public function __construct(private array $responses)
    {
        parent::__construct();
    }

    public function get(string $url, bool $pdf = false): Response
    {
        $url = $this->absolute($url);
        if (! isset($this->responses[$url])) {
            throw new ArchiveException('archive_required_source_missing');
        }
        $source = $this->responses[$url];

        return new Response(new PsrResponse(200, ['Content-Type' => $source['content_type']], $source['bytes']));
    }

    public function post(string $url, array $form): Response
    {
        throw new ArchiveException('archive_network_access_forbidden');
    }
}
