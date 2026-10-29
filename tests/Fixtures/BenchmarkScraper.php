<?php

namespace Tests\Fixtures;

use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\HtmlMarkdownParser;
use App\Scraping\LexUz\LexHttpClient;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** Controlled synthetic identities and fake HTTP only. Never bound in the application provider. */
class BenchmarkScraper implements DocumentScraper
{
    public function discover(array $segment, callable $onPage, int $pageBudget): array
    {
        throw new \LogicException('The offline benchmark uses an explicit controlled selection.');
    }

    public function fetch(string $externalId, ?string $listingStatus = null): array
    {
        $http = app(LexHttpClient::class);
        $http->get('https://lex.uz/__offline__/document/'.$externalId);
        $html = $http->get('https://lex.uz/__offline__/content/'.$externalId)->body();
        if (getenv('P3_BENCH_ALIAS') === '1') {
            $input = FixtureInputs::seed();
            $input['entry_id'] = $externalId;

            return $input + ['diagnostics' => ['primary_format' => 'html', 'quality' => array_map(fn ($content) => $content['quality'], $input['contents'])]];
        }
        $kind = (int) substr($externalId, -1);
        if ($kind >= 4) {
            $parsed = $this->ocr();
        } else {
            $parsed = app(HtmlMarkdownParser::class)->parse($html);
        }
        $input = FixtureInputs::seed();
        $node = array_values(array_filter($input['identity'], fn ($node) => $node['language'] === 'uz-Latn'))[0];
        $node['external_id'] = $externalId;
        $node['links'] = [$externalId];
        $input['identity'] = [$node];
        $input['entry_id'] = $externalId;
        $input['title'] = 'Controlled offline benchmark '.$externalId;
        $input['contents'] = ['uz-Latn' => ['title' => $input['title'], 'content' => $parsed['content'], 'quality' => $parsed['quality']]];
        $input['metadata'] = ['benchmark' => true];
        $input['diagnostics'] = ['primary_format' => $parsed['quality']['format'], 'quality' => ['uz-Latn' => $parsed['quality']]];

        return $input;
    }

    private function ocr(): array
    {
        $directory = sys_get_temp_dir().'/p3-bench-ocr-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        $start = hrtime(true);
        try {
            $process = new Process([config('scraping.tesseract_binary'), getenv('P3_BENCH_IMAGE'), 'out', '-l', 'uzb_cyrl+uzb', '--dpi', '200', 'txt', 'tsv'], $directory,
                ['OMP_THREAD_LIMIT' => '1', 'TESSDATA_PREFIX' => config('scraping.tessdata_prefix'), 'LD_LIBRARY_PATH' => config('scraping.process_library_path')], timeout: 45);
            $process->mustRun();

            return ['content' => file_get_contents($directory.'/out.txt'), 'quality' => ['format' => 'ocr', 'complete' => true, 'structure_verified' => false,
                'pages_expected' => 1, 'pages_extracted' => 1, 'document_certified' => false, 'issues' => ['uncertified_ocr'],
                'timings' => ['ocr_seconds' => (hrtime(true) - $start) / 1e9]]];
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
