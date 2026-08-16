<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\SavedNoticeAiRequirementWikiAnswer;

/**
 * Resolves the figure REFERENCES stored on a Wiki answer into something the frontend can display,
 * against live Wiki state and the answer's owning customer.
 *
 * The stored answer deliberately holds identity only. Everything a reader sees — caption, alt text,
 * source citation, image URL — is rebuilt here, every time, from the figure's current Wiki page. So
 * a figure whose page was rewritten shows the current caption, and a figure whose source document
 * was deleted (its block withdrawn from the page by EnterpriseWikiDocumentWithdrawalService) simply
 * stops being returned. A saved answer can therefore never render a broken image, and never
 * outlives the Wiki content that justified it — while the answer text itself stays intact.
 */
class RequirementWikiAnswerFigureResolver
{
    public function __construct(
        private readonly RequirementWikiFigureCatalog $figureCatalog = new RequirementWikiFigureCatalog,
    ) {}

    /**
     * Purpose: Turn the answer's stored figure references into display-ready figures.
     * Inputs: The answer row and the customer that owns the case.
     * Returns: One entry per still-valid figure, in stored order:
     *          {figure_ref, page_id, page_title, page_slug, section_key, section_index, caption,
     *           alt_text, page_reference, image_url}.
     * Side effects: None (read-only).
     *
     * A reference is dropped — never hard-failed — when its page is gone, belongs to another
     * customer, or no longer carries that figure.
     *
     * @return list<array<string, mixed>>
     */
    public function resolve(SavedNoticeAiRequirementWikiAnswer $answer, int $customerId): array
    {
        $storedFigures = array_values(array_filter(
            (array) ($answer->answer_figures ?? []),
            static fn ($figure): bool => is_array($figure),
        ));

        if ($storedFigures === []) {
            return [];
        }

        $pageIds = array_values(array_unique(array_map(
            static fn (array $figure): int => (int) ($figure['page_id'] ?? 0),
            $storedFigures,
        )));

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $pageIds)
            ->with('currentVersion')
            ->get()
            ->keyBy('id');

        $liveFiguresByPageId = [];

        foreach ($pages as $pageId => $page) {
            $byRef = [];

            foreach ($this->figureCatalog->fromContentBlocks((array) ($page->currentVersion->content_blocks_json ?? [])) as $figure) {
                $byRef[$figure['figure_ref']] = $figure;
            }

            $liveFiguresByPageId[(int) $pageId] = $byRef;
        }

        $resolved = [];

        foreach ($storedFigures as $stored) {
            $pageId = (int) ($stored['page_id'] ?? 0);
            $ref = (string) ($stored['figure_ref'] ?? '');
            $live = $liveFiguresByPageId[$pageId][$ref] ?? null;
            $page = $pages->get($pageId);

            if ($live === null || $page === null) {
                continue;
            }

            $resolved[] = [
                'figure_ref' => $ref,
                'page_id' => $pageId,
                'page_title' => (string) $page->title,
                'page_slug' => (string) $page->slug,
                'section_key' => (string) ($stored['section_key'] ?? ''),
                'section_index' => (int) ($stored['section_index'] ?? 0),
                'caption' => $this->displayCaption($live),
                'alt_text' => (string) ($live['alt_text'] ?? ''),
                'page_reference' => $live['page_reference'],
                'image_url' => route('app.wiki.sources.image', [$live['document_id'], $live['source_image_key']]),
            ];
        }

        return $resolved;
    }

    /**
     * Purpose: Split the answer into the sections it was generated as, so each figure can be shown
     *          at the section the model chose rather than dumped at the end.
     * Inputs: The answer row and its resolved figures.
     * Returns: Ordered segments {section_key, text, figures}, or null when the segmentation cannot
     *          be proven to still describe answer_text.
     * Side effects: None.
     *
     * The proof is deliberately strict: the persisted section texts must still join back to exactly
     * the stored answer_text. Once someone has hand-edited the answer, the old section boundaries
     * are a guess, and a guess would place a figure against text it does not illustrate. In that
     * case the caller falls back to showing the answer whole with its figures after it — the
     * figures are never lost, only their placement is.
     *
     * @param  list<array<string, mixed>>  $resolvedFigures
     * @return list<array<string, mixed>>|null
     */
    public function segments(SavedNoticeAiRequirementWikiAnswer $answer, array $resolvedFigures): ?array
    {
        if ($resolvedFigures === []) {
            return null;
        }

        $sections = (array) data_get($answer->research_trace, 'answer.answer_sections', []);
        $texts = [];

        foreach ($sections as $section) {
            if (! is_array($section) || ! is_string($section['text'] ?? null)) {
                return null;
            }

            $texts[] = $section['text'];
        }

        if ($texts === [] || implode("\n\n", $texts) !== (string) $answer->answer_text) {
            return null;
        }

        $figuresBySectionIndex = [];

        foreach ($resolvedFigures as $figure) {
            $figuresBySectionIndex[(int) $figure['section_index']][] = $figure;
        }

        $segments = [];

        foreach (array_values($sections) as $index => $section) {
            $segments[] = [
                'section_key' => (string) ($section['key'] ?? 'S'.($index + 1)),
                'text' => (string) $section['text'],
                'figures' => $figuresBySectionIndex[$index] ?? [],
            ];
        }

        return $segments;
    }

    /**
     * A Word figure often has no caption of its own; the Wiki page shows its figure number instead,
     * and the answer must read the same way.
     *
     * @param  array<string, mixed>  $figure
     */
    private function displayCaption(array $figure): string
    {
        $caption = (string) ($figure['caption'] ?? '');

        if (trim($caption) !== '') {
            return trim($caption);
        }

        return $figure['figure_number'] !== null
            ? sprintf('Figur %d', (int) $figure['figure_number'])
            : 'Figur';
    }
}
