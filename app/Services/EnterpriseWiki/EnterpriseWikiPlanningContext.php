<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;
use Closure;

/**
 * The one authoritative set of facts every planning path works from: this document, and this
 * customer's existing Wiki.
 *
 * Built because the same facts were assembled in four places — EnterpriseWikiMaintainerDecisionService
 * (twice), EnterpriseWikiMaintainerDecisionBatchEvaluator, and the persisted-batch preparation — and
 * they had drifted apart. The queued split path, which is what production uses for any document
 * large enough to need batches, called decideGlobalPlan()/decidePersistedCandidateBatch() WITHOUT
 * source elements, so the planner there saw 12 000 characters of flat text instead of the document's
 * 515 addressable elements. It was then asked to cite element keys it could not see; the evidence
 * binding was only ever satisfied later, by the repair pass, which did build the full context.
 *
 * The mechanism that stops that recurring is not this class on its own — it is that the planning
 * methods now take THIS OBJECT instead of six or eight loose arrays. A caller cannot forget a field
 * that is not an argument.
 *
 * FACTS ONLY. Deliberately absent, because they are properties of a CALL rather than of the
 * document: language, AiCallContext/job budget, model, capacity profile, the decision being built,
 * run status. Equally absent: any knowledge of prompts. Which slice of these facts a given AI call
 * receives is a view decision that stays where it belongs — in the client, the split coordinator and
 * EnterpriseWikiDocumentSectionMap's routing.
 *
 * Rebuilt per process rather than passed between queue jobs: it is a deterministic function of
 * (customer, document), so a batch job rebuilds an identical context instead of receiving one.
 */
class EnterpriseWikiPlanningContext
{
    /** @var array<int, array<string, mixed>>|null Memoised — see existingPageCandidates(). */
    private ?array $memoisedExistingPageCandidates = null;

    /**
     * @param  array{title: string, filename: string}  $sourceMeta
     * @param  list<array<string, mixed>>  $elements  Every extracted element, images included.
     * @param  list<array<string, mixed>>  $catalogElements  The prose/table subset the SOURCE ELEMENTS catalog renders.
     * @param  list<array<string, mixed>>  $figureCandidates  Showable images only.
     * @param  array{sections: list<array<string, mixed>>, section_by_element: array<string, string>, sectionless_element_keys: list<string>}  $sectionMap
     * @param  array<int, array<string, mixed>>  $wikiIndex
     * @param  list<string>  $validSourceElementKeys
     * @param  list<string>  $validFigureKeys
     * @param  Closure(): array<int, array<string, mixed>>  $existingPageCandidatesResolver
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $documentId,
        public readonly array $sourceMeta,
        public readonly string $sourceText,
        public readonly array $elements,
        public readonly array $catalogElements,
        public readonly array $figureCandidates,
        public readonly array $sectionMap,
        public readonly array $wikiIndex,
        public readonly array $validSourceElementKeys,
        public readonly array $validFigureKeys,
        private readonly Closure $existingPageCandidatesResolver,
    ) {}

    /**
     * The deterministic factory every planning path goes through. Same (customer, document) in,
     * byte-identical facts out, whichever entrypoint asked.
     */
    public static function forDocument(int $customerId, EnterpriseWikiDocument $document): self
    {
        $elements = app(EnterpriseWikiDocumentSourceElementService::class)->inspect($document)['elements'];
        $catalogElements = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements);
        $figureCandidates = array_values(array_filter(
            $elements,
            static fn (array $element): bool => ($element['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE,
        ));

        return new self(
            customerId: $customerId,
            documentId: (int) $document->id,
            sourceMeta: [
                'title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown',
                'filename' => (string) $document->original_filename,
            ],
            sourceText: (string) ($document->extracted_text ?? ''),
            elements: $elements,
            catalogElements: $catalogElements,
            figureCandidates: $figureCandidates,
            sectionMap: EnterpriseWikiDocumentSectionMap::build($catalogElements),
            wikiIndex: app(EnterpriseWikiIndexContextService::class)->buildForCustomer($customerId),
            validSourceElementKeys: self::keysOf($catalogElements),
            validFigureKeys: self::keysOf($figureCandidates),
            // Lazy on purpose: EnterpriseWikiPatchCandidateService loads and scores every candidate
            // page of the customer against the document text, and the phase-2 batch prompt does not
            // render page candidates at all — building them eagerly would pay that cost once per
            // batch job for something those jobs never use.
            existingPageCandidatesResolver: static fn (): array => app(EnterpriseWikiPatchCandidateService::class)->findForDocument($document),
        );
    }

    /**
     * The few existing pages this document plausibly revises, with their real current content.
     * Resolved on first use and memoised for the life of this context.
     *
     * @return array<int, array<string, mixed>>
     */
    public function existingPageCandidates(): array
    {
        return $this->memoisedExistingPageCandidates ??= ($this->existingPageCandidatesResolver)();
    }

    /** Whether the candidates have actually been resolved — observability and tests only. */
    public function hasResolvedExistingPageCandidates(): bool
    {
        return $this->memoisedExistingPageCandidates !== null;
    }

    /**
     * The facts, as a comparable array. Two contexts built for the same document from different
     * entrypoints must be identical here — that is the anti-divergence invariant, and it
     * deliberately excludes the lazy field so comparing does not force it to load.
     *
     * @return array<string, mixed>
     */
    public function factsFingerprint(): array
    {
        return [
            'customer_id' => $this->customerId,
            'document_id' => $this->documentId,
            'source_meta' => $this->sourceMeta,
            'source_text' => $this->sourceText,
            'elements' => $this->elements,
            'catalog_elements' => $this->catalogElements,
            'figure_candidates' => $this->figureCandidates,
            'section_map' => $this->sectionMap,
            'wiki_index' => $this->wikiIndex,
            'valid_source_element_keys' => $this->validSourceElementKeys,
            'valid_figure_keys' => $this->validFigureKeys,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<string>
     */
    private static function keysOf(array $elements): array
    {
        return array_values(array_filter(array_map(
            static fn (array $element): string => trim((string) ($element['source_element_key'] ?? '')),
            $elements,
        ), static fn (string $key): bool => $key !== ''));
    }
}
