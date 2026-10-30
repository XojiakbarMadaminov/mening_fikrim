<?php

use App\Scraping\Contracts\DocumentScraper;
use Illuminate\Console\Signals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FixtureInputs;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Isolated test database only.');
}
Http::preventStrayRequests();
Signals::resolveAvailabilityUsing(fn () => true);
config(['scraping.live_enabled' => true]);
$app->instance(DocumentScraper::class, new class implements DocumentScraper
{
    public function fetch(string $externalId, ?string $listingStatus = null): array
    {
        $input = FixtureInputs::seed();
        $node = collect($input['identity'])->first(fn ($node) => $node['availability'] === 'full_text');
        $language = $node['language'];
        $node['external_id'] = $externalId;
        $node['links'] = [$externalId];
        $node['source_url'] = 'https://lex.uz/uz/docs/'.$externalId;
        $input['entry_id'] = $externalId;
        $input['source_url'] = $node['source_url'];
        $input['identity'] = [$node];
        $input['contents'] = [$language => $input['contents'][$language]];
        $input['diagnostics'] = ['primary_format' => 'html', 'quality' => [$language => $input['contents'][$language]['quality']]];

        return $input;
    }

    public function discover(array $segment, callable $onPage, int $pageBudget): array
    {
        throw new RuntimeException('This offline worker must only consume explicit fixture selections.');
    }
});
$status = Artisan::call('documents:campaign', ['action' => 'worker', '--id' => $argv[1]]);
echo Artisan::output();
exit($status);
