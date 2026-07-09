<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;

/**
 * Builds a deterministic link graph between EnterpriseWikiPage nodes that belong
 * to the same applied maintainer decision run.
 *
 * Both directions are written as explicit rows so traversal queries are simple
 * forward lookups on from_page_id, never requiring reverse-index joins.
 *
 * Idempotent: the unique index on (customer_id, from_page_id, to_page_id, link_type)
 * prevents duplicate rows. Reruns accumulate "skipped" rather than duplicating.
 *
 * Does not call OpenAI. Does not touch claims, source references, lint, or
 * ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiBuildPageLinksService
{
    /**
     * @return array{pages_checked: int, links_created: int, links_skipped: int, missing_versions: int, failed: int}
     * @throws \InvalidArgumentException if the run is not applied
     */
    public function build(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have page links built."
            );
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $articles        = [];
        $summaries       = [];
        $concepts        = [];
        $entities        = [];
        $pagesChecked    = 0;
        $missingVersions = 0;

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            $pagesChecked++;

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            if ($version === null) {
                $missingVersions++;
            }

            $entry = ['page' => $page, 'version' => $version];

            match ($page->page_type) {
                EnterpriseWikiPage::PAGE_TYPE_ARTICLE => $articles[]  = $entry,
                EnterpriseWikiPage::PAGE_TYPE_SUMMARY => $summaries[] = $entry,
                EnterpriseWikiPage::PAGE_TYPE_CONCEPT => $concepts[]  = $entry,
                EnterpriseWikiPage::PAGE_TYPE_ENTITY  => $entities[]  = $entry,
                default                               => null,
            };
        }

        $linksCreated = 0;
        $linksSkipped = 0;

        [$c, $s] = $this->buildBidirectional(
            $run, $articles, $summaries,
            EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $articles, $concepts,
            EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT,
            EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $articles, $entities,
            EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY,
            EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $summaries, $concepts,
            EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT,
            EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $summaries, $entities,
            EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY,
            EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        return [
            'pages_checked'    => $pagesChecked,
            'links_created'    => $linksCreated,
            'links_skipped'    => $linksSkipped,
            'missing_versions' => $missingVersions,
            'failed'           => 0,
        ];
    }

    /**
     * Build forward and reverse links between every item in $fromSet and every
     * item in $toSet.
     *
     * @return array{int, int} [created, skipped]
     */
    private function buildBidirectional(
        EnterpriseWikiIngestRun $run,
        array $fromSet,
        array $toSet,
        string $forwardType,
        string $reverseType,
    ): array {
        $created = 0;
        $skipped = 0;

        foreach ($fromSet as $from) {
            foreach ($toSet as $to) {
                [$c, $s] = $this->upsertLink($run, $from, $to, $forwardType);
                $created += $c;
                $skipped += $s;

                [$c, $s] = $this->upsertLink($run, $to, $from, $reverseType);
                $created += $c;
                $skipped += $s;
            }
        }

        return [$created, $skipped];
    }

    /**
     * Create the link if it does not exist; skip silently if it does.
     *
     * @return array{int, int} [1, 0] if created, [0, 1] if already existed
     */
    private function upsertLink(
        EnterpriseWikiIngestRun $run,
        array $from,
        array $to,
        string $linkType,
    ): array {
        $link = EnterpriseWikiPageLink::firstOrCreate(
            [
                'customer_id'  => $run->customer_id,
                'from_page_id' => $from['page']->id,
                'to_page_id'   => $to['page']->id,
                'link_type'    => $linkType,
            ],
            [
                'enterprise_wiki_ingest_run_id' => $run->id,
                'from_page_version_id'          => $from['version']?->id,
                'to_page_version_id'            => $to['version']?->id,
                'source'                        => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
                'confidence'                    => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
            ]
        );

        return $link->wasRecentlyCreated ? [1, 0] : [0, 1];
    }
}
