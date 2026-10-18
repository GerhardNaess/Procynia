<?php

namespace Tests\Concerns;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkAssessmentRecorder;

/**
 * For fixtures that build a "finished" run by hand: records the relation step (arbeidspakke B,
 * docs/enterprise-wiki-knowledge-integration-plan.md §6.3) as done, the same way a run that went
 * through page generation and wikilink materialization records it.
 *
 * Since B, post-ingest QA escalates a run whose generated pages have no link assessment
 * (link_assessment_incomplete), so a fixture that wants a real passed/failed verdict has to mark
 * this step complete alongside generation, extraction and verification.
 */
trait MarksWikiLinkAssessmentComplete
{
    protected function markLinkAssessmentComplete(EnterpriseWikiIngestRun $run, string $outcome = EnterpriseWikiLinkAssessmentRecorder::OUTCOME_NO_RELEVANT_LINKS): void
    {
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->update([
                'link_assessment' => json_encode(['schema' => EnterpriseWikiLinkAssessmentRecorder::SCHEMA, 'outcome' => $outcome]),
                'links_assessed_at' => now(),
            ]);
    }
}
