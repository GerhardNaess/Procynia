<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use App\Services\InfoCenter\InfoItemPayload;
use App\Services\MyTasks\MyTasksService;
use App\Services\MyTasks\Sources\TenderTaskSource;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InfoCenterController extends Controller
{
    private const OPERATIONAL_ACTIVITY_THRESHOLD = 3;

    private const OPERATIONAL_SCORE_OWNED_OPEN = 2;

    private const OPERATIONAL_SCORE_CREATED_OPEN = 1;

    private const OPERATIONAL_SCORE_OWNED_REQUIRES_RESPONSE = 2;

    private const OPERATIONAL_SCORE_CREATED_REQUIRES_RESPONSE = 1;

    private const OPERATIONAL_SCORE_OWNED_OVERDUE = 2;

    private const OPERATIONAL_SCORE_CREATED_OVERDUE = 1;

    private const OPERATIONAL_SCORE_OWNED_DUE_SOON = 1;

    private const OPERATIONAL_SCORE_CREATED_DUE_SOON = 1;

    private const OPERATIONAL_SCORE_CASE_BONUS = 1;

    /**
     * Whether the customer holds Anbud. Every view except «Mine oppgaver», and every panel except its
     * counter, is a list of Anbud aksjoner; without the module they could only ever be empty, so the
     * page is «Mine oppgaver» alone and does not talk about aksjoner. Set per request in index().
     */
    private bool $tenderAvailable = true;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly MyTasksService $myTasks,
        private readonly TenderTaskSource $tenderTasks,
        private readonly InfoItemPayload $infoItemPayload,
    ) {}

    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $this->tenderAvailable = $this->tenderTasks->isAvailableFor($user);
        $visibleItemsQuery = $this->baseInfoItemsQuery($user);
        $roleContext = $this->resolveRoleContext($user, clone $visibleItemsQuery);
        $activeView = $this->normalizeView(
            trim((string) $request->query('view', '')) ?: $roleContext['default_view'],
            $roleContext['default_view'],
            $roleContext['persona'],
        );
        $perPage = 20;

        $itemsQuery = $this->applyViewFilter(
            $this->applyRolePriorityOrdering(clone $visibleItemsQuery, $user, $roleContext['persona'], $activeView),
            $user,
            $activeView,
        );

        $items = $itemsQuery
            ->paginate($perPage)
            ->withQueryString();

        $items->setCollection(
            $items->getCollection()->map(fn (SavedNoticeInfoItem $infoItem): array => $this->infoItemPayload->for($infoItem, $customerId)),
        );

        // Read once: the same list answers the "Mine oppgaver" counter and the list itself, so the
        // two can never disagree about how much work is outstanding. Every module's work — Anbud
        // aksjoner, Wiki review and QA, Leverandører — comes through MyTasksService, each source
        // applying its own module's access rules before anything reaches this page.
        $myTasks = $this->myTasks->tasksFor($user, $customerId);

        return Inertia::render('App/InfoCenter/Index', [
            'infoCenter' => [
                'active_view' => $activeView,
                // Whether the customer holds Anbud, so the page can explain only what it shows: the
                // same module status the views and panels are built from.
                'tender_available' => $this->tenderAvailable,
                'default_view' => $roleContext['default_view'],
                'role_context' => $roleContext,
                'view_options' => $this->viewOptions($roleContext['persona'], $activeView),
                'summary' => [
                    'items' => $this->summaryItems($user, $roleContext, clone $visibleItemsQuery, $myTasks->count()),
                ],
                // «Mine oppgaver», grouped Forfalt / Denne uken / Senere / Uten frist. Only under
                // that view — the person has work to do, they are not waiting on anybody. Outside the
                // paginator: every open task, because the groups are what the person reads.
                //
                // `items` below still carries the Anbud aksjoner under this view as before, for the
                // other views' list and anything that reads it; the page shows the grouped tasks.
                'my_tasks' => $activeView === 'my_tasks'
                    ? $this->myTasks->payload($myTasks, null, $this->myTasks->availableModules($user))
                    : ['count' => $myTasks->count(), 'modules' => [], 'groups' => []],
                'items' => $items->getCollection()->all(),
                'pagination' => [
                    'from' => $items->firstItem(),
                    'to' => $items->lastItem(),
                    'total' => $items->total(),
                    'prev_page_url' => $items->previousPageUrl(),
                    'next_page_url' => $items->nextPageUrl(),
                ],
            ],
        ]);
    }

    private function frontendContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $user->canAccessCustomerFrontend()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    private function baseInfoItemsQuery(User $user): Builder
    {
        $query = SavedNoticeInfoItem::query()
            ->whereIn('saved_notice_id', $this->savedNoticeAccess->visibleQueryFor($user)->select('id'))
            // Aksjoner are Anbud's: a customer without the module reaches none of them, here as on
            // the cases they belong to.
            ->when(! $this->tenderAvailable, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->with([
                'savedNotice:id,title,external_id,reference_number',
                'owner:id,name,customer_id',
                'createdBy:id,name,customer_id',
            ]);

        return $query;
    }

    private function applyViewFilter(Builder $query, User $user, string $activeView): Builder
    {
        return match ($activeView) {
            'my_tasks' => $query
                ->where('owner_user_id', $user->id)
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED),
            'awaiting_response' => $query
                ->where('created_by_user_id', $user->id)
                ->where('requires_response', true)
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->where(function (Builder $builder) use ($user): void {
                    $builder
                        ->whereNull('owner_user_id')
                        ->orWhere('owner_user_id', '!=', $user->id);
                }),
            'outbound' => $query->where('created_by_user_id', $user->id),
            'inbound' => $query->where('direction', SavedNoticeInfoItem::DIRECTION_INBOUND),
            default => $query
                ->where('owner_user_id', $user->id)
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED),
        };
    }

    private function applyRolePriorityOrdering(Builder $query, User $user, string $persona, string $activeView): Builder
    {
        $query->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [SavedNoticeInfoItem::STATUS_CLOSED]);

        return match ($activeView) {
            'my_tasks', 'awaiting_response', 'inbound' => $query
                ->orderByRaw('CASE WHEN response_due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('response_due_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            'outbound' => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            default => $query
                ->orderByRaw('CASE WHEN response_due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('response_due_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
        };
    }

    private function viewOptions(string $persona, string $activeView): array
    {
        $orderedViews = $this->viewKeysForPersona($persona);

        $labels = [
            'my_tasks' => 'Mine oppgaver',
            'awaiting_response' => 'Venter på svar',
            'inbound' => 'Innkommende',
            'outbound' => 'Opprettet av meg',
        ];

        return array_map(
            fn (string $view): array => [
                'value' => $view,
                'label' => $labels[$view],
                'href' => route('app.info-center.index', ['view' => $view]),
                'is_active' => $activeView === $view,
            ],
            $orderedViews,
        );
    }

    private function normalizeView(string $value, string $defaultView, string $persona): string
    {
        $canonicalValue = in_array($value, ['action_required', 'my_open'], true)
            ? 'my_tasks'
            : $value;

        return in_array($canonicalValue, $this->viewKeysForPersona($persona), true)
            ? $canonicalValue
            : $defaultView;
    }

    /**
     * «Mine oppgaver» comes first for everyone: what is still on you is the page's first answer,
     * whichever way you use the case surface. The persona still decides the panels and wording.
     */
    private function viewKeysForPersona(string $persona): array
    {
        return $this->tenderAvailable
            ? ['my_tasks', 'awaiting_response', 'outbound', 'inbound']
            : ['my_tasks'];
    }

    private function resolveRoleContext(User $user, Builder $visibleItemsQuery): array
    {
        $basePersona = $this->resolveBasePersona($user);
        $activityContext = $this->resolveOperationalActivityContext($user, $visibleItemsQuery);
        $persona = $this->resolveFinalPersona($basePersona, $activityContext);

        if (! $this->tenderAvailable) {
            return [
                'persona' => $persona,
                'base_persona' => $basePersona,
                'label' => __('procynia.info_center_page.my_tasks.neutral_label'),
                'headline' => __('procynia.info_center_page.my_tasks.neutral_headline'),
                'subheadline' => __('procynia.info_center_page.my_tasks.neutral_subheadline'),
                'default_view' => 'my_tasks',
                'operational_activity_score' => $activityContext['operational_activity_score'],
                'is_case_operational' => false,
            ];
        }

        return $persona === 'operational'
            ? [
                'persona' => 'operational',
                'base_persona' => $basePersona,
                'label' => 'Operativ arbeidsflate',
                'headline' => 'Opprett og følg opp aksjoner, svarfrister og beslutninger.',
                'subheadline' => $basePersona === 'commercial_owner' && $persona === 'operational'
                    ? 'Du følger opp sakene aktivt, så dette sporet skiller egne oppgaver fra det du venter svar på.'
                    : 'Her ser du egne oppgaver og det du venter svar på.',
                'default_view' => 'my_tasks',
                'operational_activity_score' => $activityContext['operational_activity_score'],
                'is_case_operational' => $basePersona === 'commercial_owner' && $persona === 'operational',
            ]
            : [
                'persona' => 'commercial_owner',
                'base_persona' => $basePersona,
                'label' => 'Styrings- og oppfølgingsflate',
                'headline' => 'Se beslutninger, avklaringer og eierskap som påvirker retning og risiko.',
                'subheadline' => 'Du kan fortsatt opprette, tildele og følge opp aksjoner når saken krever det.',
                'default_view' => 'my_tasks',
                'operational_activity_score' => $activityContext['operational_activity_score'],
                'is_case_operational' => false,
            ];
    }

    private function resolveBasePersona(User $user): string
    {
        if ($user->isBidManager() || $user->isSystemOwner()) {
            return 'operational';
        }

        return 'commercial_owner';
    }

    private function resolveFinalPersona(string $basePersona, array $activityContext): string
    {
        if ($basePersona === 'operational') {
            return 'operational';
        }

        return $activityContext['operational_activity_score'] >= self::OPERATIONAL_ACTIVITY_THRESHOLD
            ? 'operational'
            : 'commercial_owner';
    }

    private function resolveOperationalActivityContext(User $user, Builder $visibleItemsQuery): array
    {
        $now = now();
        $dueSoonUntil = (clone $now)->addDays(7)->endOfDay();

        $openOwnedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->where('owner_user_id', $user->id);
        });

        $openCreatedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->where('created_by_user_id', $user->id);
        });

        $requiresResponseOwnedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->where('requires_response', true)
                ->where('owner_user_id', $user->id);
        });

        $requiresResponseCreatedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->where('requires_response', true)
                ->where('created_by_user_id', $user->id);
        });

        $overdueOwnedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user, $now): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->whereNotNull('response_due_at')
                ->where('response_due_at', '<', $now)
                ->where('owner_user_id', $user->id);
        });

        $overdueCreatedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user, $now): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->whereNotNull('response_due_at')
                ->where('response_due_at', '<', $now)
                ->where('created_by_user_id', $user->id);
        });

        $dueSoonOwnedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user, $now, $dueSoonUntil): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->whereNotNull('response_due_at')
                ->whereBetween('response_due_at', [$now, $dueSoonUntil])
                ->where('owner_user_id', $user->id);
        });

        $dueSoonCreatedCount = $this->countMatching($visibleItemsQuery, function (Builder $query) use ($user, $now, $dueSoonUntil): void {
            $query
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->whereNotNull('response_due_at')
                ->whereBetween('response_due_at', [$now, $dueSoonUntil])
                ->where('created_by_user_id', $user->id);
        });

        $commercialOwnerWorkloadBonus = $this->hasCommercialOwnerOperationalWorkload($visibleItemsQuery, $user)
            ? self::OPERATIONAL_SCORE_CASE_BONUS
            : 0;

        $operationalActivityScore = (
            $openOwnedCount * self::OPERATIONAL_SCORE_OWNED_OPEN
            + $openCreatedCount * self::OPERATIONAL_SCORE_CREATED_OPEN
            + $requiresResponseOwnedCount * self::OPERATIONAL_SCORE_OWNED_REQUIRES_RESPONSE
            + $requiresResponseCreatedCount * self::OPERATIONAL_SCORE_CREATED_REQUIRES_RESPONSE
            + $overdueOwnedCount * self::OPERATIONAL_SCORE_OWNED_OVERDUE
            + $overdueCreatedCount * self::OPERATIONAL_SCORE_CREATED_OVERDUE
            + $dueSoonOwnedCount * self::OPERATIONAL_SCORE_OWNED_DUE_SOON
            + $dueSoonCreatedCount * self::OPERATIONAL_SCORE_CREATED_DUE_SOON
            + $commercialOwnerWorkloadBonus
        );

        return [
            'open_owned_count' => $openOwnedCount,
            'open_created_count' => $openCreatedCount,
            'requires_response_owned_count' => $requiresResponseOwnedCount,
            'requires_response_created_count' => $requiresResponseCreatedCount,
            'overdue_owned_count' => $overdueOwnedCount,
            'overdue_created_count' => $overdueCreatedCount,
            'due_soon_owned_count' => $dueSoonOwnedCount,
            'due_soon_created_count' => $dueSoonCreatedCount,
            'commercial_owner_workload_bonus' => $commercialOwnerWorkloadBonus,
            'operational_activity_score' => $operationalActivityScore,
        ];
    }

    private function hasCommercialOwnerOperationalWorkload(Builder $visibleItemsQuery, User $user): bool
    {
        return (clone $visibleItemsQuery)
            ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->where('owner_user_id', $user->id)
                    ->orWhere('created_by_user_id', $user->id);
            })
            ->whereHas('savedNotice', function (Builder $caseQuery) use ($user): void {
                $caseQuery->where('opportunity_owner_user_id', $user->id);
            })
            ->exists();
    }

    private function summaryItems(User $user, array $roleContext, Builder $baseQuery, int $myTasksCount): array
    {
        if (! $this->tenderAvailable) {
            return [[
                'key' => 'my_tasks',
                'label' => 'Mine oppgaver',
                'count' => $myTasksCount,
                'description' => __('procynia.info_center_page.my_tasks.neutral_panel_description'),
                'tone' => 'danger',
            ]];
        }

        $responseDueSoonCount = $this->countMatching($baseQuery, function (Builder $query): void {
            $query
                ->where('requires_response', true)
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->whereNotNull('response_due_at')
                ->where('response_due_at', '<=', now()->addDays(7)->endOfDay());
        });

        $decisionCount = $this->countMatching($baseQuery, function (Builder $query): void {
            $query
                ->where('type', SavedNoticeInfoItem::TYPE_DECISION)
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED);
        });

        $clarificationCount = $this->countMatching($baseQuery, function (Builder $query): void {
            $query
                ->where('type', SavedNoticeInfoItem::TYPE_CLARIFICATION)
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED);
        });

        $awaitingResponseCount = $this->countMatching($baseQuery, function (Builder $query) use ($user): void {
            $query
                ->where('created_by_user_id', $user->id)
                ->where('requires_response', true)
                ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                ->where(function (Builder $builder) use ($user): void {
                    $builder
                        ->whereNull('owner_user_id')
                        ->orWhere('owner_user_id', '!=', $user->id);
                });
        });

        if ($roleContext['persona'] === 'operational') {
            return [
                [
                    'key' => 'my_tasks',
                    'label' => 'Mine oppgaver',
                    'count' => $myTasksCount,
                    'description' => 'Aksjoner og oppgaver som er tildelt deg og fortsatt er åpne.',
                    'tone' => 'danger',
                ],
                [
                    'key' => 'awaiting_response',
                    'label' => 'Venter på svar',
                    'count' => $this->countMatching($baseQuery, function (Builder $query) use ($user): void {
                        $query
                            ->where('created_by_user_id', $user->id)
                            ->where('requires_response', true)
                            ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
                            ->where(function (Builder $builder) use ($user): void {
                                $builder
                                    ->whereNull('owner_user_id')
                                    ->orWhere('owner_user_id', '!=', $user->id);
                            });
                    }),
                    'description' => 'Aksjoner du har sendt ut og fortsatt venter svar på.',
                    'tone' => 'violet',
                ],
                [
                    'key' => 'due_soon',
                    'label' => 'Frister innen 7 dager',
                    'count' => $responseDueSoonCount,
                    'description' => 'Aksjoner med nær oppfølgingsfrist.',
                    'tone' => 'amber',
                ],
            ];
        }

        // A commercial owner mostly watches decisions and clarifications, but «Mine oppgaver» is
        // the default view for everyone, so its counter leads here too: work assigned by name —
        // a Wiki review, a supplier they are intern ansvarlig for — has to be countable where the
        // page opens.
        return [
            [
                'key' => 'my_tasks',
                'label' => 'Mine oppgaver',
                'count' => $myTasksCount,
                'description' => 'Aksjoner og oppgaver som er tildelt deg og fortsatt er åpne.',
                'tone' => 'danger',
            ],
            [
                'key' => 'decision',
                'label' => 'Beslutninger',
                'count' => $decisionCount,
                'description' => 'Beslutningspunkter som påvirker retning og risiko.',
                'tone' => 'indigo',
            ],
            [
                'key' => 'clarification',
                'label' => 'Avklaringer',
                'count' => $clarificationCount,
                'description' => 'Avklaringer som må lande før saken kan gå videre.',
                'tone' => 'violet',
            ],
            [
                'key' => 'awaiting_response',
                'label' => 'Venter på svar',
                'count' => $awaitingResponseCount,
                'description' => 'Aksjoner du har sendt ut og fortsatt venter svar på.',
                'tone' => 'amber',
            ],
        ];
    }

    private function countMatching(Builder $baseQuery, callable $callback): int
    {
        $query = clone $baseQuery;
        $callback($query);

        return (int) $query->count();
    }
}
