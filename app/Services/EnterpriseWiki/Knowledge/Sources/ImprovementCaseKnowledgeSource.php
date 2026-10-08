<?php

namespace App\Services\EnterpriseWiki\Knowledge\Sources;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSource;
use App\Services\Improvements\ImprovementCaseAccessService;
use Illuminate\Database\Eloquent\Model;

/**
 * Avvik og forbedringer → Wiki. The lesson of a case is what happened, why, what was done about it
 * and whether that worked — the effect verification is what turns an action into knowledge.
 * Gated on improvement.edit in the case's fagområde, exactly as editing the case is.
 */
class ImprovementCaseKnowledgeSource implements WikiKnowledgeSource
{
    public const MODEL = ImprovementCase::class;

    public function __construct(private readonly ImprovementCaseAccessService $access) {}

    public function sourceType(): string
    {
        return 'improvement_case';
    }

    public function sourceModule(): string
    {
        return 'improvements';
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
        return $source instanceof ImprovementCase && $this->access->canEdit($user, $source);
    }

    public function draft(User $user, Model $source): WikiKnowledgeDraft
    {
        /** @var ImprovementCase $source */
        $t = 'procynia.knowledge_handoff.sources.improvement_case';

        $about = array_values(array_filter([
            __("{$t}.type").': '.__("{$t}.types.{$source->type}"),
            filled($source->description) ? __("{$t}.description").': '.$source->description : null,
        ]));

        $actions = $source->actions()->with(['verifications' => fn ($query) => $query->reorder()->latest('verified_at')->latest('id')])
            ->orderBy('id')
            ->get()
            ->map(function (ImprovementAction $action) use ($t): string {
                $line = $action->title.' ('.__("{$t}.action_statuses.{$action->status}").')';
                $verification = $action->verifications->first();

                if ($verification instanceof ImprovementActionVerification) {
                    $line .= ' — '.__("{$t}.verification.{$verification->result}")
                        .(filled($verification->note) ? ': '.$verification->note : '');
                }

                return $line;
            })
            ->all();

        return new WikiKnowledgeDraft($source->title, [
            new WikiKnowledgeDraftSection('about', __("{$t}.about_heading"), $about),
            new WikiKnowledgeDraftSection('cause', __("{$t}.cause_heading"), filled($source->cause_analysis) ? [(string) $source->cause_analysis] : [], asList: false),
            new WikiKnowledgeDraftSection('actions', __("{$t}.actions_heading"), $actions),
            new WikiKnowledgeDraftSection('closing', __("{$t}.closing_heading"), filled($source->closing_note) ? [(string) $source->closing_note] : [], asList: false),
        ]);
    }

    public function storeUrl(Model $source): string
    {
        return route('app.improvements.knowledge-handoff.store', ['sourceId' => $source->getKey()], false);
    }
}
