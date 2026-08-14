<?php

namespace App\Services\EnterpriseWiki;

/**
 * The document's own section structure, as a deterministic addressing scheme.
 *
 * This is CONTEXT ROUTING, not retrieval. It answers exactly one question — "which parts of the
 * document does this AI call need in full text?" — and it answers it from structure the extraction
 * already produced (`section_number`/`section_title` on every element), never from similarity,
 * keywords or any lexical comparison. Nothing here decides anything about knowledge.
 *
 * Why it exists: profiling the planning phase showed that 69 % of every Phase B batch prompt was the
 * complete element catalog (76 945 of 112 300 characters on a 77 586-character document), that the
 * catalog was re-sent in all nine planning calls, and that only ~1.4 % of a batch prompt differed
 * between batches. The document has 24 sections averaging ~3 165 rendered characters, so a batch
 * that names its own sections can be given real full text for those and a 919-character overview of
 * everything else.
 *
 * Section keys are positional in document order (`sec-0`, `sec-1`, …) and stable for one document
 * version — the same property the element keys already rely on. They are rendered into the catalog
 * so a planning call can cite them back, exactly as it cites element keys.
 */
class EnterpriseWikiDocumentSectionMap
{
    /** Elements that carry no section at all — never routable, therefore always included. */
    public const SECTIONLESS_KEY = 'sec-none';

    /**
     * @param  list<array<string, mixed>>  $elements  Catalog elements in document order.
     * @return array{
     *   sections: list<array{key: string, label: string, number: string, title: string, element_keys: list<string>, chars: int}>,
     *   section_by_element: array<string, string>,
     *   sectionless_element_keys: list<string>,
     * }
     */
    public static function build(array $elements): array
    {
        $sections = [];
        $order = [];
        $sectionByElement = [];
        $sectionless = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $elementKey = trim((string) ($element['source_element_key'] ?? ''));

            if ($elementKey === '') {
                continue;
            }

            $number = trim((string) ($element['section_number'] ?? ''));
            $title = trim((string) ($element['section_title'] ?? ''));
            $label = trim($number.' '.$title);

            if ($label === '') {
                $sectionless[] = $elementKey;
                $sectionByElement[$elementKey] = self::SECTIONLESS_KEY;

                continue;
            }

            if (! array_key_exists($label, $order)) {
                $order[$label] = 'sec-'.count($order);
                $sections[$order[$label]] = [
                    'key' => $order[$label],
                    'label' => $label,
                    'number' => $number,
                    'title' => $title,
                    'element_keys' => [],
                    'chars' => 0,
                ];
            }

            $sectionKey = $order[$label];
            $sections[$sectionKey]['element_keys'][] = $elementKey;
            $sections[$sectionKey]['chars'] += mb_strlen(trim((string) ($element['reference_text'] ?? '')));
            $sectionByElement[$elementKey] = $sectionKey;
        }

        return [
            'sections' => array_values($sections),
            'section_by_element' => $sectionByElement,
            'sectionless_element_keys' => $sectionless,
        ];
    }

    /**
     * The section label a renderer prints for an element, prefixed with its routable key.
     *
     * @param  array<string, mixed>  $element
     */
    public static function sectionLabel(array $element): string
    {
        return trim(trim((string) ($element['section_number'] ?? '')).' '.trim((string) ($element['section_title'] ?? '')));
    }

    /**
     * Every section key that exists for this document — what a cited key is validated against.
     *
     * @param  array{sections: list<array<string, mixed>>}  $map
     * @return list<string>
     */
    public static function sectionKeys(array $map): array
    {
        return array_values(array_map(static fn (array $section): string => (string) $section['key'], $map['sections']));
    }

    /**
     * The compact overview EVERY planning call carries, whether or not it gets full text: one line
     * per section with its key, label and size.
     *
     * This is what keeps a sliced call honest about its own blind spots — the model can see that
     * section sec-7 exists, how big it is, and that it was not given its text, instead of silently
     * reasoning as if the document ended where its slice ends.
     *
     * @param  array{sections: list<array<string, mixed>>, sectionless_element_keys: list<string>}  $map
     * @param  list<string>  $includedSectionKeys  Sections this call receives in full text.
     */
    public static function overviewBlock(array $map, array $includedSectionKeys): string
    {
        $included = array_flip($includedSectionKeys);
        $lines = [
            'DOCUMENT SECTION OVERVIEW ('.count($map['sections']).' sections):',
            'Every section of the source document, in document order. "full text below" marks the',
            'sections whose elements you were given in SOURCE ELEMENTS; the others exist but were not',
            'included for this call — never assume a topic is absent from the document just because',
            'its section is not shown here in full.',
        ];

        foreach ($map['sections'] as $section) {
            $lines[] = sprintf(
                '- [%s] %s (%d elements, %d chars)%s',
                $section['key'],
                $section['label'],
                count($section['element_keys']),
                $section['chars'],
                isset($included[$section['key']]) ? ' — full text below' : '',
            );
        }

        if ($map['sectionless_element_keys'] !== []) {
            $lines[] = sprintf(
                '- [%s] elements outside any section (%d elements) — always included in full text below',
                self::SECTIONLESS_KEY,
                count($map['sectionless_element_keys']),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The elements a call receives in full text: every element of the named sections, plus every
     * section-less element.
     *
     * Section-less elements are unconditional on purpose. They belong to no section, so no citation
     * can ever route them in — dropping them would make them permanently invisible to Phase B (29 of
     * 515 elements on the document this was measured against).
     *
     * @param  list<array<string, mixed>>  $elements
     * @param  list<string>  $sectionKeys
     * @param  array{section_by_element: array<string, string>}  $map
     * @return list<array<string, mixed>>
     */
    public static function elementsForSections(array $elements, array $sectionKeys, array $map): array
    {
        $wanted = array_flip(array_merge($sectionKeys, [self::SECTIONLESS_KEY]));

        return array_values(array_filter($elements, static function (mixed $element) use ($wanted, $map): bool {
            if (! is_array($element)) {
                return false;
            }

            $key = trim((string) ($element['source_element_key'] ?? ''));
            $sectionKey = $map['section_by_element'][$key] ?? null;

            return $sectionKey !== null && isset($wanted[$sectionKey]);
        }));
    }

    /**
     * The sections a set of element keys belongs to — how a bounded repair routes context from the
     * evidence its own objects already cite, without re-deriving anything semantic.
     *
     * @param  list<string>  $elementKeys
     * @param  array{section_by_element: array<string, string>}  $map
     * @return list<string>
     */
    public static function sectionsForElementKeys(array $elementKeys, array $map): array
    {
        $keys = [];

        foreach ($elementKeys as $elementKey) {
            $sectionKey = $map['section_by_element'][trim((string) $elementKey)] ?? null;

            if ($sectionKey !== null && $sectionKey !== self::SECTIONLESS_KEY) {
                $keys[$sectionKey] = true;
            }
        }

        return array_keys($keys);
    }
}
