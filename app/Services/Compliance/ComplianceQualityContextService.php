<?php

namespace App\Services\Compliance;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementControl;
use App\Models\ComplianceRequirementProcess;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\Quality\QualityProcessContextReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Krav → oppfylles gjennom → Kvalitet-prosess / -kontroll, from the requirement's side only.
 *
 * Compliance owns the requirement and its etterlevelsesvurderinger; Kvalitet owns processes,
 * controls and the evidence recorded on them. This service stores which processes and controls a
 * requirement is met through, and nothing more: titles, criterion, method, frequency, status and
 * evidence are read from Kvalitet (QualityProcessContextReader) whenever the page is drawn.
 *
 * TWO ACCESS QUESTIONS, NEITHER IMPLIES THE OTHER.
 *
 *  - The requirement: the caller has already reached it through ComplianceAccessService. Changing
 *    its links also takes compliance.edit, and only an active requirement's links change.
 *  - Kvalitet: whatever is shown about a process, a control or its evidence, the person must be
 *    able to read in Kvalitet today (QualityProcessContextReader::canRead). Without it the page
 *    says nothing about the links at all — not their names, not how many there are — and nothing
 *    can be linked or unlinked. compliance.* never implies it, for System Owner neither.
 *
 * Evidence is shown, never judged. Whether a control has evidence says nothing on its own about
 * whether the requirement is met; the etterlevelsesvurdering stays an explicit human judgement and
 * nothing here reads or writes it.
 *
 * Kvalitet never calls into this service and never reads its tables. A deleted process or control
 * takes its links along in the database; a retired control keeps its link and is shown as utgått.
 */
class ComplianceQualityContextService
{
    public function __construct(
        private readonly QualityProcessContextReader $quality,
        private readonly ComplianceAccessService $access,
    ) {}

    public function canReadQuality(User $user): bool
    {
        return $this->quality->canRead($user);
    }

    /**
     * Whether the user may change which processes and controls this requirement is met through:
     * compliance.edit, Kvalitet read access, and an active requirement.
     */
    public function canManageLinks(User $user, ComplianceRequirement $requirement): bool
    {
        return $requirement->isActive() && $this->hasManagePermissions($user);
    }

    /**
     * The linked processes and controls, read live from Kvalitet. Each control carries its own
     * fields, where it sits in Kvalitet's flows, and its evidence. Call only for a user who passes
     * canReadQuality().
     *
     * @return array{processes: list<array<string, mixed>>, controls: list<array<string, mixed>>}
     */
    public function linkedContext(ComplianceRequirement $requirement): array
    {
        $customerId = (int) $requirement->customer_id;

        $processes = $this->quality->processesQuery($customerId)
            ->whereIn('quality_items.id', ComplianceRequirementProcess::query()
                ->where('requirement_id', $requirement->id)
                ->where('customer_id', $customerId)
                ->select('quality_process_id'))
            ->get();

        $controls = $this->quality->controlsQuery($customerId)
            ->whereIn('quality_items.id', ComplianceRequirementControl::query()
                ->where('requirement_id', $requirement->id)
                ->where('customer_id', $customerId)
                ->select('control_item_id'))
            ->with('controlDetail:id,quality_item_id,criterion,responsibility,frequency,method')
            ->get();
        $controlIds = $controls->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $placements = $this->quality->controlPlacements($customerId, $controlIds);
        $evidence = $this->quality->controlEvidence($customerId, $controlIds);

        return [
            'processes' => $processes->map(fn (QualityItem $process): array => [
                'id' => (int) $process->id,
                'title' => (string) $process->title,
                'code' => $process->code,
                'status' => $process->status,
                'url' => $this->quality->processUrl((int) $process->id),
            ])->all(),
            'controls' => $controls->map(fn (QualityItem $control): array => [
                'id' => (int) $control->id,
                'title' => (string) $control->title,
                'code' => $control->code,
                'status' => $control->status,
                'criterion' => $control->controlDetail?->criterion,
                'method' => $control->controlDetail?->method,
                'frequency' => $control->controlDetail?->frequency,
                'responsibility' => $control->controlDetail?->responsibility,
                'placements' => $placements[(int) $control->id] ?? [],
                'evidence' => $evidence[(int) $control->id] ?? [],
                'url' => route('app.quality.items.show', ['item' => $control->id]),
            ])->all(),
        ];
    }

