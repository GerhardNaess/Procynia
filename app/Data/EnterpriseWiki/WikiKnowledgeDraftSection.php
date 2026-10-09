<?php

namespace App\Data\EnterpriseWiki;

/**
 * One part of what a module record can contribute to a Wiki source: a heading and its lines,
 * already in plain language. The person handing over chooses which sections go; `key` is how that
 * choice is sent back — never the text, which the server rebuilds from the record.
 */
final readonly class WikiKnowledgeDraftSection
{
    /** @param  list<string>  $lines */
    public function __construct(
        public string $key,
        public string $heading,
        public array $lines,
        public bool $asList = true,
    ) {}

    /** @return array{key: string, heading: string, lines: list<string>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'heading' => $this->heading, 'lines' => $this->lines];
    }
}
