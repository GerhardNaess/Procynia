<?php

namespace App\Services\Doffin;

use App\Models\Department;
use App\Models\Notice;
use App\Models\NoticeAttention;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DoffinNoticeAttentionService
{
    public function refreshForNotice(Notice $notice): void
    {
        $notice->loadMissing('attentions');

        $visibleDepartmentIds = collect($notice->visible_to_departments ?? [])
            ->map(fn (mixed $departmentId): int => (int) $departmentId)
            ->filter(fn (int $departmentId): bool => $departmentId > 0)
            ->unique()
            ->values();

        $departmentScores = is_array($notice->department_scores) ? $notice->department_scores : [];
        $departmentCustomerMap = Department::query()
            ->whereIn('id', $visibleDepartmentIds->all())
            ->pluck('customer_id', 'id')
            ->mapWithKeys(static fn (mixed $customerId, mixed $departmentId): array => [(int) $departmentId => (int) $customerId])
            ->all();
        $now = now();

        DB::transaction(function () use ($notice, $visibleDepartmentIds, $departmentScores, $departmentCustomerMap, $now): void {
            $existingAttentions = $notice->attentions()
                ->get()
                ->keyBy('department_id');

            foreach ($visibleDepartmentIds as $departmentId) {
                $customerId = $departmentCustomerMap[$departmentId] ?? null;

                if ($customerId === null) {
                    continue;
                }

                $scoreData = data_get($departmentScores, (string) $departmentId, []);
                $attention = $existingAttentions->get($departmentId);

                if ($attention instanceof NoticeAttention) {
                    $attention->fill([
                        'customer_id' => $customerId,
                        'department_score' => (int) data_get($scoreData, 'score', 0),
                        'relevance_level' => data_get($scoreData, 'level'),
                        'last_seen_at' => $now,
                    ])->save();

                    continue;
                }

                NoticeAttention::query()->create([
                    'notice_id' => $notice->id,
                    'customer_id' => $customerId,
                    'department_id' => $departmentId,
                    'department_score' => (int) data_get($scoreData, 'score', 0),
                    'relevance_level' => data_get($scoreData, 'level'),
                    'is_new' => true,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'read_at' => null,
                    'read_by_user_id' => null,
                ]);
            }

            $deleteQuery = $notice->attentions();

            if ($visibleDepartmentIds->isEmpty()) {
                $deleteQuery->delete();

                return;
            }

            $deleteQuery
                ->whereNotIn('department_id', $visibleDepartmentIds->all())
                ->delete();
        });
    }

    public function markAsRead(Notice $notice, Department $department, ?User $actor = null): void
    {
        $attention = NoticeAttention::query()
            ->where('notice_id', $notice->id)
            ->where('customer_id', $department->customer_id)
            ->where('department_id', $department->id)
            ->first();

        if (! $attention instanceof NoticeAttention) {
            return;
        }

        $attention->fill([
            'is_new' => false,
            'read_at' => now(),
            'read_by_user_id' => $actor?->id,
        ])->save();
    }
}