    /**
     * What can still be linked: the customer's processes and controls that are not retired and not
     * already linked. Call only for a user who passes canManageLinks().
     *
     * @return array{processes: list<array{id: int, title: string, code: ?string, status: string}>, controls: list<array{id: int, title: string, code: ?string, status: string, placements: list<string>}>}
     */
    public function options(ComplianceRequirement $requirement): array
    {
        $customerId = (int) $requirement->customer_id;

        $processes = $this->linkableProcesses($customerId)
            ->whereNotIn('quality_items.id', ComplianceRequirementProcess::query()->where('requirement_id', $requirement->id)->select('quality_process_id'))
            ->get();
        $controls = $this->linkableControls($customerId)
            ->whereNotIn('quality_items.id', ComplianceRequirementControl::query()->where('requirement_id', $requirement->id)->select('control_item_id'))
            ->get();
        $placements = $this->quality->controlPlacements($customerId, $controls->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return [
            'processes' => $processes->map(fn (QualityItem $process): array => [
                'id' => (int) $process->id,
                'title' => (string) $process->title,
                'code' => $process->code,
                'status' => $process->status,
            ])->all(),
            'controls' => $controls->map(fn (QualityItem $control): array => [
                'id' => (int) $control->id,
                'title' => (string) $control->title,
                'code' => $control->code,
                'status' => $control->status,
                'placements' => $placements[(int) $control->id] ?? [],
            ])->all(),
        ];
    }

    /**
     * Link an existing process. Anything that is not a non-retired process of the requirement's own
     * customer — another customer's id, a control, a missing id — is refused with the same answer,
     * so the form cannot be used to probe ids.
     */
    public function linkProcess(User $user, ComplianceRequirement $requirement, int $processId): void
    {
        $this->authorizeManage($user, $requirement);

        $process = $this->linkableProcesses((int) $requirement->customer_id)->whereKey($processId)->first();

        if ($process === null) {
            throw ValidationException::withMessages(['quality_process_id' => __('procynia.compliance.quality.validation.process_not_allowed')]);
        }

        $this->createOnce(
            fn (): bool => ComplianceRequirementProcess::query()->where('requirement_id', $requirement->id)->where('quality_process_id', $process->id)->exists(),
            fn () => ComplianceRequirementProcess::query()->create([
                'customer_id' => $requirement->customer_id,
                'requirement_id' => $requirement->id,
                'quality_process_id' => $process->id,
                'created_by' => $user->id,
            ]),
            'quality_process_id',
            __('procynia.compliance.quality.validation.process_already_linked'),
        );
    }

    /** Remove the link. The process is left exactly as it was. False when there was no such link. */
    public function unlinkProcess(User $user, ComplianceRequirement $requirement, int $processId): bool
    {
        $this->authorizeManage($user, $requirement);

        return ComplianceRequirementProcess::query()
            ->where('requirement_id', $requirement->id)
            ->where('customer_id', $requirement->customer_id)
            ->where('quality_process_id', $processId)
            ->delete() > 0;
    }

    /**
     * Link an existing control: a `control` QualityItem of the requirement's own customer that is
     * not retired. A plain quality item, a process, another customer's control, a retired one or a
     * missing id all get the same answer.
     */
    public function linkControl(User $user, ComplianceRequirement $requirement, int $controlId): void
    {
        $this->authorizeManage($user, $requirement);

        $control = $this->linkableControls((int) $requirement->customer_id)->whereKey($controlId)->first();

        if ($control === null) {
            throw ValidationException::withMessages(['control_item_id' => __('procynia.compliance.quality.validation.control_not_allowed')]);
        }

        $this->createOnce(
            fn (): bool => ComplianceRequirementControl::query()->where('requirement_id', $requirement->id)->where('control_item_id', $control->id)->exists(),
            fn () => ComplianceRequirementControl::query()->create([
                'customer_id' => $requirement->customer_id,
                'requirement_id' => $requirement->id,
                'control_item_id' => $control->id,
                'created_by' => $user->id,
            ]),
            'control_item_id',
            __('procynia.compliance.quality.validation.control_already_linked'),
        );
    }

    /** Remove the link. The control and its evidence are left exactly as they were. */
    public function unlinkControl(User $user, ComplianceRequirement $requirement, int $controlId): bool
    {
        $this->authorizeManage($user, $requirement);

        return ComplianceRequirementControl::query()
            ->where('requirement_id', $requirement->id)
            ->where('customer_id', $requirement->customer_id)
            ->where('control_item_id', $controlId)
            ->delete() > 0;
    }

    private function hasManagePermissions(User $user): bool
    {
        return $this->access->canEdit($user) && $this->canReadQuality($user);
    }

    /**
     * Permission first, the same 403 whatever id was sent; then the requirement's status. A retired
     * requirement keeps its links as they were — it is reopened before they change.
     */
    private function authorizeManage(User $user, ComplianceRequirement $requirement): void
    {
        abort_unless($this->hasManagePermissions($user), 403);

        if (! $requirement->isActive()) {
            throw ValidationException::withMessages(['requirement' => __('procynia.compliance.quality.validation.requirement_retired')]);
        }
    }

    /** @return Builder<QualityItem> */
    private function linkableProcesses(int $customerId): Builder
    {
        return $this->quality->processesQuery($customerId)->where('quality_items.status', '!=', QualityItem::STATUS_RETIRED);
    }

    /** @return Builder<QualityItem> */
    private function linkableControls(int $customerId): Builder
    {
        return $this->quality->controlsQuery($customerId)->where('quality_items.status', '!=', QualityItem::STATUS_RETIRED);
    }

    /**
     * A second link to the same item is refused, not silently ignored. Two requests at once both
     * pass the check; the unique index refuses the second, and it gets the same answer.
     *
     * @param  callable(): bool  $exists
     * @param  callable(): mixed  $create
     */
    private function createOnce(callable $exists, callable $create, string $field, string $message): void
    {
        if ($exists()) {
            throw ValidationException::withMessages([$field => $message]);
        }

        try {
            $create();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
