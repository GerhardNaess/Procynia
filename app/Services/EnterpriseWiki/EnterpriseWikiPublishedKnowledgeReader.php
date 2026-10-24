<?php

namespace App\Services\EnterpriseWiki;

use App\Models\User;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;

/**
 * The customer's published Enterprise Wiki knowledge, read for the external API (WIK-01, WIK-02,
 * D18): what a reviewer approved and published, and nothing else.
 *
 * It is the Wiki's own catalog — RequirementWikiCatalogBuilder in GROUNDING_PUBLISHED_ONLY, the
 * mode tender answers ground in — so the pages it lists are exactly the pages «Spør Wiki» through
 * the API can answer from. No second index, and no reading of the working version: a page whose
 * working version is in review still shows its published version, and a page that was never
 * published is not here at all. Archived and superseded pages are retired and never listed.
 *
 * Read-only by construction. Unlike the page view in the app (WikiController::show), nothing here
 * writes — no owner-approval sync, no claim, no link.
 *
 * What a page carries is its published text and title. Not the content blocks (where the source
 * excerpts live), claims, the verification basis, source documents, runs, owners or reviewers.
 */
class EnterpriseWikiPublishedKnowledgeReader
{
    public function __construct(
        private readonly RequirementWikiCatalogBuilder $catalogBuilder,
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly ModuleEntitlementService $entitlements,
        private readonly CustomerPermissionService $permissions,
    ) {}

    /**
     * Whether the user may read the Wiki: the `wiki` module for the customer and wiki.view for
     * them — the rule every Wiki page in the app opens with.
     */
    public function canRead(?User $user): bool
    {
        $customer = $user?->customer;

        return $user instanceof User
            && $user->is_active
            && $customer !== null
            && $this->entitlements->hasModule($customer, 'wiki')
            && $this->permissions->has($user, CustomerPermissionCatalog::WIKI_VIEW);
    }

    /**
     * The published pages, by id.
     *
     * @return list<array<string, mixed>> Catalog entries (RequirementWikiCatalogBuilder)
     */
    public function pages(User $user): array
    {
        $entries = $this->catalogBuilder->build((int) $user->customer_id, RequirementWikiCatalogBuilder::GROUNDING_PUBLISHED_ONLY);
        usort($entries, static fn (array $left, array $right): int => (int) $left['page_id'] <=> (int) $right['page_id']);

        return $entries;
    }

    /**
     * One published page with the published pages its text links to, in the order they first
     * occur. A link to a page that is not published is not a link here: its target is not
     * knowledge the API serves. The links are read from the published text, not from the link
     * table, which follows the working version.
     *
     * @return array{entry: array<string, mixed>, links: list<array<string, mixed>>}|null
     */
    public function page(User $user, int $pageId): ?array
    {
        $entries = $this->pages($user);
        $bySlug = [];
        $entry = null;

        foreach ($entries as $candidate) {
            $bySlug[(string) $candidate['slug']] = $candidate;

            if ((int) $candidate['page_id'] === $pageId) {
                $entry = $candidate;
            }
        }

        if ($entry === null) {
            return null;
        }

        $links = [];

        foreach ($this->linkParser->parse((string) $entry['content_markdown']) as $link) {
            $target = $bySlug[$link['target_slug']] ?? null;

            if ($target === null || (int) $target['page_id'] === $pageId || isset($links[(int) $target['page_id']])) {
                continue;
            }

            $links[(int) $target['page_id']] = $target;
        }

        return ['entry' => $entry, 'links' => array_values($links)];
    }
}
