<?php

namespace App\Data\EnterpriseWiki;

/**
 * The structured knowledge basis a module offers for one record: a suggested title and the
 * sections it can contribute. Built by the module from its own domain objects (never a prompt,
 * never a raw record dump) and turned into a Wiki source by WikiKnowledgeHandoffService.
 */
final readonly class WikiKnowledgeDraft
{
    /** @param  list<WikiKnowledgeDraftSection>  $sections */
    public function __construct(
        public string $suggestedTitle,
        public array $sections,
    ) {}

    /** Sections with something to say; an empty section is never offered. */
    public function offeredSections(): array
    {
        return array_values(array_filter(
            $this->sections,
            static fn (WikiKnowledgeDraftSection $section): bool => $section->lines !== [],
        ));
    }

    /** @return array{title: string, sections: list<array{key: string, heading: string, lines: list<string>}>} */
    public function toArray(): array
    {
        return [
            'title' => $this->suggestedTitle,
            'sections' => array_map(static fn (WikiKnowledgeDraftSection $section): array => $section->toArray(), $this->offeredSections()),
        ];
    }
}
