<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);

        return Inertia::render('App/Dashboard/Index', [
            'stats' => $this->resolveStats($user, $customerId),
            'recentInboxItems' => $this->resolveRecentInboxItems($user, $customerId),
            'recentWorklistItems' => $this->resolveRecentWorklistItems($customerId),
            'watchProfileSummary' => $this->resolveWatchProfileSummary($user, $customerId),
            'quickLinks' => $this->resolveQuickLinks($user),
        ]);
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
        $userInboxCount = (clone $this->userInboxQuery($user, $customerId))->count();
        $departmentInboxAvailable = $user->department_id !== null;
        $departmentInboxCount = $departmentInboxAvailable
            ? (clone $this->departmentInboxQuery($user, $customerId))->count()
            : 0;
        $worklistCount = (clone $this->activeSavedNoticeQuery($customerId))->count();
        $activeWatchProfileCount = (clone $this->activeAccessibleWatchProfilesQuery($user, $customerId))->count();

        return [
            'userInbox' => [
                'value' => $userInboxCount,
                'href' => route('app.inbox.user'),
                'is_available' => true,
            ],
            'departmentInbox' => [
                'value' => $departmentInboxCount,
                'href' => $departmentInboxAvailable ? route('app.inbox.department') : null,
                'is_available' => $departmentInboxAvailable,
            ],
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

    private function resolveRecentInboxItems(User $user, int $customerId): array
    {
        $personalItems = $this->userInboxQuery($user, $customerId)
            ->limit(5)
            ->get()
            ->map(fn (WatchProfileInboxRecord $record): array => $this->recentInboxItem($record, 'Min inbox', route('app.inbox.user')))
            ->all();

        $departmentItems = $user->department_id === null
            ? []
            : $this->departmentInboxQuery($user, $customerId)
                ->limit(5)
                ->get()
                ->map(fn (WatchProfileInboxRecord $record): array => $this->recentInboxItem($record, 'Avdeling', route('app.inbox.department')))
                ->all();

        return collect([...$personalItems, ...$departmentItems])
            ->sortByDesc(function (array $item): int {
                $timestamp = strtotime((string) ($item['discovered_at'] ?? '')) ?: 0;

                return ($timestamp * 1000000) + ((int) ($item['relevance_score'] ?? 0) * 1000) + (int) $item['id'];
            })
            ->take(5)
            ->values()
            ->all();
    }

    private function recentInboxItem(WatchProfileInboxRecord $record, string $sourceLabel, string $href): array
    {
        return [
            'id' => $record->id,
            'title' => $record->title,
            'buyer_name' => $record->buyer_name,
            'publication_date' => optional($record->publication_date)?->toIso8601String(),
            'discovered_at' => optional($record->discovered_at)?->toIso8601String(),
            'source_label' => $sourceLabel,
            'href' => $href,
            'relevance_score' => $record->relevance_score,
        ];
    }

    private function resolveRecentWorklistItems(int $customerId): array
    {
        return $this->activeSavedNoticeQuery($customerId)
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
                'label' => 'Gå til anskaffelser',
                'href' => route('app.notices.index'),
            ],
            [
                'key' => 'userInbox',
                'label' => 'Åpne min inbox',
                'href' => route('app.inbox.user'),
            ],
            $user->department_id !== null ? [
                'key' => 'departmentInbox',
                'label' => 'Åpne avdelingsinnboks',
                'href' => route('app.inbox.department'),
            ] : null,
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

    private function userInboxQuery(User $user, int $customerId): Builder
    {
        return WatchProfileInboxRecord::query()
            ->userInbox($user)
            ->where('customer_id', $customerId)
            ->orderByDesc('discovered_at')
            ->orderByDesc('relevance_score')
            ->orderByDesc('id');
    }

    private function departmentInboxQuery(User $user, int $customerId): Builder
    {
        return WatchProfileInboxRecord::query()
            ->departmentInbox($user)
            ->where('customer_id', $customerId)
            ->orderByDesc('discovered_at')
            ->orderByDesc('relevance_score')
            ->orderByDesc('id');
    }

    private function activeSavedNoticeQuery(int $customerId): Builder
    {
        return SavedNotice::query()
            ->where('customer_id', $customerId)
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
