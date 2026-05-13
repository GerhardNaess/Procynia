<?php

namespace App\Services\InfoCenter;

use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use Illuminate\Support\Str;

class RequirementResponsibilityTaskService
{
    /**
     * Keep one canonical info-center task in sync with the current assignee for a requirement.
     * Open tasks are updated in place, duplicates are collapsed, and removed assignees close the task.
     */
    public function syncRequirementTask(SavedNoticeAiRequirement $requirement, ?User $actor = null): ?SavedNoticeInfoItem
    {
        $requirement->loadMissing(['savedNotice', 'assignedUser']);

        $activeTasks = $this->taskQuery($requirement)
            ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
            ->orderByDesc('id')
            ->get();

        $task = $activeTasks->first();

        if ($requirement->assigned_user_id === null) {
            foreach ($activeTasks as $activeTask) {
                $this->closeTask($activeTask);
            }

            return null;
        }

        if ($task === null) {
            $task = new SavedNoticeInfoItem();
            $task->forceFill($this->openTaskAttributes($requirement, $actor));
            $task->save();
        } else {
            $task->forceFill($this->openTaskAttributes($requirement, $actor, $task));
            $task->save();
        }

        foreach ($activeTasks->skip(1) as $duplicateTask) {
            $this->closeTask($duplicateTask);
        }

        return $task->refresh();
    }

    private function taskQuery(SavedNoticeAiRequirement $requirement)
    {
        return SavedNoticeInfoItem::query()
            ->where('saved_notice_id', $requirement->saved_notice_id)
            ->where('type', SavedNoticeInfoItem::TYPE_AI_REQUIREMENT_RESPONSIBILITY)
            ->where('source_type', SavedNoticeInfoItem::SOURCE_TYPE_SAVED_NOTICE_AI_REQUIREMENT)
            ->where('source_id', $requirement->id);
    }

    private function openTaskAttributes(
        SavedNoticeAiRequirement $requirement,
        ?User $actor = null,
        ?SavedNoticeInfoItem $task = null,
    ): array {
        $savedNoticeTitle = Str::squish((string) ($requirement->savedNotice?->title ?? ''));
        $requirementText = Str::squish((string) ($requirement->requirement_text ?? ''));
        $subjectRequirementText = Str::limit($requirementText, 120);
        $subject = $subjectRequirementText !== ''
            ? sprintf('Besvar krav: %s', $subjectRequirementText)
            : 'Besvar krav';

        $bodyParts = [
            'Du er satt som ansvarlig for dette kravet.',
        ];

        if ($savedNoticeTitle !== '') {
            $bodyParts[] = sprintf('Sak: %s.', $savedNoticeTitle);
        }

        if ($requirementText !== '') {
            $bodyParts[] = sprintf('Krav: %s.', $requirementText);
        }

        return [
            'saved_notice_id' => $requirement->saved_notice_id,
            'type' => SavedNoticeInfoItem::TYPE_AI_REQUIREMENT_RESPONSIBILITY,
            'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL,
            'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL,
            'subject' => $subject,
            'body' => implode(' ', $bodyParts),
            'status' => SavedNoticeInfoItem::STATUS_OPEN,
            'requires_response' => false,
            'response_due_at' => null,
            'owner_user_id' => (int) $requirement->assigned_user_id,
            'created_by_user_id' => $task?->created_by_user_id ?? $actor?->id,
            'closed_at' => null,
            'closure_comment' => null,
            'source_type' => SavedNoticeInfoItem::SOURCE_TYPE_SAVED_NOTICE_AI_REQUIREMENT,
            'source_id' => $requirement->id,
        ];
    }

    private function closeTask(SavedNoticeInfoItem $task): void
    {
        $task->forceFill([
            'status' => SavedNoticeInfoItem::STATUS_CLOSED,
            'closed_at' => now(),
            'requires_response' => false,
            'response_due_at' => null,
        ])->save();
    }
}
