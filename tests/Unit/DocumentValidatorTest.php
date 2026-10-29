<?php

namespace Tests\Unit;

use App\Enums\DocumentStatus;
use App\Services\Documents\DocumentValidator;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class DocumentValidatorTest extends TestCase
{
    public function test_all_four_saved_identity_graphs_are_verified_without_fetching(): void
    {
        $validator = new DocumentValidator;
        $nodes = 0;
        foreach (['8506802', '8554309', '-7655343', '-8540314'] as $id) {
            $input = FixtureInputs::seed($id);
            $nodes += count($validator->identity($input['identity'], $id));
        }
        $this->assertSame(15, $nodes);
    }

    public function test_a_one_way_link_is_not_identity_proof(): void
    {
        $input = FixtureInputs::seed();
        array_pop($input['identity'][0]['links']);
        $this->expectException(ValidationException::class);
        (new DocumentValidator)->identity($input['identity'], $input['entry_id']);
    }

    public function test_matching_requisites_titles_and_hashes_without_links_do_not_merge(): void
    {
        $input = FixtureInputs::seed();
        foreach ($input['identity'] as &$node) {
            $node['links'] = [];
        }
        $this->expectException(ValidationException::class);
        (new DocumentValidator)->identity($input['identity'], $input['entry_id']);
    }

    public function test_conflicting_requisites_require_review(): void
    {
        $input = FixtureInputs::seed();
        $input['identity'][0]['requisites']['bodies'][0]['number'] = 'conflict';
        $this->expectException(ValidationException::class);
        (new DocumentValidator)->identity($input['identity'], $input['entry_id']);
    }

    public function test_unknown_and_internally_conflicting_metadata_fail_closed(): void
    {
        $validator = new DocumentValidator;
        foreach ([[], ['authority' => 'source_metadata', 'state' => 'unknown'], ['authority' => 'source_metadata', 'state' => 'active', 'effective_at' => null], ['authority' => 'source_metadata', 'state' => 'active', 'effective_at' => '2026-10-23', 'effective_at_candidates' => ['2026-10-23', '2027-01-01']], ['authority' => 'source_metadata', 'state' => 'active', 'effective_at' => '2026-02-30'], ['authority' => 'source_metadata', 'state' => 'active', 'effective_at' => '2026-10-23', 'listing_state' => 'revoked']] as $evidence) {
            try {
                $validator->status($evidence, new DateTimeImmutable('2026-10-28'));
                $this->fail('Invalid metadata accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_pending_becomes_active_at_tashkent_midnight_only_with_source_active_metadata(): void
    {
        $evidence = FixtureInputs::seed('8557098')['status_evidence'];
        $validator = new DocumentValidator;
        $this->assertSame(DocumentStatus::Pending, $validator->status($evidence, new DateTimeImmutable('2027-01-26T18:59:59Z'))['status']);
        $this->assertSame(DocumentStatus::Active, $validator->status($evidence, new DateTimeImmutable('2027-01-26T19:00:00Z'))['status']);
    }

    public function test_source_revoked_is_inactive_and_n_is_never_assumed_pending(): void
    {
        $validator = new DocumentValidator;
        $this->assertSame(DocumentStatus::Inactive, $validator->status(FixtureInputs::seed('8181977')['status_evidence'], new DateTimeImmutable('2026-10-28'))['status']);
        $this->expectException(ValidationException::class);
        $validator->status(['authority' => 'source_metadata', 'state' => 'not_in_force', 'effective_at' => '2027-01-01'], new DateTimeImmutable('2026-10-28'));
    }

    public function test_actual_metadata_pdf_mismatch_is_only_a_warning_for_status(): void
    {
        $input = FixtureInputs::seed('-8553692');
        $result = (new DocumentValidator)->status($input['status_evidence'], new DateTimeImmutable('2026-10-28'), '2027-01-01');
        $this->assertSame(DocumentStatus::Active, $result['status']);
        $this->assertSame('2026-10-23', $result['effective_at']);
        $this->assertSame('2027-01-01', $result['warnings'][0]['text_effective_at']);
    }

    public function test_actual_pdf_and_ocr_quality_reviews_are_not_overridden_by_status(): void
    {
        $validator = new DocumentValidator;
        foreach (['8557098', '-8553692', '-8553258'] as $id) {
            $input = FixtureInputs::seed($id);
            foreach ($input['contents'] as $candidate) {
                $this->assertContains('quality_review', $validator->content($candidate, null)['errors']);
            }
        }
    }

    public function test_page_mismatch_and_uncertified_ocr_fail_even_when_candidate_claims_good_quality(): void
    {
        $candidate = FixtureInputs::seed()['contents']['en'];
        $candidate['quality'] = ['complete' => true, 'structure_verified' => true, 'issues' => [], 'format' => 'ocr', 'pages_expected' => 19, 'pages_extracted' => 18];
        $errors = (new DocumentValidator)->content($candidate, null)['errors'];
        $this->assertContains('incomplete_pdf_pages', $errors);
        $this->assertContains('uncertified_ocr', $errors);
    }

    public function test_missing_optional_field_cannot_bridge_two_conflicting_publication_dates(): void
    {
        $input = FixtureInputs::seed();
        $input['identity'] = array_slice($input['identity'], 0, 3);
        [$a, $b, $c] = array_column($input['identity'], 'external_id');
        $input['identity'][0]['links'] = [$b];
        $input['identity'][1]['links'] = [$a, $c];
        $input['identity'][2]['links'] = [$b];
        $input['identity'][0]['requisites']['publication_date'] = '2026-10-01';
        $input['identity'][1]['requisites']['publication_date'] = null;
        $input['identity'][2]['requisites']['publication_date'] = '2026-10-02';
        $this->expectException(ValidationException::class);
        (new DocumentValidator)->identity($input['identity'], $a);
    }
}
