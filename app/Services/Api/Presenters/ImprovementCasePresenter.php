<?php

namespace App\Services\Api\Presenters;

use App\Models\ImprovementAction;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Services\Improvements\ImprovementActionVerificationResolver;
use App\Services\Improvements\ImprovementAttentionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * An avvik or a forbedring as the external API presents it: structured fields only (D8). Both are an
 * ImprovementCase and read the same; each endpoint serves one type.
 *
 * In: identifiers, title, status, fagområde, ansvarlig, hendelsesdato, frist, whether the frist has
 * passed, when it ended, and its follow-up — tiltak counts and the reasons it needs attention, as
 * codes. The detail adds the tiltak: title, status, ansvarlig, frist, when completed and the
 * effektverifisering (result and date). Out: beskrivelse, årsaksanalyse, avslutningsnotat, tiltak
 * descriptions and completion notes, verification notes and every other free text, the reporter,
 * who closed, completed or verified, the status history, the Kvalitet links, the origin in other
 * modules, documents and web routes.
 *
 * The follow-up is ImprovementAttentionService's, the same six rules the register's «Trenger
 * oppmerksomhet» applies, so the API never judges a case differently from the web. Only an active
 * case has findings. Which verification is current is ImprovementActionVerificationResolver's, as on
 * the case page.
 */
class ImprovementCasePresenter
{
    public function __construct(
        private readonly ImprovementAttentionService $attention,
        private readonly ImprovementActionVerificationResolver $verifications,
    ) {}

    /**
     * @param  Collection<int, ImprovementCase>  $cases  already reached through ImprovementCaseAccessService::visibleCases(),
     *                                                   with businessArea, owner and the action counts loaded
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $cases, CarbonImmutable $today): array
    {
        $attention = $this->attentionByCase($cases, $today);

        return $cases
            ->map(fn (ImprovementCase $case): array => $this->row($case, $attention[(int) $case->id] ?? []))
            ->values()
            ->all();
    }

    /**
     * One case with its tiltak.
     *
     * @param  ImprovementCase  $case  already reached through ImprovementCaseAccessService::findVisible(), with
     *                                 businessArea, owner, the action counts, actions.owner and actions.verifications loaded
     * @return array<string, mixed>
     */
    public function detail(ImprovementCase $case, CarbonImmutable $today): array
    {
        $current = $this->verifications->forActions($case->actions);

        return $this->rows(new Collection([$case]), $today)[0] + [
            // In the order they were added, as on the case page.
            'actions' => $case->actions
                ->map(fn (ImprovementAction $action): array => $this->action($action, $case, $today, $current[(int) $action->id] ?? null))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $attention
     * @return array<string, mixed>
     */
    private function row(ImprovementCase $case, array $attention): array
    {
        return [
            'id' => (int) $case->id,
            'title' => $case->title,
            'status' => $case->status,
            'business_area' => [
                'id' => (int) $case->business_area_id,
                'name' => $case->businessArea?->name,
            ],
            'owner' => $case->owner_user_id !== null
                ? ['id' => (int) $case->owner_user_id, 'name' => $case->owner?->name]
                : null,
            'occurred_on' => $case->occurred_at?->toDateString(),
            'due_on' => $case->due_date?->toDateString(),
            'is_overdue' => in_array(ImprovementAttentionService::CASE_OVERDUE, $attention, true),
            'closed_at' => $case->closed_at?->utc()->toIso8601ZuluString(),
            'follow_up' => [
                'actions_total' => (int) $case->action_count,
                'actions_open' => (int) $case->open_action_count,
                'attention' => $attention,
            ],
            'created_at' => $case->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $case->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * A tiltak, with its effektverifisering. Frist passert only while the case is active, as on the
     * case page: an ended case is no longer being worked, whatever its tiltak say.
     *
     * @param  array{completion: mixed, verification: ?ImprovementActionVerification}|null  $current
     * @return array<string, mixed>
     */
    private function action(ImprovementAction $action, ImprovementCase $case, CarbonImmutable $today, ?array $current): array
    {
        $completed = $action->status === ImprovementAction::STATUS_COMPLETED;
        $currentVerification = $current['verification'] ?? null;

        return [
            'id' => (int) $action->id,
            'title' => $action->title,
            'status' => $action->status,
            'owner' => $action->owner_user_id !== null
                ? ['id' => (int) $action->owner_user_id, 'name' => $action->owner?->name]
                : null,
            'due_on' => $action->due_date?->toDateString(),
            'is_overdue' => $case->isActive() && $action->isOverdue($today),
            'completed_at' => $action->completed_at?->utc()->toIso8601ZuluString(),
            'verification' => [
                // Completed, and its current completion not yet judged.
                'awaiting' => $completed && $currentVerification === null,
                'current' => $currentVerification !== null ? $this->verification($currentVerification) : null,
                // Verifications of earlier completions that were later reopened: history.
                'earlier' => $action->verifications
                    ->reject(fn (ImprovementActionVerification $verification): bool => $currentVerification !== null && (int) $verification->id === (int) $currentVerification->id)
                    ->map(fn (ImprovementActionVerification $verification): array => $this->verification($verification))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** @return array{id: int, result: string, verified_at: ?string} */
    private function verification(ImprovementActionVerification $verification): array
    {
        return [
            'id' => (int) $verification->id,
            'result' => $verification->result,
            'verified_at' => $verification->verified_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * The attention codes per case: the case's own reasons and those of its tiltak, each once, in the
     * service's display order.
     *
     * @param  Collection<int, ImprovementCase>  $cases
     * @return array<int, list<string>>
     */
    private function attentionByCase(Collection $cases, CarbonImmutable $today): array
    {
        $findings = $this->attention->findingsForCases($cases, $today);
        $codes = [];

        foreach ($findings['cases'] as $finding) {
            foreach (array_keys($finding['reasons']) as $key) {
                $codes[(int) $finding['case']->id][$key] = true;
            }
        }

        foreach ($findings['actions'] as $finding) {
            foreach (array_keys($finding['reasons']) as $key) {
                $codes[(int) $finding['action']->improvement_case_id][$key] = true;
            }
        }

        $order = array_keys(ImprovementAttentionService::CATEGORIES);

        return array_map(
            fn (array $keys): array => array_values(array_filter($order, fn (string $key): bool => isset($keys[$key]))),
            $codes,
        );
    }
}
