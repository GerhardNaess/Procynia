<?php

namespace App\Data\EnterpriseWiki;

/**
 * The structured knowledge basis a module offers for one record: a suggested title and the
 * sections it can contribute. Built by the module from its own domain objects (never a prompt,
 * never a raw record dump) and turned into a Wiki source by WikiKnowledgeHandoffService.
 *
 * `context` is a section that always goes with the source — what the reader needs to place the
 * chosen sections, such as the date they speak for. It is never offered as a choice. `notice` is
 * what the person should know before handing this kind of record over; it is shown, never sent.
 */
final readonly class WikiKnowledgeDraft
{
    /** @param  list<WikiKnowledgeDraftSection>  $sections */
    public function __construct(
        public string $suggestedTitle,
        public array $sections,
        public ?WikiKnowledgeDraftSection $context = null,
        public ?string $notice = null,
    ) {}

    /** Sections with something to say; an empty section is never offered. */
    public function offeredSections(): array
    {
        return array_values(array_filter(
            $this->sections,
            static fn (WikiKnowledgeDraftSection $section): bool => $section->lines !== [],
        ));
    }

    /** The section that always goes with the source, when it has something to say. */
    public function includedContext(): ?WikiKnowledgeDraftSection
    {
        return $this->context !== null && $this->context->lines !== [] ? $this->context : null;
    }

    /** @return array{title: string, sections: list<array{key: string, heading: string, lines: list<string>}>, context: ?array{key: string, heading: string, lines: list<string>}, notice: ?string} */
    public function toArray(): array
    {
        return [
            'title' => $this->suggestedTitle,
            'sections' => array_map(static fn (WikiKnowledgeDraftSection $section): array => $section->toArray(), $this->offeredSections()),
            'context' => $this->includedContext()?->toArray(),
            'notice' => $this->notice,
        ];
    }
}
