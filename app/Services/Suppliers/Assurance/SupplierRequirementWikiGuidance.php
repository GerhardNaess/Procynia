<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\EnterpriseWikiPage;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierControlRequirementWikiPage;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Veiledning fra Enterprise Wiki» on a control requirement (supplier-assurance-v2-plan §28): links
 * from a requirement to existing Wiki pages that explain how it is controlled. A reference, never a
 * copy; the Wiki owns the content.
 *
 * Reading is the Wiki's own rule, applied here because the supplier pages are outside the Wiki's
 * route guard: the customer holds the `wiki` module, the person has wiki.view and can use the
 * customer frontend, and the page is the customer's and in a status the person may read
 * (User::visibleEnterpriseWikiPageStatuses() — today every status, as in the Wiki itself). Without
 * that, the guidance is absent: no titles, no count, no «hidden page».
 *
 * Linking and unlinking need supplier.assure — the right that owns the Kontrollkrav catalogue — *and*
 * Wiki read access, so nobody links or removes a page they cannot open themselves. A new link must be
 * a page the person can read that is not archived or superseded, and not a Wiki index page. A
 * requirement for one supplier follows that supplier: an ended supplier is read-only.
 */
class SupplierRequirementWikiGuidance
{
    /** How many pages one search returns. */
    public const SEARCH_LIMIT = 20;

    /** Retired pages are never offered as new guidance; existing links to them still show, labelled. */
    private const NOT_OFFERED_STATUSES = [EnterpriseWikiPage::STATUS_ARCHIVED, EnterpriseWikiPage::STATUS_SUPERSEDED];

    /** Navigation pages the Wiki generates itself — not guidance. */
    private const NOT_OFFERED_TYPES = [EnterpriseWikiPage::PAGE_TYPE_INDEX, EnterpriseWikiPage::PAGE_TYPE_BACKLINKS];

    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /** Whether the person may read Enterprise Wiki pages from here. */
    public function canRead(?User $user): bool
    {
        $customer = $user?->customer;

        return $customer !== null
            && $user->canAccessCustomerFrontend()
            && $this->entitlements->hasModule($customer, 'wiki')
            && $this->permissions->has($user, CustomerPermissionCatalog::WIKI_VIEW);
    }

    /** Whether the person may link and unlink guidance. */
    public function canManage(?User $user): bool
    {
        return $user instanceof User && $this->access->canAssure($user) && $this->canRead($user);
    }

    /**
     * The guidance the person may read, by requirement id: title, Wiki link and — when it matters —
     * that the page is not published yet or is archived. Null when the person cannot read the Wiki
     * at all, so nothing about the links is shown.
     *
     * @param  iterable<SupplierControlRequirement>  $requirements
     * @return array<int, list<array{id: int, title: string, url: string, note: ?string}>>|null
     */
    public function forRequirements(?User $user, iterable $requirements): ?array
    {
        if (! $this->canRead($user)) {
            return null;
        }

        $ids = collect($requirements)->map(fn (SupplierControlRequirement $requirement): int => (int) $requirement->id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $links = SupplierControlRequirementWikiPage::query()
            ->where('customer_id', (int) $user->customer_id)
            ->whereIn('requirement_id', $ids)
            ->whereHas('page', fn ($query) => $query
                ->where('customer_id', (int) $user->customer_id)
                ->whereIn('status', $user->visibleEnterpriseWikiPageStatuses()))
            ->with('page:id,customer_id,slug,title,status,published_version_id')
            ->get();

        return $links
            ->groupBy('requirement_id')
            ->map(fn (Collection $group): array => $group
                ->map(fn (SupplierControlRequirementWikiPage $link): array => $this->page($link->page))
                ->sortBy(fn (array $page): string => mb_strtolower($page['title']))
                ->values()
                ->all())
            ->mapWithKeys(fn (array $pages, $requirementId): array => [(int) $requirementId => $pages])
            ->all();
    }

    /**
     * Pages the person can read and may link, matching $term in title or slug, by title — at most
     * SEARCH_LIMIT, and whether there were more (the person is then asked to narrow the search).
     * Nothing without the right to manage guidance.
     *
     * @return array{pages: list<array{id: int, title: string, url: string, note: ?string}>, has_more: bool}
     */
    public function search(User $user, string $term): array
    {
        if (! $this->canManage($user)) {
            return ['pages' => [], 'has_more' => false];
        }

        $term = trim($term);
        $found = $this->offered($user)
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
                $query->where(fn ($inner) => $inner->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(slug) like ?', [$like]));
            })
            ->orderByRaw('lower(title)')
            ->orderBy('id')
            ->limit(self::SEARCH_LIMIT + 1)
            ->get(['id', 'customer_id', 'slug', 'title', 'status', 'published_version_id']);

        return [
            'pages' => $found->take(self::SEARCH_LIMIT)->map(fn (EnterpriseWikiPage $page): array => $this->page($page))->values()->all(),
            'has_more' => $found->count() > self::SEARCH_LIMIT,
        ];
    }

