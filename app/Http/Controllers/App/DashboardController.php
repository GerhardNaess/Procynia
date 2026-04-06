<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SavedNotice;
use App\Models\SavedNoticeBusinessReview;
use App\Models\SavedNoticePhaseComment;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Models\WatchProfile;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
    ) {
    }

    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $pipeline = $this->savedNoticePipelineSummary($user);
        $cockpit = $this->buildCockpitPayload($user, $customerId, $pipeline);

        return Inertia::render('App/Dashboard/Index', [
            'cockpit' => $cockpit,
            'pipeline' => $pipeline,
            'stats' => $this->resolveStats($user, $customerId),
            'recentWorklistItems' => $this->resolveRecentWorklistItems($user),
            'watchProfileSummary' => $this->resolveWatchProfileSummary($user, $customerId),
            'quickLinks' => $this->resolveQuickLinks($user),
        ]);
    }

    /**
     * @param  array<string, mixed>  $pipeline
     * @return array<string, mixed>
     */
    private function buildCockpitPayload(User $user, int $customerId, array $pipeline): array
    {
        $cockpitScopeNotices = $this->cockpitScopeSavedNotices($user, $customerId);
        $archivedNotices = $this->cockpitScopeArchivedSavedNotices($user, $customerId);
        $trendArchivedNotices = $this->cockpitScopeArchivedSavedNotices(
            $user,
            $customerId,
            now()->startOfMonth()->subMonthsNoOverflow(11),
        );
        $deadlineItems = $this->buildDeadlineItems($cockpitScopeNotices);

        return [
            'portfolio' => [
                'total' => $pipeline['total_count'],
                'active' => $pipeline['active_total_count'],
                'outcome' => $pipeline['outcome_total_count'],
            ],
            'attention' => [
                'items' => $this->resolveAttentionItems($cockpitScopeNotices, $deadlineItems),
            ],
            'deadlines' => [
                'month_start' => now()->startOfMonth()->toIso8601String(),
                'month_label' => now()->locale('nb')->translatedFormat('F Y'),
                'items' => $deadlineItems,
                'upcoming' => array_slice($deadlineItems, 0, 6),
            ],
            'bid_quality' => $this->resolveBidQualitySummary($cockpitScopeNotices, $archivedNotices, $trendArchivedNotices),
            'responsibility_activity' => $this->resolveResponsibilityActivitySummary($user, $customerId, $cockpitScopeNotices),
            'outcomes' => $pipeline['outcomes'],
            'pipeline' => $pipeline,
        ];
    }

    private function dashboardActiveSavedNotices(User $user, int $customerId): Collection
    {
        return $this->activeSavedNoticeQuery($user)
            ->where('customer_id', $customerId)
            ->select([
                'id',
                'customer_id',
                'bid_manager_user_id',
                'opportunity_owner_user_id',
                'bid_status',
                'title',
                'buyer_name',
                'deadline',
                'questions_deadline_at',
                'questions_rfi_deadline_at',
                'rfi_submission_deadline_at',
                'questions_rfp_deadline_at',
                'rfp_submission_deadline_at',
                'award_date_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'bidManager:id,name',
                'opportunityOwner:id,name',
                'businessReviews:id,saved_notice_id,business_review_at',
                'phaseComments:id,saved_notice_id,user_id,phase_status,comment,created_at',
                'submissions:id,saved_notice_id,sequence_number,label,submitted_at',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, SavedNotice>
     */
    private function cockpitScopeSavedNotices(User $user, int $customerId): Collection
    {
        return $this->savedNoticeAccess->cockpitScopeQueryFor($user, $customerId)
            ->whereNull('archived_at')
            ->select([
                'id',
                'customer_id',
                'bid_manager_user_id',
                'opportunity_owner_user_id',
                'bid_status',
                'title',
                'buyer_name',
                'deadline',
                'questions_deadline_at',
                'questions_rfi_deadline_at',
                'rfi_submission_deadline_at',
                'questions_rfp_deadline_at',
                'rfp_submission_deadline_at',
                'award_date_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'bidManager:id,name',
                'opportunityOwner:id,name',
                'businessReviews:id,saved_notice_id,business_review_at',
                'phaseComments:id,saved_notice_id,user_id,phase_status,comment,created_at',
                'submissions:id,saved_notice_id,sequence_number,label,submitted_at',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, SavedNotice>
     */
    private function cockpitScopeArchivedSavedNotices(User $user, int $customerId, ?CarbonInterface $since = null): Collection
    {
        return $this->savedNoticeAccess->cockpitScopeQueryFor($user, $customerId)
            ->whereNotNull('archived_at')
            ->whereIn('history_type', SavedNotice::HISTORY_TYPES)
            ->where('archived_at', '>=', $since ?? now()->subDays(90)->startOfDay())
            ->select([
                'id',
                'customer_id',
                'bid_manager_user_id',
                'opportunity_owner_user_id',
                'bid_status',
                'history_type',
                'title',
                'buyer_name',
                'created_at',
                'archived_at',
                'updated_at',
            ])
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->get();
    }

    private function savedWatchListsCount(User $user, int $customerId): int
    {
        return $user->watchProfiles()
            ->where('customer_id', $customerId)
            ->count();
    }

    private function contributorCasesCount(Collection $notices): int
    {
        $noticeIds = $notices->pluck('id')->filter()->unique()->all();

        if ($noticeIds === []) {
            return 0;
        }

        return SavedNoticeUserAccess::query()
            ->whereIn('saved_notice_id', $noticeIds)
            ->active()
            ->where('access_role', SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR)
            ->distinct()
            ->count('saved_notice_id');
    }

    private function resolveAttentionItems(Collection $notices, array $deadlineItems): array
    {
        return [
            $this->buildAttentionCategory(
                'deadline-soon',
                'Frister innen 5 dager',
                'Saker med operative frister som nærmer seg eller er passert.',
                $this->buildDeadlineSoonAttentionItems($deadlineItems),
                'danger',
            ),
            $this->buildAttentionCategory(
                'missing-bid-manager',
                'Saker uten bid-manager',
                'Saker som mangler eksplisitt operativt ansvar.',
                $this->buildMissingBidManagerAttentionItems($notices),
                'warning',
            ),
            $this->buildAttentionCategory(
                'missing-commercial-owner',
                'Saker uten kommersiell eier',
                'Saker som mangler eksplisitt kommersielt ansvar.',
                $this->buildMissingCommercialOwnerAttentionItems($notices),
                'warning',
            ),
            $this->buildAttentionCategory(
                'go-no-go-pending',
                'Go / No-Go uten beslutning',
                'Saker som står i beslutningsfasen uten endelig utfall.',
                $this->buildGoNoGoPendingAttentionItems($notices),
                'warning',
            ),
            $this->buildAttentionCategory(
                'inactive-seven-days',
                'Uten aktivitet siste 7 dager',
                'Saker som ikke har fått kommentarer eller innsendinger nylig.',
                $this->buildInactiveSevenDaysAttentionItems($notices),
                'warning',
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function buildAttentionCategory(string $key, string $title, string $subtitle, array $items, string $severity): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'count' => count($items),
            'severity' => count($items) > 0 ? $severity : 'neutral',
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $deadlineItems
     * @return array<int, array<string, mixed>>
     */
    private function buildDeadlineSoonAttentionItems(array $deadlineItems): array
    {
        $deadlineLimit = now()->addDays(5)->endOfDay();

        return collect($deadlineItems)
            ->filter(function (array $item) use ($deadlineLimit): bool {
                $date = Carbon::parse($item['date']);

                return $date->lessThanOrEqualTo($deadlineLimit);
            })
            ->groupBy('saved_notice_id')
            ->map(function (Collection $group): array {
                $item = $group->sortBy('date')->first();
                $date = Carbon::parse($item['date']);
                $daysUntil = $this->attentionDaysUntil($date);

                return [
                    'id' => 'deadline-soon:'.$item['saved_notice_id'],
                    'saved_notice_id' => $item['saved_notice_id'],
                    'title' => $item['title'],
                    'buyer_name' => $item['buyer_name'],
                    'reason' => $this->attentionDeadlineReason((string) $item['deadline_type_label'], $daysUntil),
                    'secondary' => sprintf(
                        '%s · Frist: %s',
                        $this->attentionBuyerLabel($item['buyer_name']),
                        $this->attentionDateLabel($date),
                    ),
                    'show_url' => $item['show_url'],
                    'severity' => $daysUntil <= 0 ? 'danger' : 'warning',
                    'metadata' => [
                        'deadline_type' => $item['deadline_type'],
                        'deadline_type_label' => $item['deadline_type_label'],
                        'date' => $date->toIso8601String(),
                        'date_key' => $item['date_key'],
                        'days_until' => $daysUntil,
                        'bid_manager_name' => $item['bid_manager_name'],
                    ],
                ];
            })
            ->sortBy(fn (array $item): string => (string) $item['metadata']['date'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMissingBidManagerAttentionItems(Collection $notices): array
    {
        return $notices
            ->whereNull('bid_manager_user_id')
            ->sortBy(fn (SavedNotice $notice): int => $notice->deadline?->getTimestamp() ?? PHP_INT_MAX)
            ->map(function (SavedNotice $notice): array {
                return [
                    'id' => $notice->id,
                    'saved_notice_id' => $notice->id,
                    'title' => $notice->title,
                    'buyer_name' => $notice->buyer_name,
                    'reason' => 'Mangler bid-manager',
                    'secondary' => sprintf(
                        '%s · Kommersiell eier: %s',
                        $this->attentionBuyerLabel($notice->buyer_name),
                        $notice->opportunityOwner?->name ?? 'Ikke registrert',
                    ),
                    'show_url' => route('app.notices.saved.show', ['savedNotice' => $notice->id]),
                    'severity' => 'warning',
                    'metadata' => [
                        'bid_manager_name' => $notice->bidManager?->name,
                        'opportunity_owner_name' => $notice->opportunityOwner?->name,
                        'deadline' => $notice->deadline?->toIso8601String(),
                        'bid_status' => $notice->bid_status,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMissingCommercialOwnerAttentionItems(Collection $notices): array
    {
        return $notices
            ->whereNull('opportunity_owner_user_id')
            ->sortBy(fn (SavedNotice $notice): int => $notice->deadline?->getTimestamp() ?? PHP_INT_MAX)
            ->map(function (SavedNotice $notice): array {
                return [
                    'id' => $notice->id,
                    'saved_notice_id' => $notice->id,
                    'title' => $notice->title,
                    'buyer_name' => $notice->buyer_name,
                    'reason' => 'Mangler kommersiell eier',
                    'secondary' => sprintf(
                        '%s · Bid-manager: %s',
                        $this->attentionBuyerLabel($notice->buyer_name),
                        $notice->bidManager?->name ?? 'Ikke registrert',
                    ),
                    'show_url' => route('app.notices.saved.show', ['savedNotice' => $notice->id]),
                    'severity' => 'warning',
                    'metadata' => [
                        'bid_manager_name' => $notice->bidManager?->name,
                        'opportunity_owner_name' => $notice->opportunityOwner?->name,
                        'deadline' => $notice->deadline?->toIso8601String(),
                        'bid_status' => $notice->bid_status,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildGoNoGoPendingAttentionItems(Collection $notices): array
    {
        return $notices
            ->where('bid_status', SavedNotice::BID_STATUS_GO_NO_GO)
            ->sortBy(fn (SavedNotice $notice): int => $notice->deadline?->getTimestamp() ?? PHP_INT_MAX)
            ->map(function (SavedNotice $notice): array {
                return [
                    'id' => $notice->id,
                    'saved_notice_id' => $notice->id,
                    'title' => $notice->title,
                    'buyer_name' => $notice->buyer_name,
                    'reason' => 'Beslutning mangler',
                    'secondary' => sprintf(
                        '%s · Status: %s',
                        $this->attentionBuyerLabel($notice->buyer_name),
                        $notice->bid_status_label,
                    ),
                    'show_url' => route('app.notices.saved.show', ['savedNotice' => $notice->id]),
                    'severity' => 'warning',
                    'metadata' => [
                        'bid_status' => $notice->bid_status,
                        'bid_status_label' => $notice->bid_status_label,
                        'deadline' => $notice->deadline?->toIso8601String(),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInactiveSevenDaysAttentionItems(Collection $notices): array
    {
        return $notices
            ->filter(function (SavedNotice $notice): bool {
                $latestActivityAt = $this->latestSavedNoticeActivityAt($notice);

                return $latestActivityAt === null || $latestActivityAt->lessThan(now()->subDays(7)->startOfDay());
            })
            ->sortBy(function (SavedNotice $notice): int {
                return $this->latestSavedNoticeActivityAt($notice)?->getTimestamp() ?? 0;
            })
            ->map(function (SavedNotice $notice): array {
                $latestActivityAt = $this->latestSavedNoticeActivityAt($notice);

                return [
                    'id' => $notice->id,
                    'saved_notice_id' => $notice->id,
                    'title' => $notice->title,
                    'buyer_name' => $notice->buyer_name,
                    'reason' => $latestActivityAt instanceof CarbonInterface
                        ? sprintf('Ingen aktivitet siden %s', $this->attentionDateLabel($latestActivityAt))
                        : 'Ingen aktivitet registrert',
                    'secondary' => $this->attentionBuyerLabel($notice->buyer_name),
                    'show_url' => route('app.notices.saved.show', ['savedNotice' => $notice->id]),
                    'severity' => 'warning',
                    'metadata' => [
                        'last_activity_at' => $latestActivityAt?->toIso8601String(),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function attentionBuyerLabel(?string $buyerName): string
    {
        return sprintf('Oppdragsgiver: %s', $buyerName ?: 'Ikke registrert');
    }

    private function attentionDateLabel(CarbonInterface $date): string
    {
        return $date->copy()->locale('nb')->translatedFormat('j. M Y');
    }

    private function attentionDaysUntil(CarbonInterface $date): int
    {
        return (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }

    private function attentionDeadlineReason(string $deadlineTypeLabel, int $daysUntil): string
    {
        if ($daysUntil < 0) {
            return sprintf('%s forfalt for %d dager siden', $deadlineTypeLabel, abs($daysUntil));
        }

        if ($daysUntil === 0) {
            return sprintf('%s i dag', $deadlineTypeLabel);
        }

        if ($daysUntil === 1) {
            return sprintf('%s i morgen', $deadlineTypeLabel);
        }

        return sprintf('%s om %d dager', $deadlineTypeLabel, $daysUntil);
    }

    private function buildDeadlineItems(Collection $notices): array
    {
        $items = $notices
            ->flatMap(fn (SavedNotice $notice): Collection => collect($this->deadlineEntriesForNotice($notice)))
            ->sortBy(function (array $item): string {
                return sprintf('%s-%s', $item['date'], $item['id']);
            })
            ->values();

        return $items->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deadlineEntriesForNotice(SavedNotice $notice): array
    {
        $definitions = [
            'deadline' => 'Frist',
            'questions_deadline_at' => 'Spørsmålsfrist',
            'questions_rfi_deadline_at' => 'Spørsmål / RFI',
            'rfi_submission_deadline_at' => 'RFI-innlevering',
            'questions_rfp_deadline_at' => 'Spørsmål / RFP',
            'rfp_submission_deadline_at' => 'RFP-innlevering',
            'award_date_at' => 'Tildeling',
        ];

        $entries = [];

        foreach ($definitions as $attribute => $label) {
            $value = $notice->{$attribute};

            if ($value === null) {
                continue;
            }

            $date = $value instanceof CarbonInterface ? $value : now()->parse($value);

            $entries[] = [
                'id' => $notice->id.':'.$attribute,
                'saved_notice_id' => $notice->id,
                'title' => $notice->title,
                'buyer_name' => $notice->buyer_name,
                'deadline_type' => $attribute,
                'deadline_type_label' => $label,
                'date' => $date->toIso8601String(),
                'date_key' => $date->toDateString(),
                'bid_manager_name' => $notice->bidManager?->name,
                'phase_label' => $notice->bid_status_label,
                'show_url' => route('app.notices.saved.show', ['savedNotice' => $notice->id]),
                'severity' => $date->lessThan(now()->startOfDay()->addDays(5)) ? 'warning' : 'neutral',
            ];
        }

        foreach ($notice->businessReviews as $businessReview) {
            $date = $businessReview->business_review_at;

            if ($date === null) {
                continue;
            }

            $entries[] = [
                'id' => $notice->id.':business_review:'.$businessReview->id,
                'saved_notice_id' => $notice->id,
                'title' => $notice->title,
                'buyer_name' => $notice->buyer_name,
                'deadline_type' => 'business_review',
                'deadline_type_label' => 'Business Review',
                'date' => $date->toDateString(),
                'date_key' => $date->toDateString(),
                'bid_manager_name' => $notice->bidManager?->name,
                'phase_label' => $notice->bid_status_label,
                'show_url' => route('app.notices.saved.show', ['savedNotice' => $notice->id]),
                'severity' => $date->lessThan(now()->startOfDay()->addDays(5)) ? 'warning' : 'neutral',
            ];
        }

        return $entries;
    }

    /**
     * @param  Collection<int, SavedNotice>  $activeNotices
     * @param  Collection<int, SavedNotice>  $archivedNotices
     * @param  Collection<int, SavedNotice>  $trendArchivedNotices
     * @return array<string, mixed>
     */
    private function resolveBidQualitySummary(Collection $activeNotices, Collection $archivedNotices, Collection $trendArchivedNotices): array
    {
        $activeCount = $activeNotices->count();
        $activeWithBidManager = $activeNotices->whereNotNull('bid_manager_user_id')->count();
        $activeWithOpportunityOwner = $activeNotices->whereNotNull('opportunity_owner_user_id')->count();
        $staleActiveCount = $activeNotices->filter(function (SavedNotice $notice): bool {
            $latestActivityAt = $this->latestSavedNoticeActivityAt($notice);

            return $latestActivityAt === null || $latestActivityAt->lessThan(now()->subDays(7)->startOfDay());
        })->count();

        $recentClosedNotices = $archivedNotices->values();
        $recentClosedCount = $recentClosedNotices->count();
        $wonCount = $recentClosedNotices->where('history_type', SavedNotice::HISTORY_TYPE_WON)->count();
        $lostCount = $recentClosedNotices->where('history_type', SavedNotice::HISTORY_TYPE_LOST)->count();
        $abortedCount = $recentClosedNotices->where('history_type', SavedNotice::HISTORY_TYPE_ABORTED)->count();
        $noGoCount = $recentClosedNotices->where('history_type', SavedNotice::HISTORY_TYPE_NO_GO)->count();
        $winLossCount = $wonCount + $lostCount;
        $medianCycleTrendSeries = $this->buildMedianCycleTrendSeries($trendArchivedNotices);

        $outcomeSubtitle = sprintf(
            'Vunnet %d · Tapt %d · Avbrutt %d · NoGo %d',
            $wonCount,
            $lostCount,
            $abortedCount,
            $noGoCount,
        );

        return [
            'title' => 'Bid-kvalitet og styring',
            'subtitle' => 'Objektive styringsmål for ansvar, flyt og utfall i cockpit-skopet.',
            'items' => [
                $this->buildBidQualityMetric(
                    'bid_manager_coverage',
                    'Aktive saker med bid-manager',
                    $activeCount > 0 ? round(($activeWithBidManager / $activeCount) * 100, 1) : null,
                    '%',
                    $activeCount > 0
                        ? sprintf('%d av %d aktive saker har operativt ansvar satt.', $activeWithBidManager, $activeCount)
                        : 'Ingen aktive saker i cockpit-skopet akkurat nå.',
                    'Andel aktive saker i cockpit-skopet med bid_manager_user_id satt.',
                    ['bid_manager', 'management'],
                    'nå-situasjon',
                    $this->qualitySeverityForHigherIsBetter(
                        $activeCount > 0 ? round(($activeWithBidManager / $activeCount) * 100, 1) : null,
                        90,
                        75,
                    ),
                    [
                        'numerator' => $activeWithBidManager,
                        'denominator' => $activeCount,
                    ],
                ),
                $this->buildBidQualityMetric(
                    'opportunity_owner_coverage',
                    'Aktive saker med kommersiell eier',
                    $activeCount > 0 ? round(($activeWithOpportunityOwner / $activeCount) * 100, 1) : null,
                    '%',
                    $activeCount > 0
                        ? sprintf('%d av %d aktive saker har kommersiell eier satt.', $activeWithOpportunityOwner, $activeCount)
                        : 'Ingen aktive saker i cockpit-skopet akkurat nå.',
                    'Andel aktive saker i cockpit-skopet med opportunity_owner_user_id satt.',
                    ['commercial_owner', 'management'],
                    'nå-situasjon',
                    $this->qualitySeverityForHigherIsBetter(
                        $activeCount > 0 ? round(($activeWithOpportunityOwner / $activeCount) * 100, 1) : null,
                        90,
                        75,
                    ),
                    [
                        'numerator' => $activeWithOpportunityOwner,
                        'denominator' => $activeCount,
                    ],
                ),
                $this->buildBidQualityMetric(
                    'inactive_active_share_7d',
                    'Aktive saker uten aktivitet siste 7 dager',
                    $activeCount > 0 ? round(($staleActiveCount / $activeCount) * 100, 1) : null,
                    '%',
                    $activeCount > 0
                        ? sprintf('%d av %d aktive saker har ikke hatt aktivitet siste 7 dager.', $staleActiveCount, $activeCount)
                        : 'Ingen aktive saker i cockpit-skopet akkurat nå.',
                    'Andel aktive saker der siste aktivitet er eldre enn 7 dager eller mangler helt.',
                    ['bid_manager', 'commercial_owner', 'management'],
                    'nå-situasjon',
                    $this->qualitySeverityForLowerIsBetter(
                        $activeCount > 0 ? round(($staleActiveCount / $activeCount) * 100, 1) : null,
                        10,
                        20,
                    ),
                    [
                        'numerator' => $staleActiveCount,
                        'denominator' => $activeCount,
                    ],
                ),
                $this->buildBidQualityTrendMetric(
                    'median_cycle_time_trend_12m',
                    'Varighet på avsluttede saker',
                    'Utvikling siste 12 måneder',
                    'Median antall dager fra created_at til archived_at per måned for avsluttede saker med gyldige datoer.',
                    ['bid_manager', 'commercial_owner', 'management'],
                    'siste 12 måneder',
                    'neutral',
                    $medianCycleTrendSeries,
                ),
                $this->buildBidQualityMetric(
                    'win_rate_90d',
                    'Win rate blant vunnet og tapt',
                    $winLossCount > 0 ? round(($wonCount / $winLossCount) * 100, 1) : null,
                    '%',
                    $winLossCount > 0
                        ? sprintf('%d av %d saker med utfallet vunnet eller tapt endte som vunnet.', $wonCount, $winLossCount)
                        : 'Ingen avsluttede saker med utfallet vunnet eller tapt i de siste 90 dagene.',
                    'Vunnet / (Vunnet + Tapt) blant avsluttede saker med history_type i {won, lost} siste 90 dager.',
                    ['commercial_owner', 'management'],
                    'siste 90 dager',
                    $this->qualitySeverityForHigherIsBetter(
                        $winLossCount > 0 ? round(($wonCount / $winLossCount) * 100, 1) : null,
                        50,
                        30,
                    ),
                    [
                        'numerator' => $wonCount,
                        'denominator' => $winLossCount,
                    ],
                ),
                $this->buildBidQualityMetric(
                    'outcome_distribution_90d',
                    'Utfallsfordeling siste 90 dager',
                    $recentClosedCount,
                    'saker',
                    $outcomeSubtitle,
                    'Fordeling av avsluttede saker etter history_type de siste 90 dagene.',
                    ['commercial_owner', 'management'],
                    'siste 90 dager',
                    'neutral',
                    [
                        'breakdown' => [
                            [
                                'key' => SavedNotice::HISTORY_TYPE_WON,
                                'label' => 'Vunnet',
                                'count' => $wonCount,
                                'share' => $recentClosedCount > 0 ? round(($wonCount / $recentClosedCount) * 100, 1) : null,
                            ],
                            [
                                'key' => SavedNotice::HISTORY_TYPE_LOST,
                                'label' => 'Tapt',
                                'count' => $lostCount,
                                'share' => $recentClosedCount > 0 ? round(($lostCount / $recentClosedCount) * 100, 1) : null,
                            ],
                            [
                                'key' => SavedNotice::HISTORY_TYPE_ABORTED,
                                'label' => 'Avbrutt',
                                'count' => $abortedCount,
                                'share' => $recentClosedCount > 0 ? round(($abortedCount / $recentClosedCount) * 100, 1) : null,
                            ],
                            [
                                'key' => SavedNotice::HISTORY_TYPE_NO_GO,
                                'label' => 'NoGo',
                                'count' => $noGoCount,
                                'share' => $recentClosedCount > 0 ? round(($noGoCount / $recentClosedCount) * 100, 1) : null,
                            ],
                        ],
                    ],
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function buildBidQualityMetric(
        string $key,
        string $title,
        mixed $value,
        string $unit,
        string $subtitle,
        string $definition,
        array $audience,
        string $trendBasis,
        string $severity,
        array $extra = [],
    ): array {
        return array_merge([
            'key' => $key,
            'title' => $title,
            'value' => $value,
            'unit' => $unit,
            'subtitle' => $subtitle,
            'definition' => $definition,
            'audience' => $audience,
            'trend_basis' => $trendBasis,
            'severity' => $severity,
        ], $extra);
    }

    /**
     * @param  array<int, array<string, mixed>>  $series
     * @param  array<int, string>  $audience
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function buildBidQualityTrendMetric(
        string $key,
        string $title,
        string $subtitle,
        string $definition,
        array $audience,
        string $period,
        string $severity,
        array $series,
        array $extra = [],
    ): array {
        return array_merge([
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'definition' => $definition,
            'audience' => $audience,
            'period' => $period,
            'severity' => $severity,
            'series' => $series,
        ], $extra);
    }

    private function qualitySeverityForHigherIsBetter(?float $value, float $goodThreshold, float $warningThreshold): string
    {
        if ($value === null) {
            return 'neutral';
        }

        if ($value >= $goodThreshold) {
            return 'success';
        }

        if ($value >= $warningThreshold) {
            return 'warning';
        }

        return 'danger';
    }

    private function qualitySeverityForLowerIsBetter(?float $value, float $goodThreshold, float $warningThreshold): string
    {
        if ($value === null) {
            return 'neutral';
        }

        if ($value <= $goodThreshold) {
            return 'success';
        }

        if ($value <= $warningThreshold) {
            return 'warning';
        }

        return 'danger';
    }

    /**
     * @param  array<int, float|int>  $values
     */
    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn ($value): bool => $value !== null));

        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        $count = count($values);
        $middleIndex = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round((float) $values[$middleIndex], 1);
        }

        return round(((float) $values[$middleIndex - 1] + (float) $values[$middleIndex]) / 2, 1);
    }

    /**
     * @param  Collection<int, SavedNotice>  $archivedNotices
     * @return array<int, array<string, mixed>>
     */
    private function buildMedianCycleTrendSeries(Collection $archivedNotices): array
    {
        return $archivedNotices
            ->filter(function (SavedNotice $notice): bool {
                return $notice->created_at instanceof CarbonInterface && $notice->archived_at instanceof CarbonInterface;
            })
            ->groupBy(function (SavedNotice $notice): string {
                return $notice->archived_at->format('Y-m');
            })
            ->sortKeys()
            ->map(function (Collection $monthNotices, string $monthKey): array {
                $durations = $monthNotices
                    ->map(fn (SavedNotice $notice): float => round($notice->created_at->diffInMinutes($notice->archived_at) / 1440, 1))
                    ->values()
                    ->all();
                $median = $this->median($durations);
                $month = Carbon::parse($monthKey.'-01 00:00:00');

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $this->qualityMonthLabel($month),
                    'median_days' => $median,
                    'sample_size' => count($durations),
                ];
            })
            ->values()
            ->all();
    }

    private function qualityMonthLabel(CarbonInterface $date): string
    {
        return ucfirst(rtrim($date->format('M'), '.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveResponsibilityActivitySummary(User $user, int $customerId, Collection $notices): array
    {
        $bidManagerAssignments = $notices->whereNotNull('bid_manager_user_id');
        $opportunityOwnerAssignments = $notices->whereNotNull('opportunity_owner_user_id');

        $recentComments = $notices->flatMap(function (SavedNotice $notice): Collection {
            return $notice->phaseComments
                ->filter(function (SavedNoticePhaseComment $comment): bool {
                    return $comment->created_at !== null && $comment->created_at->greaterThanOrEqualTo(now()->subDays(14));
                })
                ->map(function (SavedNoticePhaseComment $comment) use ($notice): array {
                    return [
                        'id' => $comment->id,
                        'notice_id' => $notice->id,
                        'title' => $notice->title,
                        'created_at' => optional($comment->created_at)?->toIso8601String(),
                    ];
                });
        });
        $recentSubmissions = $notices->flatMap(function (SavedNotice $notice): Collection {
            return $notice->submissions
                ->filter(function ($submission): bool {
                    return $submission->submitted_at !== null && $submission->submitted_at->greaterThanOrEqualTo(now()->subDays(14));
                })
                ->map(function ($submission) use ($notice): array {
                    return [
                        'id' => $submission->id,
                        'notice_id' => $notice->id,
                        'title' => $notice->title,
                        'created_at' => optional($submission->submitted_at)?->toIso8601String(),
                    ];
                });
        });

        $activityCount = $recentComments->count() + $recentSubmissions->count() + $notices->filter(function (SavedNotice $notice): bool {
            return $notice->updated_at !== null && $notice->updated_at->greaterThanOrEqualTo(now()->subDays(14));
        })->count();
        $lastActivityNotice = $notices
            ->sortByDesc(fn (SavedNotice $notice): mixed => $this->latestSavedNoticeActivityAt($notice))
            ->first();

        return [
            'bid_manager_cases_count' => $bidManagerAssignments->count(),
            'opportunity_owner_cases_count' => $opportunityOwnerAssignments->count(),
            'saved_watch_lists_count' => $this->savedWatchListsCount($user, $customerId),
            'contributor_cases_count' => $this->contributorCasesCount($notices),
            'activity' => [
                'last_activity_at' => $lastActivityNotice instanceof SavedNotice
                    ? $this->latestSavedNoticeActivityAt($lastActivityNotice)?->toIso8601String()
                    : null,
                'activity_count_14_days' => $activityCount,
                'inactive_7_days_count' => $notices->filter(function (SavedNotice $notice): bool {
                    $latestActivityAt = $this->latestSavedNoticeActivityAt($notice);

                    return $latestActivityAt === null || $latestActivityAt->lessThan(now()->subDays(7));
                })->count(),
            ],
        ];
    }

    private function latestSavedNoticeActivityAt(SavedNotice $notice): ?CarbonInterface
    {
        $activityDates = collect([
            $notice->updated_at,
            $notice->phaseComments->max('created_at'),
            $notice->submissions->max('submitted_at'),
        ])->filter();

        if ($activityDates->isEmpty()) {
            return null;
        }

        return $activityDates->sortDesc()->first();
    }

    private function pipelineStageCount(array $pipeline, string $stageKey): int
    {
        foreach ($pipeline['stages'] as $stage) {
            if ($stage['key'] === $stageKey) {
                return (int) $stage['count'];
            }
        }

        return 0;
    }

    private function formatConversionRate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round(($numerator / $denominator) * 100, 1);
    }

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

    private function resolveStats(User $user, int $customerId): array
    {
        $worklistCount = (clone $this->activeSavedNoticeQuery($user))->count();
        $activeWatchProfileCount = (clone $this->activeAccessibleWatchProfilesQuery($user, $customerId))->count();

        return [
            'worklist' => [
                'value' => $worklistCount,
                'href' => route('app.notices.index', ['mode' => 'saved']),
                'is_available' => true,
            ],
            'activeWatchProfiles' => [
                'value' => $activeWatchProfileCount,
                'href' => route('app.watch-profiles.index'),
                'is_available' => true,
            ],
        ];
    }

    private function resolveRecentWorklistItems(User $user): array
    {
        return $this->activeSavedNoticeQuery($user)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (SavedNotice $notice): array => [
                'id' => $notice->id,
                'title' => $notice->title,
                'buyer_name' => $notice->buyer_name,
                'saved_at' => optional($notice->created_at)?->toIso8601String(),
                'href' => route('app.notices.index', ['mode' => 'saved']),
            ])
            ->all();
    }

    private function resolveWatchProfileSummary(User $user, int $customerId): array
    {
        $profiles = $this->activeAccessibleWatchProfilesQuery($user, $customerId)
            ->with([
                'user:id,name',
                'department:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderBy('name')
            ->get();

        return [
            'active_personal_count' => $profiles->whereNotNull('user_id')->count(),
            'active_department_count' => $profiles->whereNotNull('department_id')->count(),
            'recent_profiles' => $profiles
                ->take(3)
                ->map(fn (WatchProfile $profile): array => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'owner_scope' => $profile->ownerScope(),
                    'owner_reference' => $profile->isUserOwned()
                        ? ($profile->user?->name ?? 'Unknown user')
                        : ($profile->department?->name ?? 'Unknown department'),
                ])
                ->all(),
            'href' => route('app.watch-profiles.index'),
        ];
    }

    private function resolveQuickLinks(User $user): array
    {
        return array_values(array_filter([
            [
                'key' => 'procurements',
                'label' => 'Gå til kunngjøringer',
                'href' => route('app.notices.index'),
            ],
            [
                'key' => 'worklist',
                'label' => 'Åpne arbeidsliste',
                'href' => route('app.notices.index', ['mode' => 'saved']),
            ],
            [
                'key' => 'watchProfiles',
                'label' => 'Gå til Watch Profiles',
                'href' => route('app.watch-profiles.index'),
            ],
        ]));
    }

    private function savedNoticePipelineSummary(User $user): array
    {
        $counts = $this->savedNoticeAccess->visibleQueryFor($user)
            ->select('bid_status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('bid_status')
            ->get()
            ->reduce(function (array $carry, SavedNotice $notice): array {
                $status = in_array($notice->bid_status, SavedNotice::BID_STATUSES, true)
                    ? $notice->bid_status
                    : SavedNotice::BID_STATUS_DISCOVERED;

                $carry[$status] = ($carry[$status] ?? 0) + (int) $notice->aggregate;

                return $carry;
            }, []);

        return $this->buildSavedNoticePipelineSummary($counts);
    }

    private function buildSavedNoticePipelineSummary(array $counts): array
    {
        $normalizedCounts = [];

        foreach (SavedNotice::BID_STATUSES as $status) {
            $normalizedCounts[$status] = (int) ($counts[$status] ?? 0);
        }

        $activeStatuses = [
            SavedNotice::BID_STATUS_DISCOVERED,
            SavedNotice::BID_STATUS_QUALIFYING,
            SavedNotice::BID_STATUS_GO_NO_GO,
            SavedNotice::BID_STATUS_IN_PROGRESS,
            SavedNotice::BID_STATUS_SUBMITTED,
            SavedNotice::BID_STATUS_NEGOTIATION,
        ];
        $outcomeStatuses = [
            SavedNotice::BID_STATUS_WON,
            SavedNotice::BID_STATUS_LOST,
            SavedNotice::BID_STATUS_NO_GO,
            SavedNotice::BID_STATUS_WITHDRAWN,
            SavedNotice::BID_STATUS_ARCHIVED,
        ];

        return [
            'total_count' => array_sum($normalizedCounts),
            'active_total_count' => array_sum(array_intersect_key($normalizedCounts, array_flip($activeStatuses))),
            'outcome_total_count' => array_sum(array_intersect_key($normalizedCounts, array_flip($outcomeStatuses))),
            'focus_counts' => [
                'submitted' => $normalizedCounts[SavedNotice::BID_STATUS_SUBMITTED],
                'negotiation' => $normalizedCounts[SavedNotice::BID_STATUS_NEGOTIATION],
                'won' => $normalizedCounts[SavedNotice::BID_STATUS_WON],
            ],
            'stages' => array_map(fn (string $status): array => [
                'key' => $status,
                'label' => SavedNotice::BID_STATUS_LABELS[$status],
                'count' => $normalizedCounts[$status],
            ], $activeStatuses),
            'outcomes' => array_map(fn (string $status): array => [
                'key' => $status,
                'label' => SavedNotice::BID_STATUS_LABELS[$status],
                'count' => $normalizedCounts[$status],
            ], $outcomeStatuses),
        ];
    }

    private function activeSavedNoticeQuery(User $user): Builder
    {
        return $this->savedNoticeAccess->visibleQueryFor($user)
            ->whereNull('archived_at');
    }

    private function activeAccessibleWatchProfilesQuery(User $user, int $customerId): Builder
    {
        return WatchProfile::query()
            ->accessibleTo($user)
            ->where('customer_id', $customerId)
            ->active();
    }
}
