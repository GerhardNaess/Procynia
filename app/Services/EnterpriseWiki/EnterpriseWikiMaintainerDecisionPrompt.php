<?php

namespace App\Services\EnterpriseWiki;

/**
 * Defines the strict JSON contract for the Karpathy-style maintainer decision step.
 *
 * A maintainer decision is produced by an AI pass that reads:
 *  - the source document text
 *  - the existing wiki page index (from EnterpriseWikiIndexContextService)
 *
 * It decides what pages to create or update, but does NOT generate page content.
 * Content generation happens in a separate downstream step.
 *
 * Rules encoded here (used in both the JSON schema and the PHP validator):
 *  - source_article and source_summary are 1-to-1 with the source document.
 *  - concept/entity pages are shared across sources and carry a nullable page_id.
 *  - update action on a shared page requires a non-null page_id pointing to an existing page.
 *  - proposed_slug must not contain a file extension.
 *  - title must not be a raw filename (e.g. "Masterdata.pdf").
 *  - output is a decision only — no article content, no OpenAI calls in this class.
 *
 * content_responsibility/must_not_repeat/related_page_guidance (added to reduce cross-page
 * repetition — see docs/enterprise-llm-wiki-plan.md, the section on page responsibility): the
 * maintainer sees every planned page for this source document in one decision, so it is the one
 * place that can assign non-overlapping faglig ansvar between them before any page content is
 * generated. Required in the OpenAI strict JSON schema (every property must be, per the API's own
 * strict-mode constraint — see the existing concept_pages/entity_pages/warnings top-level fields
 * for the same pattern), but treated as OPTIONAL by the PHP validator/parser, defaulting to an
 * empty list when absent — exactly like every other optional top-level field in this contract —
 * so a hand-built decision (tests, or a legacy stored run predating this field) remains valid.
 */
class EnterpriseWikiMaintainerDecisionPrompt
{
    public const ACTIONS = ['create', 'update'];

