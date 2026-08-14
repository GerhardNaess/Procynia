<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiPageVersionBestPracticeReviewLostException;
use App\Exceptions\EnterpriseWikiPageVersionBlockProvenanceLostException;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single, standardized write path for the critical
 * lock page -> read next version_number -> demote old current -> create new current sequence
 * every EnterpriseWikiPageVersion writer (ordinary generation, planned-section/figure/wikilink/
 * semantic repair, incremental relink, automated claim-content repair) must follow.
 *
 * Every method here opens its own short-lived transaction and takes `lockForUpdate()` on the
 * parent `enterprise_wiki_pages` row before reading/writing `enterprise_wiki_page_versions` for
 * that page — this is what makes two concurrent writers against the same page resolve into two
 * distinct, sequential version_numbers instead of racing on an unlocked `max()+1` read. Callers
 * must have already finished any AI call or other slow work before invoking these methods; no
 * lock here is ever held across anything but a handful of fast, local queries.
 *
 * A `ewpv_page_version_number_unique` and `ewpv_page_single_current_unique` partial index (see
 * the `add_authoritative_version_constraints_to_enterprise_wiki_page_versions_table` migration)
 * back this up as the last line of defense — this class prevents the race in practice, the
 * database constraint guarantees it can never silently succeed even if some future writer
 * bypasses this class.
 */
class EnterpriseWikiPageVersionWriter
{
    /**
     * Locks the page, allocates the next version_number, demotes the existing current version
     * (if any), and creates+returns the new current version — all in one transaction.
     *
     * @param  array<string, mixed>  $attributes  EnterpriseWikiPageVersion attributes other than
     *                                            enterprise_wiki_page_id, version_number, and is_current.
     */
    public function writeNewCurrentVersion(EnterpriseWikiPage|int $page, array $attributes): EnterpriseWikiPageVersion
    {
        return $this->writeNewCurrentVersionRestoringBlocks($page, $attributes, null);
    }

