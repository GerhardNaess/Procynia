<?php

namespace App\Services\Improvements;

use App\Models\ImprovementCase;
use App\Models\ImprovementCaseActivity;
use App\Models\ImprovementCaseProcess;
use App\Models\User;
use App\Services\Quality\QualityProcessContextReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avvik / forbedring → gjelder → Kvalitet-prosess eller -aktivitet, from the case's side only.
 *
 * The same semantics as KPI → måler and Risiko → Kontekst: a case may concern whole processes and/or
 * concrete activities in them. Only ids are stored; titles, step labels and roles are read live
 * through QualityProcessContextReader.
 *
 * Access: the caller has reached the case through ImprovementCaseAccessService. Changing links is
 * changing the case (improvement.edit in its area, case open or in progress); anything shown about
 * a process takes Kvalitet read access, which improvement.* never implies. Without it the page says
 * nothing about context at all — not even that there is some.
 *
 * Kvalitet never calls into this service. Links to a step that leaves the flow are removed by
 * QualityActivityLinkCleanup; a removed process takes its links along in the database.
 */
class ImprovementCaseQualityContextService
{
    public function __construct(
        private readonly QualityProcessContextReader $quality,
    ) {}

    public function canReadQuality(User $user): bool
    {
        return $this->quality->canRead($user);
    }

    /**
     * The processes and activities the case concerns, grouped by process.
     *
     * @return list<array{id: int, title: string, code: ?string, url: string, whole_process: bool, activities: list<array{id: int, key: string, label: string, role: ?string, url: string}>}>
     */
    public function linkedContext(ImprovementCase $case): array
    {
        [$processIds, $activityLinks] = $this->links($case);

        return $this->quality->groupedContext((int) $case->customer_id, $processIds, $activityLinks);
    }

    /**
     * Every process of the customer with its activities, marked with what the case already concerns.
     *
     * @return list<array{id: int, title: string, code: ?string, linked: bool, activities: list<array{key: string, label: string, role: ?string, linked: bool}>}>
     */
    public function contextOptions(ImprovementCase $case): array
    {
        [$processIds, $activityLinks] = $this->links($case);

        return $this->quality->options(
            (int) $case->customer_id,
            $processIds,
            $activityLinks->map(fn (array $link): string => $link['process_id'].'|'.$link['key'])->all(),
        );
    }

    /**
     * Endre kobling for one process: what the case concerns in it afterwards — the process as a
     * whole, and/or the given activities. Choosing nothing removes the process from the case. A
     * process outside the case's customer, or a key that is not a step in its flow, is refused with
     * the same answer as a missing one.
     *
     * @param  list<string>  $activityKeys
     */
    public function syncProcess(User $user, ImprovementCase $case, int $processId, bool $wholeProcess, array $activityKeys): void
    {
        $customerId = (int) $case->customer_id;
        $process = $this->quality->findProcess($customerId, $processId);

        if ($process === null) {
            throw ValidationException::withMessages([
                'quality_process_id' => __('procynia.improvements.context.validation.process_not_allowed'),
            ]);
        }

        $activityKeys = array_values(array_unique($activityKeys));
        $steps = $this->quality->steps($this->quality->blueprints($customerId, [(int) $process->id])->get($process->id));

        if (array_diff($activityKeys, array_keys($steps)) !== []) {
            throw ValidationException::withMessages([
                'activity_keys' => __('procynia.improvements.context.validation.activity_not_allowed'),
            ]);
        }

        DB::transaction(function () use ($user, $case, $customerId, $process, $wholeProcess, $activityKeys): void {
            if ($wholeProcess) {
                ImprovementCaseProcess::query()->firstOrCreate(
                    ['improvement_case_id' => $case->id, 'quality_process_id' => $process->id],
                    ['customer_id' => $customerId, 'created_by' => $user->id],
                );
            } else {
                ImprovementCaseProcess::query()
                    ->where('improvement_case_id', $case->id)
                    ->where('quality_process_id', $process->id)
                    ->delete();
            }

            ImprovementCaseActivity::query()
                ->where('improvement_case_id', $case->id)
                ->where('quality_process_id', $process->id)
                ->whereNotIn('activity_key', $activityKeys)
                ->delete();

            foreach ($activityKeys as $key) {
                ImprovementCaseActivity::query()->firstOrCreate(
                    ['improvement_case_id' => $case->id, 'quality_process_id' => $process->id, 'activity_key' => $key],
                    ['customer_id' => $customerId, 'created_by' => $user->id],
                );
            }
        });
    }

    /** @return array{0: list<int>, 1: Collection<int, array{id: int, process_id: int, key: string}>} */
    private function links(ImprovementCase $case): array
    {
        $processIds = ImprovementCaseProcess::query()
            ->where('improvement_case_id', $case->id)
            ->where('customer_id', $case->customer_id)
            ->pluck('quality_process_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $activityLinks = ImprovementCaseActivity::query()
            ->where('improvement_case_id', $case->id)
            ->where('customer_id', $case->customer_id)
            ->orderBy('id')
            ->get(['id', 'quality_process_id', 'activity_key'])
            ->map(fn (ImprovementCaseActivity $link): array => [
                'id' => (int) $link->id,
                'process_id' => (int) $link->quality_process_id,
                'key' => (string) $link->activity_key,
            ]);

        return [$processIds, $activityLinks];
    }
}
