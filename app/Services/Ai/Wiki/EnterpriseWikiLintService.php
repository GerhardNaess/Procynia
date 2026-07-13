<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;

class EnterpriseWikiLintService
{
    /**
     * Run lint for all pages and documents of a customer.
     *
     * @return array{opened: int, resolved: int}
     */
    public function lintCustomer(int $customerId): array
    {
        $opened = 0;
        $resolved = 0;

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->get();

        foreach ($pages as $page) {
            $result = $this->lintPage($page);
            $opened += $result['opened'];
            $resolved += $result['resolved'];
        }

        $result = $this->lintDocuments($customerId);
        $opened += $result['opened'];
        $resolved += $result['resolved'];

        return compact('opened', 'resolved');
    }

    /**
     * Run lint for a single wiki page (claims + source references).
     *
     * @return array{opened: int, resolved: int}
     */
    public function lintPage(EnterpriseWikiPage $page): array
    {
        $customerId = $page->customer_id;
        $pageId = $page->id;
        $touchedIds = [];
        $opened = 0;

        $isPublished = in_array($page->status, [
            EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            EnterpriseWikiPage::STATUS_APPROVED,
        ], true);

        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->with('sourceReferences')
            ->get();

        foreach ($claims as $claim) {
            // Rule: claim_missing_source — suppressed when the claim has either a real source
            // reference or a manual System Owner approval; see
            // EnterpriseWikiClaim::needsSourceWarning().
            if ($claim->needsSourceWarning()) {
                [$finding, $isOpened] = $this->upsertFinding(
                    [
                        'customer_id' => $customerId,
                        'enterprise_wiki_page_id' => $pageId,
                        'enterprise_wiki_claim_id' => $claim->id,
                        'enterprise_wiki_document_id' => null,
                        'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
                    ],
                    $isPublished
                        ? EnterpriseWikiLintFinding::SEVERITY_ERROR
                        : EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'Påstanden mangler kildereferanse.',
                );
                $touchedIds[] = $finding->id;
                if ($isOpened) {
                    $opened++;
                }
            }

            // Rule: source_reference_missing_excerpt
            $hasMissingExcerpt = $claim->sourceReferences
                ->contains(fn ($ref) => $ref->excerpt === null || $ref->excerpt === '');

            if ($hasMissingExcerpt) {
                [$finding, $isOpened] = $this->upsertFinding(
                    [
                        'customer_id' => $customerId,
                        'enterprise_wiki_page_id' => $pageId,
                        'enterprise_wiki_claim_id' => $claim->id,
                        'enterprise_wiki_document_id' => null,
                        'code' => EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
                    ],
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'Kildereferanse mangler tekstutdrag (excerpt).',
                );
                $touchedIds[] = $finding->id;
                if ($isOpened) {
                    $opened++;
                }
            }
        }

        $resolved = $this->closeStaleOpenFindings(
            ['customer_id' => $customerId, 'enterprise_wiki_page_id' => $pageId],
            $touchedIds,
        );

        return compact('opened', 'resolved');
    }

    /**
     * Run lint for all documents of a customer (ingest failure checks).
     *
     * @return array{opened: int, resolved: int}
     */
    public function lintDocuments(int $customerId): array
    {
        $touchedIds = [];
        $opened = 0;
        $resolved = 0;

        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->get();

        if ($documents->isEmpty()) {
            return compact('opened', 'resolved');
        }

        $latestRuns = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('source_id', $documents->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('source_id')
            ->map(fn ($group) => $group->first());

        foreach ($documents as $document) {
            $latestRun = $latestRuns->get($document->id);

            // Rule: document_ingest_failed
            if ($latestRun && $latestRun->status === EnterpriseWikiIngestRun::STATUS_FAILED) {
                [$finding, $isOpened] = $this->upsertFinding(
                    [
                        'customer_id' => $customerId,
                        'enterprise_wiki_page_id' => null,
                        'enterprise_wiki_claim_id' => null,
                        'enterprise_wiki_document_id' => $document->id,
                        'code' => EnterpriseWikiLintFinding::CODE_DOCUMENT_INGEST_FAILED,
                    ],
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'Siste ingest-kjøring for kildedokumentet feilet.',
                );
                $touchedIds[] = $finding->id;
                if ($isOpened) {
                    $opened++;
                }
            }
        }

        $resolved = $this->closeStaleOpenFindings(
            ['customer_id' => $customerId, 'enterprise_wiki_page_id' => null],
            $touchedIds,
        );

        return compact('opened', 'resolved');
    }

    /**
     * Upsert a lint finding by its unique key.
     *
     * Returns [finding, isOpened] where isOpened is true when the finding was
     * newly created or transitioned from resolved → open.
     *
     * @param  array<string, mixed>  $key
     * @return array{0: EnterpriseWikiLintFinding, 1: bool}
     */
    private function upsertFinding(array $key, string $severity, string $message): array
    {
        $query = EnterpriseWikiLintFinding::query();
        foreach ($key as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        /** @var EnterpriseWikiLintFinding|null $existing */
        $existing = $query->first();

        if ($existing !== null) {
            $wasResolved = $existing->status === EnterpriseWikiLintFinding::STATUS_RESOLVED;
            $existing->update([
                'severity' => $severity,
                'message' => $message,
                'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
                'detected_at' => now(),
                'resolved_at' => null,
            ]);

            return [$existing, $wasResolved];
        }

        $created = EnterpriseWikiLintFinding::query()->create(array_merge($key, [
            'severity' => $severity,
            'message' => $message,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]));

        return [$created, true];
    }

    /**
     * Close all open findings matching $conditions that are not in $keepIds.
     *
     * @param  array<string, mixed>  $conditions
     * @param  list<int>  $keepIds
     */
    private function closeStaleOpenFindings(array $conditions, array $keepIds): int
    {
        $query = EnterpriseWikiLintFinding::query()
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN);

        foreach ($conditions as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        if (! empty($keepIds)) {
            $query->whereNotIn('id', $keepIds);
        }

        return $query->update([
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }
}