    /**
     * The same write, for a caller that produces MARKDOWN ONLY and reconstructs the block
     * provenance immediately afterwards (link/semantic repair, incremental relink).
     *
     * The restorer runs inside this transaction, and the block invariant is checked after it: a
     * version that still has no blocks when the superseded one had them aborts the whole write, so
     * the page keeps its previous current version intact rather than silently losing its image
     * figures, source provenance and claim anchors. Run 54 lost a required figure exactly this way
     * — the reconstruction returned `skipped_ambiguous`, and the blockless version was promoted
     * anyway.
     *
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(EnterpriseWikiPageVersion): void)|null  $restoreBlocks  Reconstructs
     *                                                                          content_blocks_json on the freshly written version. Null for an ordinary caller that
     *                                                                          already supplies its own blocks.
     *
     * @throws EnterpriseWikiPageVersionBlockProvenanceLostException
     */
    public function writeNewCurrentVersionRestoringBlocks(
        EnterpriseWikiPage|int $page,
        array $attributes,
        ?Closure $restoreBlocks,
    ): EnterpriseWikiPageVersion {
        $pageId = $page instanceof EnterpriseWikiPage ? $page->id : $page;

        return DB::transaction(function () use ($pageId, $attributes, $restoreBlocks): EnterpriseWikiPageVersion {
            $this->lockPage($pageId);

            $superseded = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $pageId)
                ->where('is_current', true)
                ->first();

            $this->demoteCurrentVersion($pageId);

            $version = EnterpriseWikiPageVersion::query()->create(array_merge(
                $this->carryForwardBestPracticeReview($attributes, $superseded),
                [
                    'enterprise_wiki_page_id' => $pageId,
                    'version_number' => $this->nextVersionNumberLocked($pageId),
                    'is_current' => true,
                ],
            ));

            if ($restoreBlocks !== null) {
                $restoreBlocks($version);
                $version->refresh();
            }

            $this->assertBlockProvenanceSurvived($pageId, $superseded, $version);
            $this->assertBestPracticeReviewSurvived($pageId, $superseded, $version);

            return $version;
        });
    }

    /**
     * The invariant: promoting a version must never leave a page with fewer than the blocks it
     * already had, from nothing.
     *
     * Deliberately narrow — it only fires when the outgoing version HAD blocks and the incoming one
     * has NONE. A page that never had blocks (a legacy version, a first write) is untouched, and a
     * caller that legitimately rewrites the block set is untouched; this is about total loss, which
     * is never a legitimate outcome of writing a new version.
     */
    private function assertBlockProvenanceSurvived(
        int $pageId,
        ?EnterpriseWikiPageVersion $superseded,
        EnterpriseWikiPageVersion $version,
    ): void {
        $supersededBlocks = $superseded !== null ? (array) ($superseded->content_blocks_json ?? []) : [];

        if ($supersededBlocks === [] || (array) ($version->content_blocks_json ?? []) !== []) {
            return;
        }

        throw new EnterpriseWikiPageVersionBlockProvenanceLostException(
            pageId: $pageId,
            supersededVersionId: (int) $superseded->id,
            supersededBlockCount: count($supersededBlocks),
            reason: 'The write has been rolled back; the page keeps its previous current version.',
        );
    }

    /**
     * A new current version inherits the superseded one's best-practice assessment unless the caller
     * brings its own.
     *
     * Every path that writes a version WITHOUT regenerating the page — link/semantic repair,
     * incremental relink, claim repair — supplies markdown only. Those rewrites do not re-assess
     * anything, so dropping the assessment there would silently turn "assessed, nothing to add"
     * back into "never assessed", which is precisely the distinction the contract exists to make.
     * Carrying it forward here rather than in each caller is the same choice
     * writeNewCurrentVersionRestoringBlocks() makes for block provenance: one choke point, so a
     * future write path cannot forget.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function carryForwardBestPracticeReview(array $attributes, ?EnterpriseWikiPageVersion $superseded): array
    {
        $incoming = $attributes['best_practice_review_json'] ?? null;

        if (is_array($incoming) && $incoming !== []) {
            return $attributes;
        }

        $inherited = $superseded !== null ? $superseded->best_practice_review_json : null;

        if (is_array($inherited) && $inherited !== []) {
            $attributes['best_practice_review_json'] = $inherited;
        }

        return $attributes;
    }

    /**
     * The invariant, mirroring assertBlockProvenanceSurvived(): a page that HAD a recorded
     * best-practice assessment must never end up with a current version that has none. Narrow by
     * design — it says nothing about a page that never had one, and nothing about a caller that
     * legitimately replaces the assessment with a new one.
     */
    private function assertBestPracticeReviewSurvived(
        int $pageId,
        ?EnterpriseWikiPageVersion $superseded,
        EnterpriseWikiPageVersion $version,
    ): void {
        $supersededReview = $superseded !== null ? (array) ($superseded->best_practice_review_json ?? []) : [];

        if ($supersededReview === [] || (array) ($version->best_practice_review_json ?? []) !== []) {
            return;
        }

        throw new EnterpriseWikiPageVersionBestPracticeReviewLostException(
            pageId: $pageId,
            supersededVersionId: (int) $superseded->id,
            supersededReviewCount: count($supersededReview),
        );
    }

    /**
     * Locks the page, allocates the next version_number, and creates+returns a new NON-current
     * (typically staged) version — no demotion, since it is not becoming the page's active
     * version yet. Still routed through the page lock so its version_number can never collide
     * with a concurrent writer's.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function writeNonCurrentVersion(EnterpriseWikiPage|int $page, array $attributes): EnterpriseWikiPageVersion
    {
        $pageId = $page instanceof EnterpriseWikiPage ? $page->id : $page;

        return DB::transaction(function () use ($pageId, $attributes): EnterpriseWikiPageVersion {
            $this->lockPage($pageId);

            return EnterpriseWikiPageVersion::query()->create(array_merge($attributes, [
                'enterprise_wiki_page_id' => $pageId,
                'version_number' => $this->nextVersionNumberLocked($pageId),
                'is_current' => $attributes['is_current'] ?? false,
            ]));
        });
    }

    /**
     * Locks the page, demotes whatever is currently marked is_current for it (if anything other
     * than $version), and promotes $version to current. Used to flip an already-created (e.g.
     * staged) version into the live version, rather than creating a new row.
     */
    public function promoteToCurrent(EnterpriseWikiPage|int $page, EnterpriseWikiPageVersion $version): EnterpriseWikiPageVersion
    {
        $pageId = $page instanceof EnterpriseWikiPage ? $page->id : $page;

        if ((int) $version->enterprise_wiki_page_id !== (int) $pageId) {
            throw new RuntimeException("Version [{$version->id}] does not belong to page [{$pageId}].");
        }

        return DB::transaction(function () use ($pageId, $version): EnterpriseWikiPageVersion {
            $this->lockPage($pageId);

            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $pageId)
                ->where('is_current', true)
                ->where('id', '!=', $version->id)
                ->update(['is_current' => false]);

            $version->forceFill(['is_current' => true])->save();

            return $version->refresh();
        });
    }

    private function lockPage(int $pageId): void
    {
        $locked = EnterpriseWikiPage::query()->whereKey($pageId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new RuntimeException("Page [{$pageId}] no longer exists.");
        }
    }

    private function demoteCurrentVersion(int $pageId): void
    {
        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }

    /**
     * Must only be called while the page row is already locked (via lockPage()) in the same
     * transaction — that lock, not this read itself, is what serializes concurrent writers.
     */
    private function nextVersionNumberLocked(int $pageId): int
    {
        return ((int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->max('version_number')) + 1;
    }
}
