<?php

namespace App\Services\Improvements;

use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * «Trenger oppmerksomhet» for Avvik og forbedringer — which of the user's cases and tiltak need
 * someone to act now.
 *
 * Six fixed rules, each read off rows that already exist and explainable in one sentence. Only cases
 * that are Åpen or Under arbeid take part, and only their tiltak:
 *
 *  - Sak med passert frist:       the case's frist is before today — from the day after it.
 *  - Sak mangler ansvarlig:       the case has no owner (the owner's user was deleted).
 *  - Tiltak med passert frist:    a planned or under arbeid tiltak whose frist is before today.
 *  - Tiltak mangler ansvarlig:    a planned or under arbeid tiltak without owner. A completed or
 *                                 cancelled tiltak needs nobody any more.
 *  - Venter på effektverifisering: a completed tiltak whose current completion nobody has judged.
 *  - Tiltak ikke effektivt:       a completed tiltak whose current judgement is Ikke effektivt.
 *
 * «Current» completion and judgement are ImprovementActionVerificationResolver's: a judgement of an
 * earlier completion never counts, so a reopened and again completed tiltak awaits a new one.
 *
 * Nothing is stored: the picture is recomputed on every read, so acting on the case or tiltak is the
 * only way to clear a finding. A closed or cancelled case gives no findings, nor do its tiltak —
 * their history stays, but they are no longer something to steer.
 *
 * ACCESS COMES FIRST. The cases are taken from ImprovementCaseAccessService::visibleCases() before
 * anything is counted, and tiltak, completions and judgements are fetched by the ids of those cases
 * only. A case outside the user's fagområder never enters the set, so it cannot move a count.
 *
 * TWO SUBJECTS, NO SCORE. Cases and tiltak are counted apart, each once however many rules it hits.
 * A tiltak finding never makes its case a finding.
 *
 * A fixed number of queries whatever the number of tiltak: cases (with their areas), tiltak,
 * completions and judgements — each once.
 */
class ImprovementAttentionService
{
    public const CASE_OVERDUE = 'case_overdue';

    public const ACTION_OVERDUE = 'action_overdue';

    public const CASE_OWNER_MISSING = 'case_owner_missing';

    public const ACTION_OWNER_MISSING = 'action_owner_missing';

    public const AWAITING_VERIFICATION = 'awaiting_verification';

    public const NOT_EFFECTIVE = 'not_effective';

    public const SUBJECT_CASE = 'case';

    public const SUBJECT_ACTION = 'action';

    /** Display order, and what each category is about. */
    public const CATEGORIES = [
        self::CASE_OVERDUE => self::SUBJECT_CASE,
        self::ACTION_OVERDUE => self::SUBJECT_ACTION,
        self::CASE_OWNER_MISSING => self::SUBJECT_CASE,
        self::ACTION_OWNER_MISSING => self::SUBJECT_ACTION,
        self::AWAITING_VERIFICATION => self::SUBJECT_ACTION,
        self::NOT_EFFECTIVE => self::SUBJECT_ACTION,
    ];

    public function __construct(
        private readonly ImprovementCaseAccessService $access,
        private readonly ImprovementActionVerificationResolver $verifications,
    ) {}

    /**
     * The register's panel. Only categories with at least one hit are returned, in a fixed order.
     *
     * @return array{
     *     case_total: int,
     *     action_total: int,
     *     categories: list<array{key: string, subject: string, count: int, items: list<array<string, mixed>>}>
     * }
     */
    public function overview(User $user, ?CarbonInterface $today = null): array
    {
        $cases = $this->access->visibleCases($user)
            ->whereIn('improvement_cases.status', ImprovementCase::ACTIVE_STATUSES)
            ->with('businessArea:id,name')
            ->orderBy('improvement_cases.title')
            ->orderBy('improvement_cases.id')
            ->get(['improvement_cases.*']);

        $findings = $this->findings($cases, $this->day($today));
        $byCategory = array_fill_keys(array_keys(self::CATEGORIES), []);

        foreach ($findings['cases'] as $finding) {
            foreach ($finding['reasons'] as $key => $detail) {
                $byCategory[$key][] = $this->caseRow($finding['case']) + ['detail' => $detail];
            }
        }

        foreach ($findings['actions'] as $finding) {
            foreach ($finding['reasons'] as $key => $detail) {
                $byCategory[$key][] = $this->actionRow($finding['action']) + ['detail' => $detail];
            }
        }

        return [
            'case_total' => count($findings['cases']),
            'action_total' => count($findings['actions']),
            'categories' => collect($byCategory)
                ->filter(fn (array $items): bool => $items !== [])
                ->map(fn (array $items, string $key): array => [
                    'key' => $key,
                    'subject' => self::CATEGORIES[$key],
                    'count' => count($items),
                    'items' => $items,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * One case's own findings and how many of its tiltak need attention — for a short note on the
     * case page, not the panel again; the tiltak cards say which and why. The case must already have
     * been reached through ImprovementCaseAccessService. Null when there is nothing to say, and
     * always for an ended case.
     *
     * @return array{action_count: int, reasons: list<array{key: string, detail: string}>}|null
     */
    public function forCase(ImprovementCase $case, ?CarbonInterface $today = null): ?array
    {
        if (! $case->isActive()) {
            return null;
        }

        $findings = $this->findings(new EloquentCollection([$case]), $this->day($today));
        $reasons = [];

        foreach ($findings['cases'][0]['reasons'] ?? [] as $key => $detail) {
            $reasons[] = ['key' => $key, 'detail' => $detail];
        }

        if ($reasons === [] && $findings['actions'] === []) {
            return null;
        }

        return ['action_count' => count($findings['actions']), 'reasons' => $reasons];
    }

    /**
     * The same findings over cases the caller already holds — for «Mine oppgaver»
     * (ImprovementTaskSource). The cases must be active, of one customer, and already reached
     * through ImprovementCaseAccessService.
     *
     * @param  EloquentCollection<int, ImprovementCase>  $cases
     * @return array{
     *     cases: list<array{case: ImprovementCase, reasons: array<string, string>}>,
     *     actions: list<array{action: ImprovementAction, reasons: array<string, string>}>
     * }
     */
    public function findingsForCases(EloquentCollection $cases, ?CarbonInterface $today = null): array
    {
        return $this->findings($cases->filter(fn (ImprovementCase $case): bool => $case->isActive())->values(), $this->day($today));
    }

    /**
     * Every finding over the given active cases, each case and each tiltak once with all its reasons.
     *
     * @param  EloquentCollection<int, ImprovementCase>  $cases
     * @return array{
     *     cases: list<array{case: ImprovementCase, reasons: array<string, string>}>,
     *     actions: list<array{action: ImprovementAction, reasons: array<string, string>}>
     * }
     */
    private function findings(EloquentCollection $cases, CarbonImmutable $today): array
    {
        $flaggedCases = [];
        $flaggedActions = [];

        if ($cases->isEmpty()) {
            return ['cases' => [], 'actions' => []];
        }

        foreach ($cases as $case) {
            $reasons = [];

            if ($case->due_date !== null && $this->before($case->due_date, $today)) {
                $reasons[self::CASE_OVERDUE] = (string) __('procynia.improvements.attention.reasons.case_overdue', ['date' => $this->date($case->due_date)]);
            }

            if ($case->owner_user_id === null) {
                $reasons[self::CASE_OWNER_MISSING] = (string) __('procynia.improvements.attention.reasons.case_owner_missing');
            }

            if ($reasons !== []) {
                $flaggedCases[] = ['case' => $case, 'reasons' => $reasons];
            }
        }

        $actions = $this->actions($cases);
        $current = $this->verifications->forActions($actions);

        foreach ($actions as $action) {
            $reasons = $this->actionReasons($action, $current[(int) $action->id] ?? null, $today);

            if ($reasons !== []) {
                $flaggedActions[] = ['action' => $action, 'reasons' => $reasons];
            }
        }

        return ['cases' => $flaggedCases, 'actions' => $flaggedActions];
    }

    /**
     * @param  array{completion: mixed, verification: mixed}|null  $current
     * @return array<string, string>
     */
    private function actionReasons(ImprovementAction $action, ?array $current, CarbonImmutable $today): array
    {
        $reasons = [];

        if ($action->isActive()) {
            if ($action->due_date !== null && $this->before($action->due_date, $today)) {
                $reasons[self::ACTION_OVERDUE] = (string) __('procynia.improvements.attention.reasons.action_overdue', ['date' => $this->date($action->due_date)]);
            }

            if ($action->owner_user_id === null) {
                $reasons[self::ACTION_OWNER_MISSING] = (string) __('procynia.improvements.attention.reasons.action_owner_missing');
            }

            return $reasons;
        }

        if ($action->status !== ImprovementAction::STATUS_COMPLETED) {
            return $reasons;
        }

        $verification = $current['verification'] ?? null;

        if ($verification === null) {
            // A completed tiltak without a completion row (data from before the history) is unjudged too.
            $completedAt = $current['completion']->changed_at ?? $action->completed_at;
            $reasons[self::AWAITING_VERIFICATION] = (string) __('procynia.improvements.attention.reasons.awaiting_verification', [
                'date' => $completedAt !== null ? $this->date($completedAt) : '',
            ]);
        } elseif (! $verification->isEffective()) {
            $reasons[self::NOT_EFFECTIVE] = (string) __('procynia.improvements.attention.reasons.not_effective');
        }

        return $reasons;
    }

    /**
     * The tiltak of the given cases that can still need something: planned, under arbeid or
     * completed. Cancelled tiltak never do. One query, with their case set from what is at hand.
     *
     * @param  EloquentCollection<int, ImprovementCase>  $cases
     * @return EloquentCollection<int, ImprovementAction>
     */
    private function actions(EloquentCollection $cases): EloquentCollection
    {
        $byId = $cases->keyBy('id');

        return ImprovementAction::query()
            ->where('customer_id', (int) $cases->first()->customer_id)
            ->whereIn('improvement_case_id', $cases->modelKeys())
            ->whereIn('status', [...ImprovementAction::ACTIVE_STATUSES, ImprovementAction::STATUS_COMPLETED])
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->each(fn (ImprovementAction $action) => $action->setRelation('improvementCase', $byId->get($action->improvement_case_id)));
    }

    /** Before the given day: the day itself is not passed. */
    private function before(CarbonInterface $date, CarbonImmutable $today): bool
    {
        return CarbonImmutable::parse($date->toDateString())->lessThan($today);
    }

    private function day(?CarbonInterface $today): CarbonImmutable
    {
        return CarbonImmutable::parse(($today ?? now())->toDateString());
    }

    /** A day as people write it: «5. oktober 2026», «5 October 2026». */
    private function date(CarbonInterface $date): string
    {
        $english = str_starts_with(strtolower(app()->getLocale()), 'en');

        return CarbonImmutable::instance($date)->locale($english ? 'en' : 'nb')->translatedFormat($english ? 'j F Y' : 'j. F Y');
    }

    /** @return array{id: int, title: string, case_title: ?string, area_name: ?string, url: string} */
    private function caseRow(ImprovementCase $case): array
    {
        return [
            'id' => (int) $case->id,
            'title' => $case->title,
            'case_title' => null,
            'area_name' => $case->businessArea?->name,
            'url' => route('app.improvements.show', ['caseId' => $case->id]),
        ];
    }

    /** @return array{id: int, title: string, case_title: ?string, area_name: ?string, url: string} */
    private function actionRow(ImprovementAction $action): array
    {
        $case = $action->improvementCase;

        return [
            'id' => (int) $action->id,
            'title' => $action->title,
            'case_title' => $case->title,
            'area_name' => $case->businessArea?->name,
            // Straight to the tiltak's card on the case page.
            'url' => route('app.improvements.show', ['caseId' => $case->id]).'#improvement-action-'.$action->id,
        ];
    }
}