    /** Knytt til: one more page as guidance on the requirement. */
    public function link(User $actor, SupplierControlRequirement $requirement, int $pageId): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $requirement, $pageId): void {
            $this->lockOpen($actor, $requirement);
            $page = $this->offered($actor)->whereKey($pageId)->first();

            if ($page === null) {
                throw ValidationException::withMessages(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_not_available')]);
            }

            $exists = SupplierControlRequirementWikiPage::query()
                ->where('requirement_id', $requirement->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_already_linked')]);
            }

            try {
                SupplierControlRequirementWikiPage::query()->create([
                    'customer_id' => (int) $actor->customer_id,
                    'requirement_id' => (int) $requirement->id,
                    'enterprise_wiki_page_id' => (int) $page->id,
                    'created_by_user_id' => (int) $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same link sent twice at once: the second is told, not shown an error page.
                throw ValidationException::withMessages(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_already_linked')]);
            }
        });
    }

    /** Fjern: the page is no longer guidance on the requirement. The page itself is untouched. */
    public function unlink(User $actor, SupplierControlRequirement $requirement, int $pageId): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $requirement, $pageId): void {
            $this->lockOpen($actor, $requirement);

            // Only a link to a page the person can read: nobody removes what they cannot see.
            $deleted = SupplierControlRequirementWikiPage::query()
                ->where('customer_id', (int) $actor->customer_id)
                ->where('requirement_id', $requirement->id)
                ->where('enterprise_wiki_page_id', $pageId)
                ->whereHas('page', fn ($query) => $query->whereIn('status', $actor->visibleEnterpriseWikiPageStatuses()))
                ->delete();

            if ($deleted === 0) {
                throw ValidationException::withMessages(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_not_linked')]);
            }
        });
    }

    /** @return Builder<EnterpriseWikiPage> */
    private function offered(User $user)
    {
        return EnterpriseWikiPage::query()
            ->where('customer_id', (int) $user->customer_id)
            ->whereIn('status', array_values(array_diff($user->visibleEnterpriseWikiPageStatuses(), self::NOT_OFFERED_STATUSES)))
            ->whereNotIn('page_type', self::NOT_OFFERED_TYPES);
    }

    /** @return array{id: int, title: string, url: string, note: ?string} */
    private function page(EnterpriseWikiPage $page): array
    {
        return [
            'id' => (int) $page->id,
            'title' => (string) $page->title,
            'url' => route('app.wiki.show', ['slug' => $page->slug], false),
            // Said only when it matters to someone relying on the guidance.
            'note' => match (true) {
                in_array($page->status, self::NOT_OFFERED_STATUSES, true) => 'archived',
                $page->published_version_id === null => 'not_published',
                default => null,
            },
        ];
    }

    private function authorize(User $actor): void
    {
        if (! $this->canManage($actor)) {
            throw new AuthorizationException;
        }
    }

    /** The requirement locked, of the person's customer; for one supplier, that supplier open. */
    private function lockOpen(User $actor, SupplierControlRequirement $requirement): void
    {
        if ($requirement->supplier_id !== null) {
            $supplier = $this->access->visibleSuppliers($actor)->whereKey($requirement->supplier_id)->lockForUpdate()->first();

            if (! $supplier instanceof Supplier) {
                throw new AuthorizationException;
            }

            if ($supplier->isEnded()) {
                throw ValidationException::withMessages(['wiki_page_id' => __('procynia.supplier_management.validation.reopen_before_edit')]);
            }
        }

        SupplierControlRequirement::query()
            ->where('customer_id', (int) $actor->customer_id)
            ->whereKey($requirement->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
