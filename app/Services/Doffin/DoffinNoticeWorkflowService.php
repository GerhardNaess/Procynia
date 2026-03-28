<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\NoticeDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DoffinNoticeWorkflowService
{
    public function updateStatus(Notice $notice, string $toStatus, ?string $comment = null, ?User $actor = null): void
    {
        $fromStatus = $notice->internal_status;
        $normalizedComment = $this->normalizeComment($comment);
        $customerId = $actor?->customer_id;

        if ($fromStatus === $toStatus) {
            return;
        }

        if ($customerId === null) {
            throw new RuntimeException('Status updates require a customer-scoped user context.');
        }

        DB::transaction(function () use ($notice, $fromStatus, $toStatus, $normalizedComment, $actor, $customerId): void {
            $notice->fill([
                'internal_status' => $toStatus,
                'status_changed_at' => now(),
                'status_changed_by_user_id' => $actor?->id,
            ]);

            if ($actor !== null) {
                $notice->decision_by_user_id = $actor->id;
            }

            $notice->save();

            NoticeDecision::query()->create([
                'notice_id' => $notice->id,
                'customer_id' => $customerId,
                'user_id' => $actor?->id,
                'department_id' => $actor?->department_id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'comment' => $normalizedComment,
            ]);
        });

        $notice->refresh();
    }

    private function normalizeComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        $trimmed = trim($comment);

        return $trimmed === '' ? null : $trimmed;
    }
}
