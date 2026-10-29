<?php

namespace Tests\Feature;

use App\Scraping\LexUz\LexRequestException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class LexHttpClientTest extends TestCase
{
    use DatabaseMigrations;

    public function test_common_pacer_enforces_two_seconds_for_html_pdf_and_post(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        config(['scraping.request_delay_ms' => 0]); // A smaller configuration cannot bypass the conservative floor.
        $client = new VirtualLexHttpClient;
        $client->get('https://lex.uz/uz/docs/8506802');
        $client->get('https://lex.uz/pdffile/8557098', true);
        $client->post('https://lex.uz/uz/search/nat', ['__VIEWSTATE' => 'private-post-token']);
        $this->assertSame([2.0, 2.0], $client->waits);
        Http::assertSentCount(3);
    }

    public function test_429_retry_after_seconds_and_http_date_are_respected(): void
    {
        Http::fake(['*' => Http::sequence()->push('limited', 429, ['Retry-After' => '3'])->push('ok', 200)]);
        $client = new VirtualLexHttpClient;
        $client->get('https://lex.uz/uz/docs/8506802');
        $this->assertSame([3.0], $client->waits);
        $this->assertSame(90, $client->retryAfter(gmdate('D, d M Y H:i:s \G\M\T', 1700000000 + (int) $client->clock + 90)));
        Http::assertSentCount(2);
    }

    public function test_long_429_defers_without_sleeping_past_the_job_budget(): void
    {
        Http::fake(['*' => Http::response('limited', 429, ['Retry-After' => '3600'])]);
        $client = new VirtualLexHttpClient;
        try {
            $client->get('https://lex.uz/uz/docs/8506802');
            $this->fail('A long Retry-After must defer.');
        } catch (LexRequestException $e) {
            $this->assertSame(3600, $e->retryAfter);
        }
        $this->assertSame([], $client->waits);
        Http::assertSentCount(1);
    }

    public function test_403_stops_and_shared_cooldown_prevents_another_request(): void
    {
        Http::fake(['*' => Http::response('blocked', 403)]);
        $client = new VirtualLexHttpClient;
        for ($i = 0; $i < 2; $i++) {
            try {
                $client->get('https://lex.uz/uz/docs/8506802');
                $this->fail('403 must not pass.');
            } catch (LexRequestException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        Http::assertSentCount(1);
    }

    public function test_5xx_and_connection_failures_have_bounded_retries(): void
    {
        Http::fake(['*' => Http::sequence()->pushStatus(500)->pushStatus(503)->push('ok', 200)]);
        $client = new VirtualLexHttpClient;
        $this->assertSame('ok', $client->get('https://lex.uz/uz/docs/8506802')->body());
        $this->assertSame([2.0, 4.0], $client->waits);
        Http::assertSentCount(3);
    }

    public function test_untrusted_redirect_and_unlimited_request_budget_are_rejected(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://example.org/not-lex'])]);
        $this->expectException(LexRequestException::class);
        (new VirtualLexHttpClient)->get('https://lex.uz/uz/docs/8506802');
    }

    public function test_connection_timeout_failure_exhausts_exactly_three_attempts(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Controlled connection timeout.');
        });
        $client = new VirtualLexHttpClient;
        try {
            $client->get('https://lex.uz/uz/docs/8506802');
            $this->fail('Connection failure must exhaust the retry budget.');
        } catch (LexRequestException $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame(3, $calls);
        $this->assertSame([2.0, 4.0], $client->waits);
    }

    public function test_diagnostics_never_include_cookies_viewstate_or_secret_query_parameters(): void
    {
        $logger = \Mockery::mock();
        $records = [];
        $logger->shouldReceive('info')->once()->andReturnUsing(function ($message, $record) use (&$records) {
            $records[] = $record;
        });
        Log::shouldReceive('channel')->with('scraping')->andReturn($logger);
        Http::fake(['*' => Http::response('ok', 200, ['Set-Cookie' => 'session=do-not-log'])]);
        (new VirtualLexHttpClient)->post('https://lex.uz/uz/search/nat?token=do-not-log&status=Y', ['__VIEWSTATE' => 'private-state', 'cookie' => 'private-cookie']);
        $this->assertStringNotContainsString('do-not-log', json_encode($records));
        $this->assertStringNotContainsString('private-state', json_encode($records));
        $this->assertStringNotContainsString('private-cookie', json_encode($records));
    }

    public function test_context_request_budget_stops_before_an_extra_http_request(): void
    {
        config(['scraping.request_budget' => 1]);
        Http::fake(['*' => Http::response('ok', 200)]);
        $client = new VirtualLexHttpClient;
        $client->get('https://lex.uz/uz/docs/8506802');
        try {
            $client->get('https://lex.uz/uz/docs/8506870');
            $this->fail('Request budget must stop additional network work.');
        } catch (LexRequestException $e) {
            $this->assertStringContainsString('budget', $e->getMessage());
        }
        Http::assertSentCount(1);
    }
}
