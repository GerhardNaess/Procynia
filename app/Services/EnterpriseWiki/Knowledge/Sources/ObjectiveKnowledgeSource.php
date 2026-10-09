<?php

namespace App\Services\EnterpriseWiki\Knowledge\Sources;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSource;
use App\Services\Objectives\ObjectiveAccessService;
use Illuminate\Database\Eloquent\Model;

/**
 * Mål og KPI → Wiki. What carries over from an objective is what it aimed at, how it was measured,
 * where the measurements ended and what was concluded when it closed. Gated on objective.edit in
 * the objective's fagområde.
 */
class ObjectiveKnowledgeSource implements WikiKnowledgeSource
{
    public const MODEL = Objective::class;

    public function __construct(private readonly ObjectiveAccessService $access) {}

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
            $source->target_date !== null ? __("{$t}.target_date").': '.$source->target_date->toDateString() : null,
            __("{$t}.status").': '.__("{$t}.statuses.{$source->status}"),
        ]));

        $kpis = $source->kpis()->orderBy('id')->get()
            ->map(function (Kpi $kpi) use ($t): string {
                $unit = trim((string) ($kpi->unit_label ?? ''));
                $target = match (true) {
                    $kpi->target_min !== null && $kpi->target_max !== null => $kpi->target_min.'–'.$kpi->target_max,
                    $kpi->target_min !== null => '≥ '.$kpi->target_min,
                    $kpi->target_max !== null => '≤ '.$kpi->target_max,
                    default => null,
                };
                $latest = $kpi->measurements()->whereNull('withdrawn_at')->reorder()->latest('period_end')->latest('id')->first();

                return $kpi->title
                    .($target !== null ? ' — '.__("{$t}.target").': '.$target.($unit !== '' ? ' '.$unit : '') : '')
                    .($latest instanceof KpiMeasurement ? ' — '.__("{$t}.latest").': '.$latest->value.($unit !== '' ? ' '.$unit : '').' ('.$latest->period_end?->toDateString().')' : '');
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
