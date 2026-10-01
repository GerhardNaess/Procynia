<?php

namespace App\Services\Quality;

use App\Jobs\Quality\ProjectQualityPageToGraph;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityPageClassification;
use App\Models\QualityRelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to the quality layer goes through here.
 *
 * The domain rules this class owns are the ones nothing else can enforce:
 *
 *  - A quality relation may only connect pages that are classified, in a pair the matrix allows.
 *    A database constraint cannot express that — the types sit in a different table from the edge.
 *  - Changing a page's classification can invalidate edges that were legal under the old type.
 *    They are removed rather than left behind, because an edge whose ends no longer match the
 *    matrix is a claim about the kvalitetssystem that is no longer true.
 *  - The graph is a projection, so it is refreshed from whatever SQL ends up holding, after the
 *    transaction commits and never inside it.
 *
 * Tenancy is a precondition here, not a check: callers resolve the customer through CustomerContext
 * and pass it in, and every page argument is verified to belong to it before anything is written.
 */
class QualityStructureService
{
    public function classify(
        int $customerId,
        EnterpriseWikiPage $page,
        string $qualityType,
        ?string $qualityCode,
        ?User $actor = null,
    ): QualityPageClassification {
        $this->assertPageBelongsToCustomer($customerId, $page, 'page_id');

        if (! in_array($qualityType, QualityPageClassification::TYPES, true)) {
            throw ValidationException::withMessages([
                'quality_type' => __('procynia.quality.errors.unknown_type'),
            ]);
        }

        $code = $qualityCode !== null ? trim($qualityCode) : null;

        [$classification, $touchedPageIds] = DB::transaction(function () use ($customerId, $page, $qualityType, $code, $actor): array {
            $classification = QualityPageClassification::query()->updateOrCreate(
                ['enterprise_wiki_page_id' => $page->id],
                [
                    'customer_id' => $customerId,
                    'quality_type' => $qualityType,
                    'quality_code' => $code === '' ? null : $code,
                    'source' => QualityPageClassification::SOURCE_MANUAL,
                    'classified_by_user_id' => $actor?->id,
                    'classified_at' => now(),
                ],
            );

            // Retyping a page can strand edges that were legal before — a process that becomes a
            // policy cannot still "use" a checklist. Dropping them keeps the stored graph a set of
            // statements that are all currently true.
            $removed = $this->removeRelationsInvalidatedBy($customerId, (int) $page->id, $qualityType);

            return [$classification, array_merge([(int) $page->id], $removed)];
        });

        $this->reproject($touchedPageIds);

        return $classification;
    }

