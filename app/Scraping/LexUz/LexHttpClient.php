<?php

namespace App\Scraping\LexUz;

use App\Services\Scraping\PilotCampaign;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LexHttpClient
{
    private CookieJar $cookies;

    private string $context = 'manual';

    public function __construct()
    {
        $this->cookies = new CookieJar;
    }

    public function context(string $context): void
    {
        $this->context = $context;
    }

    public function resetSession(): void
    {
        $this->cookies = new CookieJar;
    }

    public function get(string $url, bool $pdf = false): Response
    {
        return $this->request('GET', $url, [], $pdf);
    }

    public function post(string $url, array $form): Response
    {
        return $this->request('POST', $url, $form);
    }

    public function absolute(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $absolute = str_starts_with($url, '/') ? 'https://lex.uz'.$url : $url;
        $parts = parse_url($absolute);
        if (($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'lex.uz' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new LexRequestException('Only HTTPS source-discovered lex.uz URLs are allowed.');
        }

        return $absolute;
    }

    private function request(string $method, string $url, array $form = [], bool $pdf = false, int $redirects = 0): Response
    {
        if (! config('scraping.live_enabled') && ! app()->runningUnitTests()) {
            throw new LexRequestException('Live scraping is disabled; enable SCRAPING_LIVE_ENABLED explicitly for a bounded run.');
        }
        $url = $this->absolute($url);
        $timeout = (int) config($pdf ? 'scraping.pdf_timeout' : 'scraping.http_timeout');
        $attempts = max(1, (int) config('scraping.max_retries'));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = Cache::lock('lex:http:single-request', $timeout + 30)->block($timeout + 35, function () use ($method, $url, $form, $timeout, $attempt) {
                app(PilotCampaign::class)->assertOpen();
                $cooldown = Cache::get('lex:http:cooldown');
                if ($cooldown && $cooldown['until'] > $this->wallTime()) {
                    throw new LexRequestException('Lex.uz cooldown is still active.', $cooldown['status'], (int) ceil($cooldown['until'] - $this->wallTime()));
                }
                $budgetKey = 'lex:http:budget:'.$this->context;
                $used = (int) Cache::get($budgetKey, 0);
                if ($used >= config('scraping.request_budget')) {
                    app(PilotCampaign::class)->stop('per_run_request_budget_exhausted');
                    throw new LexRequestException('Bounded run HTTP request budget exhausted.');
                }
                $pending = Http::withOptions(['cookies' => $this->cookies, 'allow_redirects' => false])
                    ->withUserAgent('MeningFikrim-P2/1.0 (bounded research; single worker)')
                    ->connectTimeout(min(10, $timeout))->timeout($timeout)->asForm();
                $last = Cache::get('lex:http:last-start');
                $interval = $this->minimumIntervalMs() / 1000;
                $elapsed = $last === null ? $interval : $this->monotonic() - $last;
                if ($elapsed < $interval) {
                    $this->pause($elapsed < 0 ? $interval : $interval - $elapsed);
                }
                $start = $this->monotonic();
                $campaign = app(PilotCampaign::class)->reserve();
                Cache::put('lex:http:last-start', $start, 86400);
                Cache::put($budgetKey, $used + 1, 86400);
                $record = ['context' => $this->context, 'method' => $method, 'url' => $this->safeUrl($url), 'attempt' => $attempt, 'start_monotonic' => $start];
                if ($campaign !== null) {
                    $record['campaign_id'] = $campaign;
                }
                try {
                    $response = $method === 'POST' ? $pending->post($url, $form) : $pending->get($url);
                    if (strlen($response->body()) > config('scraping.max_response_bytes')) {
                        throw new LexRequestException('Response exceeds the configured byte budget.');
                    }
                    $record += ['status' => $response->status(), 'bytes' => strlen($response->body()), 'sha256' => hash('sha256', $response->body()), 'content_type' => $response->header('Content-Type')];
                    app(PilotCampaign::class)->observe($response->status());
                    if (in_array($response->status(), [403, 429], true)) {
                        $delay = $response->status() === 403 ? 3600 : max(2, $this->retryAfter($response->header('Retry-After')) ?? 2);
                        Cache::put('lex:http:cooldown', ['status' => $response->status(), 'until' => $this->wallTime() + $delay], $delay + 1);
                    }

                    return $response;
                } catch (ConnectionException $e) {
                    $record['error'] = $e::class;
                    app(PilotCampaign::class)->observe(0);

                    return null;
                } finally {
                    $record['elapsed_seconds'] = round($this->monotonic() - $start, 6);
                    // No headers, cookies, credentials, hidden field values or raw response bodies.
                    Log::channel('scraping')->info('lex.request', $record);
                }
            });
            app(PilotCampaign::class)->assertOpen();
            if ($response && $response->redirect()) {
                if ($redirects >= 3 || ! $response->header('Location')) {
                    throw new LexRequestException('Invalid or excessive source redirects.', $response->status());
                }

                return $this->request('GET', $this->absolute($response->header('Location')), [], $pdf, $redirects + 1);
            }
            if ($response && $response->successful()) {
                return $response;
            }
            $status = $response?->status() ?? 0;
            if ($status === 403) {
                throw new LexRequestException('Lex.uz returned 403; automatic access retries stopped.', 403, 3600);
            }
            $retryable = $status === 0 || $status === 429 || $status >= 500;
            $delay = $status === 429 ? max(2, $this->retryAfter($response->header('Retry-After')) ?? 2) : min(30, 2 ** $attempt);
            if (! $retryable || $attempt === $attempts || $delay > config('scraping.max_inline_wait')) {
                throw new LexRequestException('Lex.uz request failed (HTTP '.$status.').', $status, $status === 429 ? $delay : null);
            }
            $this->pause($delay);
        }
        throw new LexRequestException('HTTP retry budget exhausted.');
    }

    public function retryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return ctype_digit(trim($value)) ? (int) trim($value) : (($date = strtotime($value)) === false ? null : max(0, (int) ceil($date - $this->wallTime())));
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query = array_intersect_key($query, array_flip(['status', 'from', 'to', 'from_eff', 'to_eff', 'court_form', 'pub_date', 'actnum', 'regnummj']));

        return 'https://lex.uz'.($parts['path'] ?? '/').($query === [] ? '' : '?'.http_build_query($query));
    }

    protected function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }

    /** Offline benchmark subclasses may simulate 1000 ms; production config cannot lower this floor. */
    protected function minimumIntervalMs(): int
    {
        return max(2000, (int) config('scraping.request_delay_ms'));
    }

    protected function wallTime(): float
    {
        return microtime(true);
    }

    protected function pause(float $seconds): void
    {
        usleep((int) ceil($seconds * 1e6));
    }
}
