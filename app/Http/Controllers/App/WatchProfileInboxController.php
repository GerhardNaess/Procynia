<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\WatchProfileInboxRecord;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WatchProfileInboxController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function userInbox(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $records = $this->userInboxQuery($user, $customerId)->get();
        $savedNoticeIds = $this->activeSavedNoticeIds($customerId, $records);

        return Inertia::render('App/Inbox/Index', [
            'scope' => 'user',
            'title' => 'Min innboks',
            'description' => 'Live Doffin-treff fanget opp av dine personlige watch profiles.',
            'records' => $records
                ->map(fn (WatchProfileInboxRecord $record): array => $this->inboxListItem($record, $savedNoticeIds))
                ->all(),
            'switchLinks' => [
                'user' => route('app.inbox.user'),
                'department' => $user->department_id !== null ? route('app.inbox.department') : null,
            ],
        ]);
    }

    public function departmentInbox(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);

        abort_unless($user->department_id !== null, 403);
        $records = $this->departmentInboxQuery($user, $customerId)->get();
        $savedNoticeIds = $this->activeSavedNoticeIds($customerId, $records);

        return Inertia::render('App/Inbox/Index', [
            'scope' => 'department',
            'title' => 'Avdelingsinnboks',
            'description' => 'Live Doffin-treff fanget opp av watch profiles for din avdeling.',
            'records' => $records
                ->map(fn (WatchProfileInboxRecord $record): array => $this->inboxListItem($record, $savedNoticeIds))
                ->all(),
            'switchLinks' => [
                'user' => route('app.inbox.user'),
                'department' => route('app.inbox.department'),
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

    private function userInboxQuery(User $user, int $customerId)
    {
        return WatchProfileInboxRecord::query()
            ->userInbox($user)
            ->where('customer_id', $customerId)
            ->with(['watchProfile:id,name'])
            ->orderByDesc('discovered_at')
            ->orderByDesc('relevance_score')
            ->orderByDesc('id');
    }

    private function departmentInboxQuery(User $user, int $customerId)
    {
        return WatchProfileInboxRecord::query()
            ->departmentInbox($user)
            ->where('customer_id', $customerId)
            ->with(['watchProfile:id,name'])
            ->orderByDesc('discovered_at')
            ->orderByDesc('relevance_score')
            ->orderByDesc('id');
    }

    private function inboxListItem(WatchProfileInboxRecord $record, array $savedNoticeIds = []): array
    {
        $payload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $description = trim((string) ($payload['description'] ?? ''));
        $cpvCodes = collect([
            ...((array) ($payload['cpvCodes'] ?? [])),
            $payload['mainCpvCode'] ?? null,
        ])
            ->filter(fn (mixed $cpv): bool => is_scalar($cpv) && trim((string) $cpv) !== '')
            ->map(fn (string|int|float|bool $cpv): string => trim((string) $cpv))
            ->values();

        return [
            'id' => $record->id,
            'notice_id' => $record->doffin_notice_id,
            'title' => $record->title,
            'buyer_name' => $record->buyer_name,
            'summary' => $description !== '' ? Str::limit(Str::squish($description), 220) : null,
            'publication_date' => optional($record->publication_date)?->toIso8601String(),
            'deadline' => optional($record->deadline)?->toIso8601String(),
            'status' => $payload['status'] ?? null,
            'cpv_code' => $cpvCodes->first(),
            'external_url' => $record->external_url,
            'is_saved' => in_array($record->doffin_notice_id, $savedNoticeIds, true),
            'relevance_score' => $record->relevance_score,
            'discovered_at' => optional($record->discovered_at)?->toIso8601String(),
            'watch_profile_name' => $record->watchProfile?->name,
            'watch_profile_id' => $record->watch_profile_id,
        ];
    }

    private function activeSavedNoticeIds(int $customerId, Collection $records): array
    {
        $noticeIds = $records
            ->pluck('doffin_notice_id')
            ->filter(fn (mixed $noticeId): bool => is_string($noticeId) && trim($noticeId) !== '')
            ->map(fn (string $noticeId): string => trim($noticeId))
            ->unique()
            ->values()
            ->all();

        if ($noticeIds === []) {
            return [];
        }

        return SavedNotice::query()
            ->where('customer_id', $customerId)
            ->whereNull('archived_at')
            ->whereIn('external_id', $noticeIds)
            ->pluck('external_id')
            ->map(fn (mixed $noticeId): string => (string) $noticeId)
            ->all();
    }
}
