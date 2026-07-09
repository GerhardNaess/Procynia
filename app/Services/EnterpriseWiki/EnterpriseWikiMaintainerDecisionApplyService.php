<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Facades\DB;

/**
 * Applies a persisted maintainer decision to create or update stub wiki pages.
 *
 * Constraints:
 *  - No content_markdown generation; no page_versions created.
 *  - No claims or source references written.
 *  - No OpenAI calls.
 *  - Customer-scoped: page_id lookup for update is gated to run->customer_id.
 *  - Idempotency guard: throws if maintainer_decision_status is already 'applied'.
 */
class EnterpriseWikiMaintainerDecisionApplyService
{
    /**
     * @return array{created: int, updated: int}
     *
     * @throws \InvalidArgumentException
     */
    public function apply(EnterpriseWikiIngestRun $run): array
    {
        if ($run->status !== EnterpriseWikiIngestRun::STATUS_DECISION_ONLY) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has status [{$run->status}], expected [decision_only]."
            );
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has source_type [{$run->source_type}], expected [enterprise_wiki_document]."
            );
        }

        if (empty($run->maintainer_decision_json)) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has no maintainer_decision_json."
            );
        }

        if ($run->maintainer_decision_status === EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has already been applied."
            );
        }

        $decision = $run->maintainer_decision_json;
        $customerId = $run->customer_id;
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($run, $decision, $customerId, &$created, &$updated): void {
            foreach ($this->collectEntries($decision) as [$entry, $pageType]) {
                $action = $entry['action'] ?? 'create';
                $pageId = $entry['page_id'] ?? null;

                if ($action === 'update' && $pageId !== null) {
                    $page = EnterpriseWikiPage::query()
                        ->where('customer_id', $customerId)
                        ->where('id', (int) $pageId)
                        ->first();

                    if ($page === null) {
                        throw new \InvalidArgumentException(
                            "Page [{$pageId}] not found for customer [{$customerId}]."
                        );
                    }

                    EnterpriseWikiIngestRunPage::query()->create([
                        'enterprise_wiki_ingest_run_id' => $run->id,
                        'enterprise_wiki_page_id'       => $page->id,
                        'action'                        => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
                    ]);

                    $updated++;
                } else {
                    $page = EnterpriseWikiPage::query()->create([
                        'customer_id'      => $customerId,
                        'slug'             => $entry['proposed_slug'],
                        'title'            => $entry['title'],
                        'page_type'        => $pageType,
                        'status'           => EnterpriseWikiPage::STATUS_DRAFT,
                        'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                        'last_source_hash' => hash('sha256', "enterprise_wiki_document:{$run->source_id}"),
                    ]);

                    EnterpriseWikiIngestRunPage::query()->create([
                        'enterprise_wiki_ingest_run_id' => $run->id,
                        'enterprise_wiki_page_id'       => $page->id,
                        'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                    ]);

                    $created++;
                }
            }

            $run->maintainer_decision_status = EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED;
            $run->save();
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /** @return list<array{0: array<string, mixed>, 1: string}> */
    private function collectEntries(array $decision): array
    {
        $entries = [];

        if (! empty($decision['source_article'])) {
            $entries[] = [$decision['source_article'], EnterpriseWikiPage::PAGE_TYPE_ARTICLE];
        }

        if (! empty($decision['source_summary'])) {
            $entries[] = [$decision['source_summary'], EnterpriseWikiPage::PAGE_TYPE_SUMMARY];
        }

        foreach ($decision['concept_pages'] ?? [] as $entry) {
            $entries[] = [$entry, EnterpriseWikiPage::PAGE_TYPE_CONCEPT];
        }

        foreach ($decision['entity_pages'] ?? [] as $entry) {
            $entries[] = [$entry, EnterpriseWikiPage::PAGE_TYPE_ENTITY];
        }

        return $entries;
    }
}