    public function unclassify(int $customerId, QualityPageClassification $classification): void
    {
        if ((int) $classification->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'classification' => __('procynia.quality.errors.page_not_found'),
            ]);
        }

        $pageId = (int) $classification->enterprise_wiki_page_id;

        $touchedPageIds = DB::transaction(function () use ($customerId, $classification, $pageId): array {
            // An unclassified page is outside the kvalitetssystem, so it can be neither end of a
            // quality relation. Both directions go.
            $fromPageIds = QualityRelation::query()
                ->where('customer_id', $customerId)
                ->where(fn ($query) => $query->where('from_page_id', $pageId)->orWhere('to_page_id', $pageId))
                ->pluck('from_page_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            QualityRelation::query()
                ->where('customer_id', $customerId)
                ->where(fn ($query) => $query->where('from_page_id', $pageId)->orWhere('to_page_id', $pageId))
                ->delete();

            $classification->delete();

            return array_merge([$pageId], $fromPageIds);
        });

        $this->reproject($touchedPageIds);
    }

    public function relate(
        int $customerId,
        EnterpriseWikiPage $fromPage,
        EnterpriseWikiPage $toPage,
        string $relationType,
        ?User $actor = null,
    ): QualityRelation {
        $this->assertPageBelongsToCustomer($customerId, $fromPage, 'from_page_id');
        $this->assertPageBelongsToCustomer($customerId, $toPage, 'to_page_id');

        if (! in_array($relationType, QualityRelation::TYPES, true)) {
            throw ValidationException::withMessages([
                'relation_type' => __('procynia.quality.errors.unknown_relation_type'),
            ]);
        }

        if ((int) $fromPage->id === (int) $toPage->id) {
            throw ValidationException::withMessages([
                'to_page_id' => __('procynia.quality.errors.relation_self_reference'),
            ]);
        }

        $fromType = $this->classificationTypeFor($customerId, (int) $fromPage->id);
        $toType = $this->classificationTypeFor($customerId, (int) $toPage->id);

        if ($fromType === null || $toType === null) {
            throw ValidationException::withMessages([
                'relation_type' => __('procynia.quality.errors.relation_requires_classification'),
            ]);
        }

        if (
            ! in_array($fromType, QualityRelation::allowedFromTypes($relationType), true)
            || ! in_array($toType, QualityRelation::allowedToTypes($relationType), true)
        ) {
            throw ValidationException::withMessages([
                'relation_type' => __('procynia.quality.errors.relation_not_allowed'),
            ]);
        }

        $relation = QualityRelation::query()->firstOrCreate(
            [
                'customer_id' => $customerId,
                'from_page_id' => $fromPage->id,
                'to_page_id' => $toPage->id,
                'relation_type' => $relationType,
            ],
            [
                'source' => QualityRelation::SOURCE_MANUAL,
                'created_by_user_id' => $actor?->id,
            ],
        );

        $this->reproject([(int) $fromPage->id]);

        return $relation;
    }

    public function unrelate(int $customerId, QualityRelation $relation): void
    {
        if ((int) $relation->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'relation' => __('procynia.quality.errors.relation_not_found'),
            ]);
        }

        $fromPageId = (int) $relation->from_page_id;

        $relation->delete();

        $this->reproject([$fromPageId]);
    }

    /**
     * Relations that the page's new quality type makes impossible, in either direction.
     *
     * @return list<int> the from_page_id of every edge removed, so each can be reprojected
     */
    private function removeRelationsInvalidatedBy(int $customerId, int $pageId, string $qualityType): array
    {
        $removedFromPageIds = [];

        $relations = QualityRelation::query()
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->where('from_page_id', $pageId)->orWhere('to_page_id', $pageId))
            ->get();

        foreach ($relations as $relation) {
            $isOutgoing = (int) $relation->from_page_id === $pageId;

            $allowed = $isOutgoing
                ? QualityRelation::allowedFromTypes($relation->relation_type)
                : QualityRelation::allowedToTypes($relation->relation_type);

            if (in_array($qualityType, $allowed, true)) {
                continue;
            }

            $removedFromPageIds[] = (int) $relation->from_page_id;
            $relation->delete();
        }

        return $removedFromPageIds;
    }

    private function classificationTypeFor(int $customerId, int $pageId): ?string
    {
        return QualityPageClassification::query()
            ->where('customer_id', $customerId)
            ->where('enterprise_wiki_page_id', $pageId)
            ->value('quality_type');
    }

    private function assertPageBelongsToCustomer(int $customerId, EnterpriseWikiPage $page, string $field): void
    {
        if ((int) $page->customer_id === $customerId) {
            return;
        }

        // Deliberately the same message a missing page would produce: a page in another customer's
        // Wiki must not be distinguishable from one that does not exist.
        throw ValidationException::withMessages([
            $field => __('procynia.quality.errors.page_not_found'),
        ]);
    }

    /**
     * @param  list<int>  $pageIds
     */
    private function reproject(array $pageIds): void
    {
        foreach (array_values(array_unique($pageIds)) as $pageId) {
            // After commit: the job reads SQL, and queued on the sync driver it would otherwise run
            // against a transaction that has not landed yet.
            ProjectQualityPageToGraph::dispatch($pageId)->afterCommit();
        }
    }
}
