<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiLinkAssessmentJournal;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkAssessmentRecorder;
use PHPUnit\Framework\TestCase;

/**
 * The link assessment outcome is derived from what the backend observed, never reported by AI
 * (docs/enterprise-wiki-knowledge-integration-plan.md §6.3, D6).
 */
class EnterpriseWikiLinkAssessmentRecorderTest extends TestCase
{
    public function test_linked_reads_materialized_links_from_the_persisted_blocks(): void
    {
        $journal = new EnterpriseWikiLinkAssessmentJournal;
        $journal->recordProposed(3);
        $journal->recordRejected(99, 'x', 'rejected_unknown_target');

        $assessment = (new EnterpriseWikiLinkAssessmentRecorder)->forGeneratedPage(
            $this->catalog([10, 11]),
            $journal,
            [['link_intents' => [['intent_id' => 'a', 'target_page_id' => 10, 'anchor_text' => 'A', 'reason' => 'Fordi.']]], ['link_intents' => []]],
        );

        $this->assertSame('linked', $assessment['outcome']);
        $this->assertSame([['target_page_id' => 10, 'intent_id' => 'a', 'anchor_text' => 'A', 'reason' => 'Fordi.']], $assessment['materialized']);
        $this->assertTrue($assessment['has_rejections'], 'some intents were rejected even though the page linked');
        $this->assertSame([10, 11], $assessment['offered_page_ids']);
        $this->assertSame(3, $assessment['proposed']);
    }

    public function test_no_relevant_links_when_candidates_were_offered_and_none_proposed(): void
    {
        $assessment = (new EnterpriseWikiLinkAssessmentRecorder)->forGeneratedPage($this->catalog([10]), new EnterpriseWikiLinkAssessmentJournal, [['link_intents' => []]]);

        $this->assertSame('no_relevant_links', $assessment['outcome']);
        $this->assertFalse($assessment['has_rejections']);
    }

    public function test_no_candidates_when_the_catalog_was_empty(): void
    {
        $assessment = (new EnterpriseWikiLinkAssessmentRecorder)->forGeneratedPage($this->catalog([]), new EnterpriseWikiLinkAssessmentJournal, []);

        $this->assertSame('no_candidates', $assessment['outcome']);
    }

    public function test_rejected_only_when_proposals_existed_but_none_were_materialized(): void
    {
        $journal = new EnterpriseWikiLinkAssessmentJournal;
        $journal->recordProposed(2);
        $journal->recordRejected(5, 'a', 'rejected_inactive_target');
        $journal->recordAnchorNotFound(10, 'b');

        $assessment = (new EnterpriseWikiLinkAssessmentRecorder)->forGeneratedPage($this->catalog([10]), $journal, [['link_intents' => []]]);

        $this->assertSame('rejected_only', $assessment['outcome']);
        $this->assertNotContains('rejected_only', EnterpriseWikiLinkAssessmentRecorder::INTEGRATED_OUTCOMES);
        $this->assertSame([['target_page_id' => 10, 'intent_id' => 'b']], $assessment['skipped_anchor_not_found']);
    }

    public function test_an_intent_to_a_page_outside_the_offered_catalog_is_never_counted_as_materialized(): void
    {
        $assessment = (new EnterpriseWikiLinkAssessmentRecorder)->forGeneratedPage(
            $this->catalog([10]),
            new EnterpriseWikiLinkAssessmentJournal,
            [['link_intents' => [['intent_id' => 'a', 'target_page_id' => 77, 'anchor_text' => 'A', 'reason' => '']]]],
        );

        $this->assertSame([], $assessment['materialized']);
    }

    public function test_patched_pages_record_that_their_links_were_kept_by_the_patch(): void
    {
        $this->assertSame('unchanged_by_patch', (new EnterpriseWikiLinkAssessmentRecorder)->forPatchedPage()['outcome']);
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function catalog(array $ids): array
    {
        return [
            'catalog' => array_map(fn (int $id): array => ['page_id' => $id, 'slug' => "s{$id}", 'title' => "T{$id}", 'page_type' => 'article', 'excerpt' => null, 'tier' => 'recent'], $ids),
            'tier_counts' => ['run' => 0, 'planned' => 0, 'neighbour' => 0, 'recent' => count($ids)],
            'planned_references' => ['page_ids' => [], 'rejected' => []],
        ];
    }
}
