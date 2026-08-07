<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
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
        $pageId = $page instanceof EnterpriseWikiPage ? $page->id : $page;

        return DB::transaction(function () use ($pageId, $attributes): EnterpriseWikiPageVersion {
            $this->lockPage($pageId);

            $this->demoteCurrentVersion($pageId);

            return EnterpriseWikiPageVersion::query()->create(array_merge($attributes, [
                'enterprise_wiki_page_id' => $pageId,
                'version_number' => $this->nextVersionNumberLocked($pageId),
                'is_current' => true,
            ]));
        });
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
