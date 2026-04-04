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
        $activeNotices = $this->dashboardActiveSavedNotices($user, $customerId);
        $cockpitScopeNotices = $this->cockpitScopeSavedNotices($user, $customerId);
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
            'pipeline_quality' => $this->resolvePipelineQualitySummary($activeNotices, $pipeline),
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
        $deadlineLimit = now()->addDays(5)->endOfDay();
        $deadlineSoonCount = collect($deadlineItems)
            ->filter(function (array $item) use ($deadlineLimit): bool {
                $date = Carbon::parse($item['date']);

                return $date->lessThanOrEqualTo($deadlineLimit);
            })
            ->pluck('saved_notice_id')
            ->unique()
            ->count();

        $missingBidManagerCount = $notices->whereNull('bid_manager_user_id')->count();
        $goNoGoCount = $notices->where('bid_status', SavedNotice::BID_STATUS_GO_NO_GO)->count();
        $inactiveSevenDaysCount = $notices->filter(function (SavedNotice $notice): bool {
            $latestActivityAt = $this->latestSavedNoticeActivityAt($notice);

            return $latestActivityAt === null || $latestActivityAt->lessThan(now()->subDays(7)->startOfDay());
        })->count();

        return [
            [
                'key' => 'deadline-soon',
                'title' => 'Frister innen 5 dager',
                'subtitle' => 'Saker med operative frister som nærmer seg eller er passert.',
                'count' => $deadlineSoonCount,
                'severity' => $deadlineSoonCount > 0 ? 'danger' : 'neutral',
                'href' => route('app.notices.index', ['mode' => 'saved', 'cockpit_scope' => 1]),
            ],
            [
                'key' => 'missing-bid-manager',
                'title' => 'Saker uten bid-manager',
                'subtitle' => 'Saker som mangler eksplisitt operativt ansvar.',
                'count' => $missingBidManagerCount,
                'severity' => $missingBidManagerCount > 0 ? 'warning' : 'neutral',
                'href' => route('app.notices.index', ['mode' => 'saved', 'cockpit_scope' => 1]),
            ],
            [
                'key' => 'go-no-go-pending',
                'title' => 'Go / No-Go uten beslutning',
                'subtitle' => 'Saker som står i beslutningsfasen uten endelig utfall.',
                'count' => $goNoGoCount,
                'severity' => $goNoGoCount > 0 ? 'warning' : 'neutral',
                'href' => route('app.notices.index', ['mode' => 'saved', 'cockpit_scope' => 1, 'bid_status' => SavedNotice::BID_STATUS_GO_NO_GO]),
            ],
            [
                'key' => 'inactive-seven-days',
                'title' => 'Uten aktivitet siste 7 dager',
                'subtitle' => 'Saker som ikke har fått kommentarer eller innsendinger nylig.',
                'count' => $inactiveSevenDaysCount,
                'severity' => $inactiveSevenDaysCount > 0 ? 'warning' : 'neutral',
                'href' => route('app.notices.index', ['mode' => 'saved', 'cockpit_scope' => 1]),
            ],
        ];
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
     * @param  array<int, array<string, mixed>>  $deadlineItems
     * @return array<string, mixed>
     */
    private function resolvePipelineQualitySummary(Collection $notices, array $pipeline): array
    {
        $stageRows = [];
        $stageOrder = [
            SavedNotice::BID_STATUS_DISCOVERED,
            SavedNotice::BID_STATUS_QUALIFYING,
            SavedNotice::BID_STATUS_GO_NO_GO,
            SavedNotice::BID_STATUS_IN_PROGRESS,
            SavedNotice::BID_STATUS_SUBMITTED,
            SavedNotice::BID_STATUS_NEGOTIATION,
        ];

        foreach ($stageOrder as $stageKey) {
            $stageNotices = $notices->where('bid_status', $stageKey);
            $averageHours = $stageNotices->isEmpty()
                ? null
                : round($stageNotices->avg(function (SavedNotice $notice): float {
                    $base = $notice->updated_at instanceof CarbonInterface
                        ? $notice->updated_at
                        : now();

                    return now()->diffInMinutes($base) / 60;
                }), 1);

            $stageRows[] = [
                'key' => $stageKey,
                'label' => SavedNotice::BID_STATUS_LABELS[$stageKey] ?? $stageKey,
                'count' => $this->pipelineStageCount($pipeline, $stageKey),
                'average_age_hours' => $averageHours,
            ];
        }

        $qualifyingCount = $this->pipelineStageCount($pipeline, SavedNotice::BID_STATUS_QUALIFYING);
        $goNoGoCount = $this->pipelineStageCount($pipeline, SavedNotice::BID_STATUS_GO_NO_GO);
        $inProgressCount = $this->pipelineStageCount($pipeline, SavedNotice::BID_STATUS_IN_PROGRESS);

        $warning = null;

        if ($goNoGoCount > 0 && $goNoGoCount >= max($qualifyingCount, $inProgressCount)) {
            $warning = [
                'label' => 'Flest saker stopper i Go / No-Go',
                'count' => $goNoGoCount,
                'severity' => 'warning',
            ];
        }

        return [
            'conversions' => [
                [
                    'key' => 'qualifying_to_go_no_go',
                    'label' => 'Kvalifiseres -> Go / No-Go',
                    'value' => $this->formatConversionRate($goNoGoCount, $qualifyingCount + $goNoGoCount),
                ],
                [
                    'key' => 'go_no_go_to_in_progress',
                    'label' => 'Go / No-Go -> Under arbeid',
                    'value' => $this->formatConversionRate($inProgressCount, $goNoGoCount + $inProgressCount),
                ],
            ],
            'stages' => $stageRows,
            'warning' => $warning,
        ];
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
                'last_comment_at' => $recentComments->sortByDesc('created_at')->first()['created_at'] ?? null,
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
