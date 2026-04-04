<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
    ) {
    }

    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
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
            $items->getCollection()->map(fn (SavedNoticeInfoItem $infoItem): array => $this->infoItemPayload($infoItem, $customerId)),
        );

        return Inertia::render('App/InfoCenter/Index', [
            'infoCenter' => [
                'active_view' => $activeView,
                'default_view' => $roleContext['default_view'],
                'role_context' => $roleContext,
                'view_options' => $this->viewOptions($roleContext['persona'], $activeView),
                'summary' => [
                    'items' => $this->summaryItems($user, $roleContext, clone $visibleItemsQuery),
                ],
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

    private function viewKeysForPersona(string $persona): array
    {
        return $persona === 'operational'
            ? ['my_tasks', 'awaiting_response', 'outbound', 'inbound']
            : ['awaiting_response', 'my_tasks', 'outbound', 'inbound'];
    }

    private function resolveRoleContext(User $user, Builder $visibleItemsQuery): array
    {
        $basePersona = $this->resolveBasePersona($user);
        $activityContext = $this->resolveOperationalActivityContext($user, $visibleItemsQuery);
        $persona = $this->resolveFinalPersona($basePersona, $activityContext);

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
                'default_view' => 'awaiting_response',
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

    private function summaryItems(User $user, array $roleContext, Builder $baseQuery): array
    {
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
                    'count' => $this->countMatching($baseQuery, function (Builder $query) use ($user): void {
                        $query
                            ->where('owner_user_id', $user->id)
                            ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED);
                    }),
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

        return [
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

    private function infoItemPayload(SavedNoticeInfoItem $infoItem, int $customerId): array
    {
        $savedNotice = $infoItem->savedNotice;
        $subject = trim((string) ($infoItem->subject ?? ''));

        return [
            'id' => $infoItem->id,
            'type' => $infoItem->type,
            'type_label' => $infoItem->type_label,
            'direction' => $infoItem->direction,
            'direction_label' => $infoItem->direction_label,
            'channel' => $infoItem->channel,
            'channel_label' => $infoItem->channel_label,
            'subject' => $infoItem->subject,
            'subject_label' => $subject !== '' ? $subject : $infoItem->type_label,
            'body_preview' => Str::limit(Str::squish((string) $infoItem->body), 220),
            'status' => $infoItem->status,
            'status_label' => $infoItem->status_label,
            'requires_response' => (bool) $infoItem->requires_response,
            'response_due_at' => optional($infoItem->response_due_at)?->toDateString(),
            'closure_comment' => $infoItem->closure_comment,
            'owner' => $this->safeUserPayload($infoItem->owner, $customerId),
            'created_by' => $this->safeUserPayload($infoItem->createdBy, $customerId),
            'created_at' => optional($infoItem->created_at)?->toIso8601String(),
            'saved_notice' => $savedNotice ? [
                'id' => $savedNotice->id,
                'title' => $savedNotice->title,
                'notice_id' => $savedNotice->external_id,
                'reference_number' => $savedNotice->reference_number,
                'show_url' => route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]),
            ] : null,
        ];
    }

    private function safeUserPayload(?User $user, int $customerId): ?array
    {
        if (! $user instanceof User || (int) $user->customer_id !== $customerId) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
