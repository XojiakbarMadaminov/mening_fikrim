<?php

namespace Tests\Unit\Chunking;

use App\Services\Chunking\BoundedTextSplitter;
use App\Services\Chunking\ChunkingService;
use App\Services\Chunking\ChunkQualityAnalyzer;
use App\Services\Chunking\EmbeddingTextBuilder;
use App\Services\Chunking\LegalChunkingStrategy;
use App\Services\Chunking\TextChunkingStrategy;
use App\Services\Legal\DocumentStructureParser;
use App\Services\Legal\LegalDocumentChunker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UniversalChunkingTest extends TestCase
{
    private function engine(): ChunkingService
    {
        return new ChunkingService(new LegalChunkingStrategy(new LegalDocumentChunker(new DocumentStructureParser)), new TextChunkingStrategy,
            new BoundedTextSplitter, new ChunkQualityAnalyzer, new EmbeddingTextBuilder);
    }

    private function policy(): array
    {
        return ['retrieval_version' => 'legal-v3', 'passage_target_chars' => 300, 'passage_max_chars' => 350, 'context_max_chars' => 120, 'embedding_max_chars' => 500, 'short_merge_chars' => 80];
    }

    private function source(): array
    {
        return ['document_id' => 10, 'document_content_id' => 20, 'title' => 'Observed document title', 'language' => 'uz-Latn', 'source_checksum' => str_repeat('a', 64),
            'source_url' => 'https://example.test/source', 'quality_status' => 'verified', 'quality_issues' => [], 'extraction_metadata' => ['quality' => ['format' => 'html']]];
    }

    public static function inputs(): array
    {
        return [
            'Latin sentence' => [str_repeat('Oʻzbekcha huquq va majburiyat ', 300)],
            'Cyrillic paragraph' => [str_repeat('Ўзбекча ҳуқуқ ва мажбуриятлар. ', 300)],
            'Russian' => [str_repeat('Права и обязанности граждан. ', 300)],
            'No whitespace' => [str_repeat('я', 2000)],
            'Unicode mixed' => [str_repeat("á😀Ўʻ\r\n", 400)],
            'Large table' => ["| № | Matn |\n|---|---|\n".str_repeat('| 12 | '.str_repeat('qiymat ', 50)." |\n", 500)],
        ];
    }

    #[DataProvider('inputs')]
    public function test_bounded_fallback_is_exact_utf8_and_deterministic(string $text): void
    {
        $rows = $this->engine()->build('text', $text, [], $this->source(), $this->policy());
        self::assertSame($text, implode('', array_column($rows, 'body')));
        self::assertSame($rows, $this->engine()->build('text', $text, [], $this->source(), $this->policy()));
        $byte = 0;
        $char = 0;
        foreach ($rows as $row) {
            self::assertTrue(mb_check_encoding($row['body'], 'UTF-8'));
            self::assertLessThanOrEqual(350, mb_strlen($row['body']));
            self::assertSame($byte, $row['start_byte']);
            self::assertSame($char, $row['start_char']);
            $byte += strlen($row['body']);
            $char += mb_strlen($row['body']);
            self::assertSame($byte, $row['end_byte']);
            self::assertSame($char, $row['end_char']);
            $built = (new EmbeddingTextBuilder)->build($row['body'], $row['metadata'], $this->policy());
            self::assertLessThanOrEqual(500, $built['characters']);
            self::assertSame($row['body'], mb_substr($built['text'], $built['body_start_char']));
        }
    }

    public function test_short_headings_join_only_their_child_article_and_never_another_article(): void
    {
        $text = "# Qonun\n\n## I bob\n\n1-modda. Bir\n\nQisqa mazmun.\n\n2-modda. Ikki\n\nIkkinchi mazmun.\n";
        $rows = $this->engine()->build('legal', $text, [], $this->source(), $this->policy());
        self::assertSame($text, implode('', array_column($rows, 'body')));
        self::assertCount(2, $rows);
        self::assertStringContainsString('1-modda', $rows[0]['body']);
        self::assertStringNotContainsString('2-modda', $rows[0]['body']);
        self::assertSame('1', $rows[0]['metadata']['context']['article']['number']);
        self::assertSame('2', $rows[1]['metadata']['context']['article']['number']);
    }

    public function test_plain_verified_text_is_not_mislabeled_bad_extraction(): void
    {
        $rows = $this->engine()->build('text', str_repeat('Plain usable paragraph. ', 20), [], $this->source(), $this->policy());
        self::assertSame('verified', $rows[0]['metadata']['quality']['source_extraction']['status']);
        self::assertSame('unrecognized', $rows[0]['metadata']['quality']['structure']);
        self::assertSame('eligible', $rows[0]['suitability']);
        self::assertSame('not_reviewed', $rows[0]['metadata']['quality']['human_review']);
    }

    public function test_ocr_review_and_semantic_cuts_are_not_promoted(): void
    {
        $source = $this->source();
        $source['quality_status'] = 'needs_review';
        $source['extraction_metadata']['quality']['format'] = 'ocr';
        $rows = $this->engine()->build('legal', str_repeat('ХатоларборOCR', 200), [], $source, $this->policy());
        self::assertSame(['review'], array_values(array_unique(array_column($rows, 'suitability'))));
        self::assertContains('ocr_uncertified', $rows[0]['metadata']['quality']['reasons']);
        self::assertSame('needs_review', $rows[0]['metadata']['quality']['source_extraction']['status']);
        self::assertTrue($rows[0]['metadata']['semantic_fragment']);
    }

    public function test_input_context_is_bounded_known_metadata_and_model_count_is_explicitly_approximate(): void
    {
        $builder = new EmbeddingTextBuilder;
        $meta = ['document_title' => str_repeat('Known title ', 100), 'context' => ['article' => ['title' => 'Observed article']],
            'document_id' => 10, 'document_content_id' => 20, 'language' => 'ru', 'source_checksum' => str_repeat('a', 64), 'profile_version' => 'legal-v3', 'lineage' => [['source_chunk_id' => 3, 'start_byte' => 0, 'end_byte' => 6]]];
        $a = $builder->build('Текст.', $meta, $this->policy());
        $b = $builder->build('Текст.', $meta, $this->policy());
        self::assertSame($a, $b);
        self::assertTrue($a['context_truncated']);
        self::assertSame(20, $a['source_content_id']);
        self::assertSame('approximate_chars_div_4_no_model_tokenizer', $a['token_count_method']);
        self::assertLessThanOrEqual(500, $a['characters']);
        self::assertStringNotContainsString('fabricated',$a['text']);
    }

    public function test_unknown_format_requires_a_strategy_instead_of_fake_support(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->build('transcript','text',[],$this->source(),$this->policy());
    }
}
