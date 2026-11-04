<?php

namespace Tests\Unit\Legal;

use App\Services\Legal\DocumentStructureParser;
use App\Services\Legal\LegalDocumentChunker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LegalDocumentChunkerTest extends TestCase
{
    private function chunker(): LegalDocumentChunker
    {
        return new LegalDocumentChunker(new DocumentStructureParser);
    }

    public static function scripts(): array
    {
        return [
            'Latin' => ["# Qonun\n\n## I BOʻLIM. Qoidalar\n\n## 1-bob. Umumiy\n\n### 1-modda. Maqsad\n\n".str_repeat('Huquq va majburiyatlar. ', 5)."\n\n### 2-modda. Tartib\n\nMatn.\n", '1'],
            'Cyrillic' => ["# Қонун\n\n## I БЎЛИМ. Қоидалар\n\n## I боб. Умумий\n\n### 1-модда. Мақсад\n\n".str_repeat('Ҳуқуқ ва мажбуриятлар. ', 5)."\n\n### 2-модда. Тартиб\n\nМатн.\n", 'I'],
            'Russian' => ["# Закон\r\n\r\n## Раздел I. Правила\r\n\r\n## Глава I. Общее\r\n\r\n### Статья 1. Цели\r\n\r\n".str_repeat('Права и обязанности. ', 5)."\r\n\r\n### Статья 2. Порядок\r\n\r\nТекст.\r\n", 'I'],
        ];
    }

    #[DataProvider('scripts')]
    public function test_articles_keep_exact_text_and_hierarchy(string $text, string $chapter): void
    {
        $chunks = $this->chunker()->chunk($text);
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        $articles = array_values(array_filter($chunks, fn ($c) => $c['section_type'] === 'article'));
        self::assertCount(2, $articles);
        self::assertSame('1', $articles[0]['section_number']);
        self::assertSame($chapter, $articles[0]['metadata']['context']['chapter']['number']);
        self::assertSame('I', $articles[0]['metadata']['context']['section']['number']);
        foreach ($chunks as $chunk) {
            self::assertSame($chunk['content'], substr($text, $chunk['metadata']['start_byte'], $chunk['metadata']['end_byte'] - $chunk['metadata']['start_byte']));
        }
    }

    public function test_multiple_chapters_and_annex_reset_article_context(): void
    {
        $text = "I bob. Bir\n1-modda. A\nBirinchi matn.\nII bob. Ikki\n2-modda. B\nIkkinchi matn.\n1-ilova\n1. Band matni.\n";
        $chunks = $this->chunker()->chunk($text);
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        self::assertSame('II', $chunks[3]['metadata']['context']['chapter']['number']);
        self::assertNull($chunks[5]['metadata']['context']['article']);
    }

    public function test_long_article_splits_on_paragraphs_without_losing_article(): void
    {
        $text = "### 23¹-modda. Katta modda\n\n".str_repeat(str_repeat('Muhim huquqiy matn. ', 12)."\n\n", 12);
        $chunks = $this->chunker()->chunk($text, 500);
        self::assertGreaterThan(3, count($chunks));
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        foreach ($chunks as $chunk) {
            self::assertSame('23¹', $chunk['section_number']);
            self::assertSame('article', $chunk['section_type']);
            self::assertSame(0, $chunk['metadata']['overlap_bytes']);
            self::assertLessThanOrEqual(500, mb_strlen($chunk['content']));
        }
    }

    public function test_parts_points_and_subpoints_are_recorded_inside_articles(): void
    {
        $text = "1-modda. Tartib\n\n1-qism\n\n1. Birinchi band.\n\na) Kichik band.\n\n".str_repeat('Mazmun saqlanadi. ', 6);
        $chunks = $this->chunker()->chunk($text);
        self::assertCount(1, $chunks);
        self::assertSame(['part', 'point', 'subpoint'], array_column($chunks[0]['metadata']['boundaries'], 'type'));
    }

    public function test_plain_paragraph_fallback_is_deterministic(): void
    {
        $text = str_repeat(str_repeat('Huquqiy matn saqlanadi. ', 8)."\n\n", 8);
        $chunks = $this->chunker()->chunk($text, 400);
        self::assertSame($chunks, $this->chunker()->chunk($text, 400));
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        self::assertSame(['needs_review'], array_values(array_unique(array_column($chunks, 'quality_status'))));
        self::assertSame([null], array_values(array_unique(array_column($chunks, 'section_number'))));
    }

    public function test_ocr_review_does_not_invent_articles(): void
    {
        $text = "I bob\n\n1-modda. Xato OCR matni �\n\nl-m0dda. Tuzatilmasin.\n\n".str_repeat('Sifatsiz matn. ', 15);
        $chunks = $this->chunker()->chunk($text, 400, ['quality_status' => 'needs_review', 'extraction_metadata' => ['quality' => ['ocr' => true]]]);
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        self::assertSame([null], array_values(array_unique(array_column($chunks, 'section_number'))));
        self::assertSame(['needs_review'], array_values(array_unique(array_column($chunks, 'quality_status'))));
    }

    public function test_pdf_without_ocr_can_use_observed_article_headings(): void
    {
        $text = "1-modda. Aniq matn\n\n".str_repeat('Hujjatning matn qatlami. ', 8);
        $chunks = $this->chunker()->chunk($text, 4000, ['quality_status' => 'needs_review', 'extraction_metadata' => ['quality' => ['format' => 'pdf', 'ocr_pages' => [], 'page_stats' => [['ocr' => false]]]]]);
        self::assertSame('article', $chunks[0]['section_type']);
        self::assertSame('1', $chunks[0]['section_number']);
        self::assertSame('needs_review', $chunks[0]['quality_status']);
    }

    public function test_empty_and_short_text_are_explicit(): void
    {
        self::assertSame([], $this->chunker()->chunk(" \n\t"));
        $chunks = $this->chunker()->chunk('Qisqa');
        self::assertSame('needs_review', $chunks[0]['quality_status']);
        self::assertSame('Qisqa', $chunks[0]['content']);
    }

    public function test_oversized_atomic_text_and_tables_are_preserved_and_flagged(): void
    {
        foreach ([str_repeat('uzunsoʻz', 100), '| '.str_repeat('Jadval qiymati ', 100)." |\n| --- |\n"] as $text) {
            $chunks = $this->chunker()->chunk($text, 200);
            self::assertSame($text, implode('', array_column($chunks, 'content')));
            self::assertTrue($chunks[0]['metadata']['oversize']);
            self::assertSame('needs_review', $chunks[0]['quality_status']);
        }
    }

    public function test_named_document_parts_and_annex_reset_preserve_exact_text(): void
    {
        $text = "# Kodeks\n\n## UMUMIY QISM\n\n## I BOʻLIM. Umumiy\n\n## 1-bob. Asosiy\n\n1-modda. Qoida\n\nMazmun.\n\n1-ilova\n\n1. Ilova bandi.\n";
        $chunks = $this->chunker()->chunk($text);
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        self::assertSame('document_part', $chunks[1]['section_type']);
        $annex = array_values(array_filter($chunks, fn ($c) => $c['section_type'] === 'appendix'))[0];
        self::assertNull($annex['metadata']['context']['section']);
        self::assertNull($annex['metadata']['context']['chapter']);
        self::assertNull($annex['metadata']['context']['document_part']);
    }

    public function test_very_large_article_preserves_every_byte_with_bounded_chunks(): void
    {
        $text = "1-modda. Katta\n\n".str_repeat(str_repeat('Huquq va majburiyat. ', 20)."\n\n", 5000);
        $chunks = $this->chunker()->chunk($text);
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        self::assertGreaterThan(500, count($chunks));
        self::assertLessThanOrEqual(4000, max(array_column(array_column($chunks, 'metadata'), 'characters')));
    }

    public function test_configured_maximum_flags_an_atomic_chunk_without_truncation(): void
    {
        $text = "1-modda. Qoida\n\n".str_repeat('Matn ', 300);
        $chunks = $this->chunker()->chunk($text, 4000, policy: ['max_chunk_size' => 1000]);
        self::assertSame($text, implode('', array_column($chunks, 'content')));
        self::assertContains('maximum_length_exceeded', $chunks[0]['metadata']['review_reasons']);
        self::assertSame('needs_review', $chunks[0]['quality_status']);
    }

    public function test_invalid_utf8_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chunker()->chunk("\xffinvalid");
    }
}
