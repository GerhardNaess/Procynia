<?php

namespace App\Services\EnterpriseWiki\Knowledge\Sources;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\Kpi;
use App\Models\Objective;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSource;
use App\Services\Objectives\KpiMeasurementResolver;
use App\Services\Objectives\ObjectiveAccessService;
use App\Support\Objectives\KpiPeriodFormatter;
use App\Support\Objectives\KpiPresenter;
use App\Support\Objectives\KpiTargetFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Mål og KPI → Wiki. What carries over from an objective is what it aimed at, how it was measured,
 * where the measurements ended and what was concluded when it closed. Gated on objective.edit in
 * the objective's fagområde.
 *
 * A KPI reads the way its page reads it — the same KpiTargetFormatter, KpiMeasurementResolver and
 * KpiPresenter — so the Wiki never gets raw column values («1000000.0000–1300000.0000») and never
 * calls an interval a minimum: the målverdi as its rule sentence, the latest current value with its
 * period, and today's status.
 */
class ObjectiveKnowledgeSource implements WikiKnowledgeSource
{
    public const MODEL = Objective::class;

    public function __construct(
        private readonly ObjectiveAccessService $access,
        private readonly KpiMeasurementResolver $resolver,
        private readonly KpiPresenter $presenter,
    ) {}

    public function sourceType(): string
    {
        return 'objective';
    }

    public function sourceModule(): string
    {
        return 'objectives';
    }

    public function modelClass(): string
    {
        return self::MODEL;
    }

    public function canOpenModule(User $user): bool
    {
        return $this->access->canOpenModule($user);
    }

    public function findForUser(User $user, int $sourceId): ?Model
    {
        return $this->canOpenModule($user) ? $this->access->findVisible($user, $sourceId) : null;
    }

    public function canHandOff(User $user, Model $source): bool
    {
        return $source instanceof Objective && $this->access->canEdit($user, $source);
    }

    public function draft(User $user, Model $source): WikiKnowledgeDraft
    {
        /** @var Objective $source */
        $t = 'procynia.knowledge_handoff.sources.objective';

        $about = array_values(array_filter([
            filled($source->description) ? __("{$t}.description").': '.$source->description : null,
            $source->target_date !== null ? __("{$t}.target_date").': '.(new KpiPeriodFormatter)->date($source->target_date) : null,
            __("{$t}.status").': '.__("{$t}.statuses.{$source->status}"),
        ]));

        $formatter = new KpiTargetFormatter;
        $periodFormatter = new KpiPeriodFormatter;
        $kpis = $source->kpis()->with('measurements')->orderBy('id')->get()
            ->map(function (Kpi $kpi) use ($t, $formatter, $periodFormatter): string {
                $latest = $this->resolver->latest($kpi->measurements);
                $result = $this->presenter->currentResult($kpi, $latest);

                return implode(' — ', array_filter([
                    $kpi->title.($kpi->isActive() ? '' : ' ('.__("{$t}.kpi_retired").')'),
                    $formatter->rule($kpi),
                    $latest !== null
                        ? __("{$t}.latest").': '.$formatter->amount($kpi, $latest->value)
                            .' ('.$periodFormatter->range($latest->period_start, $latest->period_end).')'
                        : null,
                    __("{$t}.result").': '.__("procynia.objectives.measurement.results.{$result}"),
                ]));
            })
            ->all();

        return new WikiKnowledgeDraft($source->title, [
            new WikiKnowledgeDraftSection('about', __("{$t}.about_heading"), $about),
            new WikiKnowledgeDraftSection('kpis', __("{$t}.kpis_heading"), $kpis),
            new WikiKnowledgeDraftSection('closing', __("{$t}.closing_heading"), filled($source->closing_note) ? [(string) $source->closing_note] : [], asList: false),
        ]);
    }

    public function storeUrl(Model $source): string
    {
        return route('app.objectives.knowledge-handoff.store', ['sourceId' => $source->getKey()], false);
    }
}
