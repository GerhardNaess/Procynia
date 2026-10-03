<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hjem — Procynia across its modules, rather than any one of them.
 *
 * This page used to be the bid cockpit, which made Hjem and Anbud the same thing wearing two
 * names: a person clicking "Hjem" landed inside Anbud's numbers with Anbud's pipeline in front of
 * them. The cockpit is unchanged and still exists, but it belongs to Anbud and now lives there as
 * Bid Status. What is left here is the one thing neither module can show: where the system as a
 * whole stands.
 *
 * Deliberately thin. Every number below is a count over data a module already owns, read through
 * that module's own access rules — `SavedNoticeAccessService` for cases, the user's visible page
 * statuses for Wiki. Nothing here decides anything, and no domain logic was written for this page;
 * the moment a card needs reasoning rather than counting, that reasoning belongs in its module.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly CustomerPermissionService $customerPermissions,
    ) {}

    public function __invoke(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);

        // Same order as the left rail, minus Hjem itself. The Wiki card is dropped rather than
        // dimmed for someone the customer has given no Wiki permission: the rail already hides
        // the module for them, and a card with its page counts would both contradict that and say
        // how much is in a Wiki they cannot open.
        $modules = array_values(array_filter([
            $this->customerPermissions->has($user, CustomerPermissionCatalog::WIKI_VIEW)
                ? $this->wikiCard($user, $customerId)
                : null,
            $this->tendersCard($user),
            $this->qualityCard(),
        ]));

        return Inertia::render('App/Home/Index', [
            'modules' => $modules,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function wikiCard(User $user, int $customerId): array
    {
        $visibleStatuses = $user->visibleEnterpriseWikiPageStatuses();

        $counts = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $visibleStatuses)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $metrics = [
            $this->metric('pages', (int) $counts->sum()),
        ];

        // Only offered to someone who can actually open a page in review. For everyone else the
        // number would name work they cannot reach, which is worse than not showing it.
        if (in_array(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $visibleStatuses, true)) {
            $metrics[] = $this->metric(
                'pending_review',
                (int) ($counts[EnterpriseWikiPage::STATUS_PENDING_REVIEW] ?? 0),
            );
        }

        return [
            'key' => 'wiki',
            'state' => 'live',
            'href' => route('app.wiki.index'),
            'metrics' => $metrics,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tendersCard(User $user): array
    {
        $visible = $this->savedNoticeAccess->visibleQueryFor($user);

        return [
            'key' => 'tenders',
            'state' => 'live',
            'href' => route('app.notices.index', ['mode' => 'saved']),
            'metrics' => [
                $this->metric('active_cases', (clone $visible)->whereNull('archived_at')->count()),
                $this->metric(
                    'submitted_cases',
                    (clone $visible)->where('bid_status', SavedNotice::BID_STATUS_SUBMITTED)->count(),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qualityCard(): array
    {
        // No numbers on purpose. The module has no data of its own yet, and borrowing Wiki's
        // would say the quality module is doing work it is not.
        return [
            'key' => 'quality',
            'state' => 'building',
            'href' => route('app.quality.index'),
            'metrics' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metric(string $key, int $value): array
    {
        return ['key' => $key, 'value' => $value];
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function frontendContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $user instanceof User
            ? ($this->customerContext->currentCustomerId($user) ?? $user->customer_id)
            : null;

        abort_unless(
            $user instanceof User
            && $user->canAccessCustomerFrontend()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }
}
