<?php

namespace App\Services\Api\Presenters;

use App\Models\ImprovementCase;
use App\Services\Improvements\ImprovementAttentionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * An avvik as the external API presents it: structured fields only (D8).
 *
 * In: identifiers, title, status, fagområde, ansvarlig, hendelsesdato, frist, whether the frist has
 * passed, when it ended, and its follow-up — tiltak counts and the reasons it needs attention, as
 * codes. Out: beskrivelse, årsaksanalyse, avslutningsnotat and every other free text, the reporter
 * and who closed it, tiltak titles, documents, and web routes.
 *
 * The follow-up is ImprovementAttentionService's, the same six rules the register's «Trenger
 * oppmerksomhet» applies, so the API never judges a case differently from the web. Only an active
 * case has findings.
 */
class DeviationPresenter
{
    public function __construct(private readonly ImprovementAttentionService $attention) {}

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
