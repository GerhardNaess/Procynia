<?php

namespace App\Filament\Resources\NoticeResource\Pages;

use App\Filament\Resources\NoticeResource;
use App\Models\Notice;
use App\Models\User;
use App\Services\Doffin\DoffinNoticeWorkflowService;
use App\Support\CustomerContext;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditNotice extends EditRecord
{
    protected static string $resource = NoticeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Notice) {
            return parent::handleRecordUpdate($record, $data);
        }

        $newStatus = (string) ($data['internal_status'] ?? $record->internal_status);
        $comment = $this->normalizeComment($data['internal_comment'] ?? null);
        $assignedToUserId = isset($data['assigned_to_user_id']) && $data['assigned_to_user_id'] !== ''
            ? (int) $data['assigned_to_user_id']
            : null;
        $assignedToUserId = $this->validatedAssignedUserId($assignedToUserId);

        if ($newStatus !== $record->internal_status) {
            app(DoffinNoticeWorkflowService::class)->updateStatus(
                $record,
                $newStatus,
                $comment,
                $this->currentActor(),
            );
        }

        $record->fill([
            'internal_comment' => $comment,
            'assigned_to_user_id' => $assignedToUserId,
        ]);
        $record->save();

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->url($this->getResource()::getUrl('view', ['record' => $this->record])),
        ];
    }

    private function currentActor(): ?User
    {
        return app(CustomerContext::class)->currentUser();
    }

    private function normalizeComment(mixed $comment): ?string
    {
        if (! is_string($comment)) {
            return null;
        }

        $trimmed = trim($comment);

        return $trimmed === '' ? null : $trimmed;
    }

    private function validatedAssignedUserId(?int $assignedToUserId): ?int
    {
        if ($assignedToUserId === null) {
            return null;
        }

        $assignedUser = User::query()->find($assignedToUserId);

        if (! $assignedUser instanceof User) {
            throw new RuntimeException('Assigned user was not found.');
        }

        $actor = $this->currentActor();
        $context = app(CustomerContext::class);

        if ($actor instanceof User && ! $context->isInternalAdmin($actor) && $assignedUser->customer_id !== $actor->customer_id) {
            throw new RuntimeException('Assigned user must belong to the same customer.');
        }

        return $assignedUser->id;
    }
}