    private const FILE_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'txt', 'doc', 'pptx', 'odt', 'csv'];

    /**
     * Returns the OpenAI Responses API text.format block for strict JSON output.
     * Use as: ['text' => self::jsonSchema()] in the API request body.
     */
    public static function jsonSchema(): array
    {
        $responsibilityProperties = [
            'content_responsibility' => ['type' => 'array', 'items' => ['type' => 'string']],
            'must_not_repeat' => ['type' => 'array', 'items' => ['type' => 'string']],
            'related_page_guidance' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'page_title' => ['type' => 'string'],
                        'relationship' => ['type' => 'string'],
                    ],
                    'required' => ['page_title', 'relationship'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        $sourcePageSchema = [
            'type' => 'object',
            'properties' => array_merge([
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'title' => ['type' => 'string'],
                'proposed_slug' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ], $responsibilityProperties),
            'required' => ['action', 'title', 'proposed_slug', 'reason', 'content_responsibility', 'must_not_repeat', 'related_page_guidance'],
            'additionalProperties' => false,
        ];

        $sharedPageSchema = [
            'type' => 'object',
            'properties' => array_merge([
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'page_id' => ['type' => ['integer', 'null']],
                'title' => ['type' => 'string'],
                'proposed_slug' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ], $responsibilityProperties),
            'required' => ['action', 'page_id', 'title', 'proposed_slug', 'reason', 'content_responsibility', 'must_not_repeat', 'related_page_guidance'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'maintainer_decision',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_article' => $sourcePageSchema,
                        'source_summary' => $sourcePageSchema,
                        'concept_pages' => ['type' => 'array', 'items' => $sharedPageSchema],
                        'entity_pages' => ['type' => 'array', 'items' => $sharedPageSchema],
                        'no_action_reason' => ['type' => ['string', 'null']],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => [
                        'source_article',
                        'source_summary',
                        'concept_pages',
                        'entity_pages',
                        'no_action_reason',
                        'warnings',
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Validate a raw decoded decision object.
     *
     * @param  array<string, mixed>  $raw
     * @return string[] Empty when valid; one string per error when invalid.
     */
    public static function validate(array $raw): array
    {
        $errors = [];

        foreach (['source_article', 'source_summary'] as $key) {
            if (! isset($raw[$key]) || ! is_array($raw[$key])) {
                $errors[] = "{$key} is required and must be an object.";

                continue;
            }
            $errors = array_merge($errors, self::validateSourceEntry($raw[$key], $key));
        }

        foreach (['concept_pages', 'entity_pages'] as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            if (! is_array($raw[$key])) {
                $errors[] = "{$key} must be an array.";

                continue;
            }
            foreach ($raw[$key] as $i => $entry) {
                $errors = array_merge($errors, self::validateSharedEntry($entry, "{$key}[{$i}]"));
            }
        }

        if (
            array_key_exists('no_action_reason', $raw)
            && $raw['no_action_reason'] !== null
            && ! is_string($raw['no_action_reason'])
        ) {
            $errors[] = 'no_action_reason must be a string or null.';
        }

        if (array_key_exists('warnings', $raw) && ! is_array($raw['warnings'])) {
            $errors[] = 'warnings must be an array of strings.';
        }

        return $errors;
    }

    /**
     * Validate and return a normalised decision array.
     * Optional collection keys default to empty arrays/null.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when validation fails.
     */
    public static function parse(array $raw): array
    {
        $errors = self::validate($raw);

        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'Invalid maintainer decision: '.implode(' | ', $errors)
            );
        }

        return [
            'source_article' => $raw['source_article'],
            'source_summary' => $raw['source_summary'],
            'concept_pages' => $raw['concept_pages'] ?? [],
            'entity_pages' => $raw['entity_pages'] ?? [],
            'no_action_reason' => $raw['no_action_reason'] ?? null,
            'warnings' => $raw['warnings'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Internal validators
    // -------------------------------------------------------------------------

    /** @return string[] */
    private static function validateSourceEntry(array $entry, string $ctx): array
    {
        $errors = [];

        if (! isset($entry['action']) || ! in_array($entry['action'], self::ACTIONS, true)) {
            $errors[] = "{$ctx}.action must be one of: ".implode(', ', self::ACTIONS).'.';
        }

        foreach (['title', 'proposed_slug', 'reason'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        if (isset($entry['title']) && is_string($entry['title'])) {
            $errors = array_merge($errors, self::validateNoFileExtensionInTitle($entry['title'], "{$ctx}.title"));
        }

        if (isset($entry['proposed_slug']) && is_string($entry['proposed_slug'])) {
            $errors = array_merge($errors, self::validateNoFileExtensionInSlug($entry['proposed_slug'], "{$ctx}.proposed_slug"));
        }

        foreach (['content_responsibility', 'must_not_repeat'] as $field) {
            if (! array_key_exists($field, $entry)) {
                continue;
            }

            if (! is_array($entry[$field])) {
                $errors[] = "{$ctx}.{$field} must be an array of strings.";

                continue;
            }

            foreach ($entry[$field] as $i => $item) {
                if (! is_string($item) || trim($item) === '') {
                    $errors[] = "{$ctx}.{$field}[{$i}] must be a non-empty string.";

                    continue;
                }

                $errors = array_merge($errors, self::validateNoControlCharacters($item, "{$ctx}.{$field}[{$i}]"));
            }
        }

        if (array_key_exists('related_page_guidance', $entry)) {
            if (! is_array($entry['related_page_guidance'])) {
                $errors[] = "{$ctx}.related_page_guidance must be an array.";
            } else {
                foreach ($entry['related_page_guidance'] as $i => $item) {
                    $errors = array_merge($errors, self::validateRelatedPageGuidanceEntry($item, "{$ctx}.related_page_guidance[{$i}]"));
                }
            }
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateRelatedPageGuidanceEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = [];

        foreach (['page_title', 'relationship'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateSharedEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = self::validateSourceEntry($entry, $ctx);

        if (! array_key_exists('page_id', $entry)) {
            $errors[] = "{$ctx}.page_id is required (null for create, integer for update).";
        } elseif ($entry['page_id'] !== null && ! is_int($entry['page_id'])) {
            $errors[] = "{$ctx}.page_id must be an integer or null.";
        }

        if (
            isset($entry['action']) && $entry['action'] === 'update'
            && (! isset($entry['page_id']) || ! is_int($entry['page_id']))
        ) {
            $errors[] = "{$ctx}.page_id must be a non-null integer for update action.";
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateNoFileExtensionInTitle(string $title, string $ctx): array
    {
        $ext = mb_strtolower((string) pathinfo($title, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::FILE_EXTENSIONS, true)) {
            return ["{$ctx} must not be a raw filename — strip the file extension (found: .{$ext})."];
        }

        return [];
    }

    /** @return string[] */
    private static function validateNoFileExtensionInSlug(string $slug, string $ctx): array
    {
        $extPattern = implode('|', self::FILE_EXTENSIONS);
        if (preg_match('/[-.](?:'.$extPattern.')$/i', $slug)) {
            return ["{$ctx} must not contain a file extension — remove it from the slug."];
        }

        return [];
    }

    /**
     * Rejects a maintainer-decision text field that is not valid UTF-8, or that contains a raw
     * ASCII control character (anything other than the ordinary whitespace/newline characters).
     *
     * Found via the Wiki run-34 investigation: an AI-generated title was persisted verbatim
     * containing literal control bytes (a stray low ASCII control byte, and a malformed Unicode
     * escape sequence, in the stored JSON) where a Norwegian letter with a diacritic should have
     * been — corrupting the page title. The corruption traced back to the model's own structured-
     * output text, not to any byte-unsafe truncation in this codebase
     * (EnterpriseWikiMaintainerDecisionApplyService persists title/proposed_slug/reason verbatim
     * from here). Rejecting it here, at the same validation boundary that already rejects a raw
     * filename-as-title, fails the maintainer decision step loudly and traceably instead of
     * silently storing corrupted text as a page title.
     *
     * @return string[]
     */
    private static function validateNoControlCharacters(string $value, string $ctx): array
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return ["{$ctx} is not valid UTF-8 — the AI response text is corrupted."];
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            return ["{$ctx} contains an invalid control character — the AI response text is corrupted."];
        }

        return [];
    }
}
