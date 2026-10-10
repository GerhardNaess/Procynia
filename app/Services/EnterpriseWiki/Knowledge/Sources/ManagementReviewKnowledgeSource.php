<?php

namespace App\Services\EnterpriseWiki\Knowledge\Sources;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\ManagementReview;
use App\Models\ManagementReviewAmendment;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewSection;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSource;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\ManagementReview\ManagementReviewBasisService;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as Catalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledelsens gjennomgåelse → Wiki. What carries over is the management's own word on how the
 * organisation is run: its judgement and comment per section, its texts, its overall conclusion, what
 * it decided and which improvements it planned. Never the basis — the figures and lists another module
 * owns stay in that module, so the Wiki does not get a second, ageing copy of the registers.
 *
 * Only a finalized review hands over: it is frozen, so the same choice always renders the same source
 * (and the document store reuses it). Live follow-up state — a tiltak's status, owner or due date — is
 * left out for the same reason, and because names are never offered. The review's period and
 * finalization date always go with the source, so a judgement is never read as timeless.
 *
 * A module section's judgement and comment are offered only to someone who may see that section's
 * basis today — the same rule ManagementReviewPresenter applies on the review page. Gated on
 * management_review.edit, the permission that works on reviews.
 */
class ManagementReviewKnowledgeSource implements WikiKnowledgeSource
{
    public const MODEL = ManagementReview::class;

    public function __construct(
        private readonly ManagementReviewAccessService $access,
        private readonly ManagementReviewBasisService $basis,
        private readonly Catalog $catalog,
    ) {}

    public function sourceType(): string
    {
        return 'management_review';
    }

    public function sourceModule(): string
    {
        return 'management_review';
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
        return $source instanceof ManagementReview
            && $source->isFinalized()
            && $this->access->canEdit($user);
    }

    public function draft(User $user, Model $source): WikiKnowledgeDraft
    {
        /** @var ManagementReview $source */
        $t = 'procynia.knowledge_handoff.sources.management_review';

        if (! $source->isFinalized()) {
            return new WikiKnowledgeDraft('', []);
        }

        $asOf = $source->finalized_at?->toDateString() ?? '';
        $titles = fn (?string $key): ?string => $key !== null && isset(Catalog::DEFINITIONS[$key])
            ? __("procynia.management_review.sections.{$key}.title")
            : null;

        $sections = [
            new WikiKnowledgeDraftSection('conclusion', __("{$t}.conclusion_heading", ['date' => $asOf]), filled($source->conclusion) ? [(string) $source->conclusion] : [], asList: false),
            ...$this->judgementSections($user, $source, $titles),
        ];

        $decisions = $source->decisions()->get();
        $decisionLines = fn (string $kind): array => $decisions
            ->filter(fn (ManagementReviewDecision $decision): bool => $decision->kind === $kind)
            ->map(fn (ManagementReviewDecision $decision): string => $decision->text
                .(($theme = $titles($decision->section_key)) !== null ? ' ('.__("{$t}.theme").': '.$theme.')' : ''))
            ->values()
            ->all();

        $sections[] = new WikiKnowledgeDraftSection('decisions', __("{$t}.decisions_heading"), $decisionLines(ManagementReviewDecision::KIND_DECISION));
        $sections[] = new WikiKnowledgeDraftSection('planned_improvements', __("{$t}.planned_improvements_heading"), $decisionLines(ManagementReviewDecision::KIND_ACTION));
        $sections[] = new WikiKnowledgeDraftSection(
            'amendments',
            __("{$t}.amendments_heading"),
            $source->amendments()->get()
                ->map(fn (ManagementReviewAmendment $amendment): string => $amendment->created_at?->toDateString().': '.$amendment->text)
                ->all(),
        );

        return new WikiKnowledgeDraft(
            $source->title,
            $sections,
            context: new WikiKnowledgeDraftSection('context', __("{$t}.context_heading"), $this->contextLines($source, $asOf)),
            notice: __("{$t}.notice"),
        );
    }

    public function storeUrl(Model $source): string
    {
        return route('app.management-review.knowledge-handoff.store', ['sourceId' => $source->getKey()], false);
    }

    /**
     * One section per review section with something the management said about it, in review order.
     *
     * @param  \Closure(?string): ?string  $titles
     * @return list<WikiKnowledgeDraftSection>
     */
    private function judgementSections(User $user, ManagementReview $review, \Closure $titles): array
    {
        $t = 'procynia.knowledge_handoff.sources.management_review';
        $views = $this->basis->snapshot($user, $review);
        $stored = $review->sections()->get()->keyBy('section_key');
        $sections = [];

        foreach ($views as $key => $view) {
            // The review page's own rule: a module section's judgement follows its basis.
            if ($this->catalog->judgementFollowsBasis($key) && $view['state'] !== Catalog::STATE_AVAILABLE) {
                continue;
            }

            /** @var ManagementReviewSection|null $section */
            $section = $stored->get($key);

            $lines = array_values(array_filter([
                filled($section?->notes) ? __("{$t}.notes").': '.$section->notes : null,
                filled($section?->judgement) ? __("{$t}.judgement").': '.__("procynia.management_review.judgements.{$section->judgement}") : null,
                filled($section?->comment) ? __("{$t}.comment").': '.$section->comment : null,
            ]));

            $sections[] = new WikiKnowledgeDraftSection("assessment_{$key}", __("{$t}.assessment_heading", ['section' => $titles($key)]), $lines);
        }

        return $sections;
    }

    /** @return list<string> */
    private function contextLines(ManagementReview $review, string $asOf): array
    {
        $t = 'procynia.knowledge_handoff.sources.management_review';
        $areas = $review->all_business_areas
            ? __("{$t}.scope_all")
            : $review->businessAreas()->orderBy('business_areas.name')->pluck('business_areas.name')->implode(', ');

        return array_values(array_filter([
            __("{$t}.period", [
                'start' => $review->period_start?->toDateString(),
                'end' => $review->period_end?->toDateString(),
                'date' => $asOf,
            ]),
            $review->meeting_date !== null ? __("{$t}.meeting_date").': '.$review->meeting_date->toDateString() : null,
            $areas !== '' ? __("{$t}.scope").': '.$areas : null,
            filled($review->purpose) ? __("{$t}.purpose").': '.$review->purpose : null,
            __("{$t}.as_of", ['date' => $asOf]),
            __("{$t}.kinds"),
        ]));
    }
}
