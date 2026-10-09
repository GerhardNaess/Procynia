<?php

namespace App\Services\InfoCenter;

use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One Anbud aksjon as Oppfølging shows it — the list under every view, and the Anbud card under
 * «Mine oppgaver». One shape, so the two can never describe the same aksjon differently.
 *
 * The item must already have been reached through SavedNoticeAccessService.
 */
class InfoItemPayload
{
    /** @return array<string, mixed> */
    public function for(SavedNoticeInfoItem $infoItem, int $customerId): array
    {
        $savedNotice = $infoItem->savedNotice;
        $subject = trim((string) ($infoItem->subject ?? ''));
        $actionUrl = $savedNotice ? route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]) : null;

        if (
            $savedNotice !== null
            && $infoItem->source_type === SavedNoticeInfoItem::SOURCE_TYPE_SAVED_NOTICE_AI_REQUIREMENT
            && $infoItem->source_id !== null
        ) {
            $actionUrl = route('app.ai.show', [
                'savedNotice' => $savedNotice->id,
                'requirement_id' => $infoItem->source_id,
            ]);
        }

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
            'action_url' => $actionUrl,
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
