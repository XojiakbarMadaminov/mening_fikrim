<?php

namespace App\Services\Scraping;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ScrapingDependencyHealth
{
    protected function execute(array $command): array
    {
        try {
            $process = new Process($command, env: array_filter([
                'LD_LIBRARY_PATH' => config('scraping.process_library_path'),
                'TESSDATA_PREFIX' => config('scraping.tessdata_prefix'), 'OMP_THREAD_LIMIT' => '1',
            ], fn ($value) => $value !== null && $value !== ''), timeout: 15);
            $process->run();

            return ['ok' => $process->isSuccessful(), 'output' => $process->getOutput().$process->getErrorOutput()];
        } catch (\Throwable) {
            return ['ok' => false, 'output' => 'Dependency process could not execute.'];
        }
    }

    private function binary(string $key, array $arguments): array
    {
        $configured = (string) config('scraping.'.$key);
        $path = str_contains($configured, DIRECTORY_SEPARATOR) ? $configured : (new ExecutableFinder)->find($configured);
        if (! $path || ! is_file($path) || ! is_executable($path)) {
            return ['ok' => false, 'path' => $configured, 'issue' => strtoupper($key).'_NOT_AVAILABLE'];
        }
        $real = realpath($path) ?: $path;
        if (str_starts_with($real, '/tmp/') || str_starts_with($real, '/var/tmp/')) {
            return ['ok' => false, 'path' => $real, 'issue' => 'EPHEMERAL_DEPENDENCY_PATH'];
        }
        $result = $this->execute([$path, ...$arguments]);

        return ['ok' => $result['ok'], 'path' => $path, 'version' => strtok(trim($result['output']), "\n"),
            'issue' => $result['ok'] ? null : strtoupper($key).'_NOT_RUNNABLE'];
    }

    private function ocr(): array
    {
        $checks = ['tesseract' => $this->binary('tesseract_binary', ['--version'])];
        foreach (['tessdata_prefix', 'process_library_path'] as $key) {
            $path = config('scraping.'.$key);
            $checks[$key] = ['ok' => ! $path || (is_dir($path) && ! str_starts_with(realpath($path) ?: $path, '/tmp/')),
                'path' => $path, 'issue' => 'INVALID_'.strtoupper($key)];
        }
        if ($checks['tesseract']['ok']) {
            $result = $this->execute([$checks['tesseract']['path'], '--list-langs']);
            $languages = array_values(array_filter(array_map('trim', explode("\n", $result['output'])), fn ($v) => preg_match('/^[a-z][a-z_]{1,20}$/D', $v)));
            $missing = array_values(array_diff(['eng', 'rus', 'uzb', 'uzb_cyrl'], $languages));
            $checks['ocr_languages'] = ['ok' => $result['ok'] && $missing === [], 'available' => $languages,
                'missing' => $missing, 'issue' => 'OCR_LANGUAGE_MISSING'];
        }

        return $checks;
    }

    public function report(): array
    {
        $checks = $this->ocr();
        foreach (['pdfinfo_binary', 'pdftotext_binary', 'pdftohtml_binary', 'pdftoppm_binary'] as $key) {
            $checks[$key] = $this->binary($key, ['-v']);
        }
        if (config('scraping.pdf_layout_enabled')) {
            $checks['python'] = $this->binary('python_binary', ['--version']);
            if ($checks['python']['ok']) {
                $result = $this->execute([$checks['python']['path'], '-c', 'import pdfplumber; print(pdfplumber.__version__)']);
                $checks['pdfplumber'] = ['ok' => $result['ok'], 'version' => trim($result['output']), 'issue' => 'PDF_LAYOUT_DEPENDENCY_MISSING'];
            }
        } else {
            $checks['pdfplumber'] = ['ok' => true, 'required' => false, 'note' => 'Native Tesseract does not require Python. Optional layout diagnostics disabled.'];
        }
        $issues = array_values(array_unique(array_map(fn ($check) => $check['issue'], array_filter($checks, fn ($check) => ! $check['ok']))));

        return ['ok' => $issues === [], 'checked_at' => now()->toAtomString(), 'checks' => $checks, 'issues' => $issues];
    }

    public function assertReady(): array
    {
        $report = $this->report();
        if (! $report['ok']) {
            throw new ScrapingDependencyException($report['issues']);
        }

        return $report;
    }

    public function assertOcr(): void
    {
        $issues = array_map(fn ($check) => $check['issue'], array_filter($this->ocr(), fn ($check) => ! $check['ok']));
        if ($issues !== []) {
            throw new ScrapingDependencyException(array_values(array_unique($issues)));
        }
    }
}
