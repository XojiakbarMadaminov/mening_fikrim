<?php

namespace App\Scraping\LexUz;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PdfTextExtractor
{
    private float $deadline;

    public function __construct(private PdfQualityInspector $inspector) {}

    public function extract(string $bytes, string $contentType, string $declaredLanguage, array $criticalTokens = []): array
    {
        if (! str_starts_with($bytes, '%PDF-') || ! str_contains(strtolower($contentType), 'application/pdf')) {
            throw new SourceReviewException('PDF Content-Type or magic header is invalid.');
        }
        if (strlen($bytes) > config('scraping.max_response_bytes')) {
            throw new SourceReviewException('PDF byte budget exceeded.');
        }
        $extractStarted = hrtime(true);
        $ocrSeconds = 0.0;
        $this->deadline = microtime(true) + config('scraping.parse_timeout');
        $base = config('scraping.temp_directory');
        File::ensureDirectoryExists($base, 0700);
        foreach (glob($base.'/pdf-*', GLOB_ONLYDIR) ?: [] as $stale) {
            if (preg_match('/^pdf-[a-f0-9]{24}$/D', basename($stale)) && filemtime($stale) < time() - 3600) {
                File::deleteDirectory($stale);
            }
        }
        $directory = $base.'/pdf-'.bin2hex(random_bytes(12));
        File::makeDirectory($directory, 0700);
        try {
            file_put_contents($directory.'/document.pdf', $bytes);
            $info = $this->run([config('scraping.pdfinfo_binary'), 'document.pdf'], $directory);
            if (! preg_match('/^Pages:\s+(\d+)/m', $info, $match)) {
                throw new SourceReviewException('PDF page count cannot be determined.');
            }
            $pages = (int) $match[1];
            if ($pages < 1 || $pages > config('scraping.max_pdf_pages')) {
                throw new SourceReviewException('PDF page budget exceeded.');
            }
            $this->run([config('scraping.pdftotext_binary'), '-layout', '-enc', 'UTF-8', 'document.pdf', 'layer.txt'], $directory);
            $this->run([config('scraping.pdftohtml_binary'), '-xml', '-hidden', '-zoom', '1', '-enc', 'UTF-8', 'document.pdf', 'geometry.xml'], $directory);
            $layer = explode("\f", file_get_contents($directory.'/layer.txt'));
            if (count($layer) === $pages + 1 && trim(end($layer)) === '') {
                array_pop($layer);
            }
            $xml = new DOMDocument;
            $oldErrors = libxml_use_internal_errors(true);
            try {
                $loaded = $xml->load($directory.'/geometry.xml', LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($oldErrors);
            }
            if (! $loaded) {
                throw new SourceReviewException('Poppler geometry extraction failed.');
            }
            $geometry = (new DOMXPath($xml))->query('//page');
            if (count($layer) !== $pages || $geometry->count() !== $pages) {
                throw new SourceReviewException('PDF extracted page count differs from the official file page count.');
            }
            $texts = [];
            $stats = [];
            $ocrPages = [];
            $issues = ['pdf_structure_unverified', 'pdf_language_coverage_unconfirmed'];
            for ($page = 1; $page <= $pages; $page++) {
                $metrics = $this->geometry($geometry->item($page - 1));
                $needsOcr = $metrics['image_coverage'] > 0.6 && ($metrics['body_chars'] < 100 || $metrics['text_vertical_coverage'] < 0.35);
                if (mb_strlen(trim($layer[$page - 1])) < 100 && $metrics['image_coverage'] > 0) {
                    $needsOcr = true;
                }
                $text = trim($layer[$page - 1]);
                $ocr = null;
                if ($needsOcr) {
                    if (count($ocrPages) >= config('scraping.max_ocr_pages')) {
                        throw new SourceReviewException('OCR page budget exceeded; partial extraction is not admitted.');
                    }
                    $ocr = $this->ocr($directory, $page, $declaredLanguage);
                    $text = $ocr['text'];
                    $ocrSeconds += $ocr['seconds'];
                    unset($ocr['seconds']);
                    $ocrPages[] = $page;
                    $issues[] = 'uncertified_ocr';
                    if ($ocr['mean_confidence'] < 90 || $ocr['low_confidence_fraction'] > 0.05) {
                        $issues[] = 'low_ocr_confidence';
                    }
                } elseif ($metrics['image_coverage'] > 0.6) {
                    $issues[] = 'image_backed_text_review';
                }
                if ($text === '') {
                    $issues[] = 'empty_pdf_page';
                }
                $texts[] = $text;
                $stats[] = ['page' => $page, 'chars' => mb_strlen($text), 'ocr' => $needsOcr, ...$metrics, 'layout' => $this->inspector->page($geometry->item($page - 1)), 'confidence' => $ocr === null ? null : array_diff_key($ocr, ['text' => true])];
            }
            $layout = null;
            if (config('scraping.pdf_layout_enabled')) {
                $layout = json_decode($this->run([config('scraping.python_binary'), __DIR__.'/inspect_pdf.py', 'document.pdf'], $directory), true, flags: JSON_THROW_ON_ERROR);
                if (count($layout['pages']) !== $pages) {
                    throw new SourceReviewException('Optional layout diagnostic page accounting mismatch.');
                }
            }
            $body = implode("\n\n", $texts);
            $script = $this->language($body, $declaredLanguage);
            if ($script !== $declaredLanguage) {
                $issues[] = 'declared_language_script_mismatch';
            }
            $critical = [];
            foreach ($criticalTokens as $name => $token) {
                if (! is_string($token) || trim($token) === '') {
                    continue;
                }
                $needle = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($token));
                $haystack = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($body));
                $critical[$name] = ['expected' => $token, 'found' => str_contains($haystack, $needle)];
                if (! $critical[$name]['found'] && $ocrPages !== []) {
                    $issues[] = 'ocr_critical_tokens_unverified';
                }
            }
            if ($ocrPages !== []) {
                $issues[] = 'ocr_source_identifier_not_certified';
            }
            if (array_any($stats, fn ($page) => $page['image_coverage'] > 0)) {
                $issues[] = 'pdf_image_semantics_unverified';
            }
            $issues[] = 'pdf_reading_order_unverified';
            $issues = array_values(array_unique($issues));
            sort($issues);
            $markdown = '';
            foreach ($texts as $i => $text) {
                $markdown .= '<!-- page '.($i + 1).' -->'."\n\n".$this->headings($text)."\n\n";
            }

            return ['content' => trim($markdown)."\n", 'language' => $script, 'quality' => [
                'format' => $ocrPages === [] ? 'pdf' : 'ocr', 'complete' => ! in_array('empty_pdf_page', $issues, true),
                'structure_verified' => false, 'document_certified' => false, 'issues' => $issues,
                'pages_expected' => $pages, 'pages_extracted' => count($texts), 'ocr_pages' => $ocrPages,
                'page_stats' => $stats, 'critical_tokens' => $critical,
                'validation_levels' => ['bytes_valid' => true, 'page_accounting_valid' => true, 'text_layer_extraction_valid' => $ocrPages === [] && ! in_array('empty_pdf_page', $issues, true), 'text_extraction_nonempty' => ! in_array('empty_pdf_page', $issues, true), 'reading_order_verified' => false, 'table_section_structure_verified' => false, 'language_coverage_verified' => false, 'ocr_accuracy_verified' => $ocrPages === [] ? null : false],
                'layout_diagnostics' => $layout,
                'provenance' => ['parser' => 'P3-poppler-tesseract', 'source_sha256' => hash('sha256', $bytes), 'markdown_sha256' => hash('sha256', trim($markdown)."\n"), 'physical_language' => $script, 'declared_language' => $declaredLanguage, 'raster_max_pixels' => 2400, 'ocr_dpi' => 200],
                'timings' => ['pdf_parse_seconds' => round((hrtime(true) - $extractStarted) / 1e9, 6), 'ocr_seconds' => round($ocrSeconds, 6)],
                'source_id_check' => 'Source URL/card identity is checked separately; OCR cannot certify the source identifier.',
            ]];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** Page geometry guard also catches a long header over an image-backed body. */
    public function geometry(DOMElement $page): array
    {
        $width = (float) $page->getAttribute('width');
        $height = (float) $page->getAttribute('height');
        if ($width <= 0 || $height <= 0) {
            throw new SourceReviewException('Invalid PDF page geometry.');
        }
        $rectangles = [];
        foreach ($page->getElementsByTagName('image') as $image) {
            $left = max(0, (float) $image->getAttribute('left'));
            $top = max(0, (float) $image->getAttribute('top'));
            $rectangles[] = [$left, $top, min($width, $left + (float) $image->getAttribute('width')), min($height, $top + (float) $image->getAttribute('height'))];
        }
        $bodyChars = 0;
        $min = $height;
        $max = 0;
        foreach ($page->getElementsByTagName('text') as $text) {
            $top = (float) $text->getAttribute('top');
            $bottom = $top + (float) $text->getAttribute('height');
            if (trim($text->textContent) !== '') {
                $min = min($min, $top);
                $max = max($max, $bottom);
            }
            if ($top > $height * 0.08 && $bottom < $height * 0.92) {
                $bodyChars += mb_strlen(trim($text->textContent));
            }
        }

        return ['image_coverage' => round($this->rectangleArea($rectangles) / ($width * $height), 5), 'body_chars' => $bodyChars, 'text_vertical_coverage' => round(max(0, $max - $min) / $height, 5)];
    }

    private function rectangleArea(array $rectangles): float
    {
        $xs = [];
        foreach ($rectangles as $rectangle) {
            $xs[] = $rectangle[0];
            $xs[] = $rectangle[2];
        }
        $xs = array_values(array_unique($xs));
        sort($xs);
        $area = 0;
        for ($i = 1; $i < count($xs); $i++) {
            $ys = [];
            foreach ($rectangles as [$x1, $y1, $x2, $y2]) {
                if ($x1 < $xs[$i] && $x2 > $xs[$i - 1] && $y2 > $y1) {
                    $ys[] = [$y1, $y2];
                }
            }
            sort($ys);
            $length = 0;
            $end = 0;
            foreach ($ys as [$a, $b]) {
                $length += max(0, $b - max($a, $end));
                $end = max($end, $b);
            }
            $area += ($xs[$i] - $xs[$i - 1]) * $length;
        }

        return $area;
    }

    private function ocr(string $directory, int $page, string $language): array
    {
        $ocrStarted = hrtime(true);
        $prefix = 'page-'.$page;
        $this->run([config('scraping.pdftoppm_binary'), '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '2400', '-png', 'document.pdf', $prefix], $directory);
        $model = match ($language) {
            'ru' => 'rus', 'en' => 'eng', default => 'uzb_cyrl+uzb'
        };
        $this->run([config('scraping.tesseract_binary'), $prefix.'.png', $prefix, '-l', $model, '--dpi', '200', 'txt', 'tsv'], $directory, config('scraping.ocr_page_timeout'));
        $text = trim(file_get_contents($directory.'/'.$prefix.'.txt'));
        $confidences = [];
        $lines = [];
        $suspiciousBoxes = 0;
        foreach (file($directory.'/'.$prefix.'.tsv', FILE_IGNORE_NEW_LINES) as $i => $line) {
            $row = explode("\t", $line, 12);
            if ($i > 0 && count($row) === 12 && (float) $row[10] >= 0 && trim($row[11]) !== '') {
                $confidences[] = (float) $row[10];
                $lines[implode(':', array_slice($row, 1, 4))] = true;
                $suspiciousBoxes += (float) $row[9] > 200 || (float) $row[8] > 1800 ? 1 : 0;
            }
        }
        $mean = $confidences === [] ? 0 : array_sum($confidences) / count($confidences);
        $low = $confidences === [] ? 1 : count(array_filter($confidences, fn ($c) => $c < 70)) / count($confidences);
        @unlink($directory.'/'.$prefix.'.png');

        return ['text' => $text, 'seconds' => (hrtime(true) - $ocrStarted) / 1e9, 'line_count' => count($lines), 'suspicious_word_boxes' => $suspiciousBoxes, 'review_regions' => $this->inspector->ocrRegions(file_get_contents($directory.'/'.$prefix.'.tsv')), 'tsv_sha256' => hash_file('sha256', $directory.'/'.$prefix.'.tsv'), 'mean_confidence' => round($mean, 4), 'low_confidence_fraction' => round($low, 5), 'words' => count($confidences), 'model' => $model];
    }

    private function run(array $command, string $directory, ?int $timeout = null): string
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new SourceReviewException('PDF parsing total time budget exhausted.');
        }
        $environment = ['OMP_THREAD_LIMIT' => '1'];
        if (config('scraping.tessdata_prefix')) {
            $environment['TESSDATA_PREFIX'] = config('scraping.tessdata_prefix');
        }
        if (config('scraping.process_library_path')) {
            $environment['LD_LIBRARY_PATH'] = config('scraping.process_library_path');
        }
        $process = new Process($command, $directory, $environment, timeout: min($remaining, $timeout ?? config('scraping.pdf_timeout')));
        $process->start();
        try {
            while ($process->isRunning()) {
                $process->checkTimeout();
                $bytes = 0;
                foreach (File::allFiles($directory) as $file) {
                    $bytes += $file->getSize();
                }
                if ($bytes > config('scraping.max_temp_bytes')) {
                    throw new SourceReviewException('PDF/OCR temporary disk budget exceeded.');
                }
                usleep(100000);
            }
            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        return $process->getOutput();
    }

    private function language(string $text, string $declared): string
    {
        if (! str_starts_with($declared, 'uz-')) {
            return $declared;
        }
        preg_match_all('/\p{Cyrillic}/u', $text, $cyrl);
        preg_match_all('/\p{Latin}/u', $text, $latn);

        return count($cyrl[0]) > count($latn[0]) ? 'uz-Cyrl' : 'uz-Latn';
    }

    private function headings(string $text): string
    {
        $text = preg_replace('/^\s*(\d+[.\-– ]*\s*(?:bob|боб|ilova|илова)\b[^\n]*)$/imu', '## $1', $text);

        return preg_replace('/^\s*(\d+[.\-– ]*\s*(?:modda|модда)\b[^\n]*)$/imu', '### $1', $text);
    }
}
