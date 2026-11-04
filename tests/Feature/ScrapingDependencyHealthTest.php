<?php

namespace Tests\Feature;

use App\Services\Scraping\ScrapingDependencyException;
use App\Services\Scraping\ScrapingDependencyHealth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScrapingDependencyHealthTest extends TestCase
{
    private function health(string $languages = "eng\nrus\nuzb\nuzb_cyrl", bool $runnable = true): ScrapingDependencyHealth
    {
        foreach (['tesseract', 'pdfinfo', 'pdftotext', 'pdftohtml', 'pdftoppm', 'python'] as $name) {
            config(['scraping.'.$name.'_binary' => '/usr/bin/true']);
        }
        config(['scraping.tessdata_prefix' => null, 'scraping.process_library_path' => null, 'scraping.pdf_layout_enabled' => true]);

        return new class($languages, $runnable) extends ScrapingDependencyHealth
        {
            public function __construct(private string $languages, private bool $runnable) {}

            protected function execute(array $command): array
            {
                return ['ok' => $this->runnable, 'output' => in_array('--list-langs', $command, true) ? $this->languages : 'synthetic dependency version'];
            }
        };
    }

    public function test_ready_dependencies_and_required_language_models_pass_without_http(): void
    {
        $report = $this->health()->assertReady();
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['checks']['ocr_languages']['missing']);
        Http::assertNothingSent();
    }

    public function test_missing_executable_is_a_clear_permanent_dependency_failure(): void
    {
        $health = $this->health();
        config(['scraping.tesseract_binary' => '/nonexistent/mening-fikrim/tesseract']);
        $this->expectException(ScrapingDependencyException::class);
        $this->expectExceptionMessage('OCR_DEPENDENCY_MISSING: TESSERACT_BINARY_NOT_AVAILABLE');
        $health->assertOcr();
    }

    public function test_non_executable_path_and_failed_process_are_detected(): void
    {
        $health = $this->health(runnable: false);
        $this->assertContains('TESSERACT_BINARY_NOT_RUNNABLE', $health->report()['issues']);
        config(['scraping.tesseract_binary' => base_path('composer.json')]);
        $this->assertContains('TESSERACT_BINARY_NOT_AVAILABLE', $health->report()['issues']);
    }

    public function test_missing_model_preserves_available_languages_and_blocks_startup(): void
    {
        $health = $this->health("eng\nrus\nuzb");
        $report = $health->report();
        $this->assertFalse($report['ok']);
        $this->assertSame(['uzb_cyrl'], $report['checks']['ocr_languages']['missing']);
        $this->app->instance(ScrapingDependencyHealth::class, $health);
        $this->artisan('documents:campaign', ['action' => 'worker'])->expectsOutputToContain('OCR_DEPENDENCY_MISSING')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_optional_layout_requires_python_only_when_enabled(): void
    {
        $health = $this->health();
        config(['scraping.python_binary' => '/nonexistent/python']);
        $this->assertContains('PYTHON_BINARY_NOT_AVAILABLE', $health->report()['issues']);
        config(['scraping.pdf_layout_enabled' => false]);
        $this->assertTrue($health->report()['ok']);
        $health->assertOcr();
    }

    public function test_ephemeral_executable_is_rejected_even_when_executable(): void
    {
        $health = $this->health();
        $path = tempnam(sys_get_temp_dir(), 'ocr-health-');
        try {
            file_put_contents($path, '#!/bin/sh');
            chmod($path, 0700);
            config(['scraping.tesseract_binary' => $path]);
            $this->assertContains('EPHEMERAL_DEPENDENCY_PATH', $health->report()['issues']);
        } finally {
            unlink($path);
        }
    }
}
