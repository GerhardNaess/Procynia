<?php

namespace App\Services\Api\Presenters;

/**
 * Enterprise Wiki as the external API presents it: published knowledge only (D18).
 *
 * In: a page's title, slug, type, scope, the number of its published version, its headings and a
 * short excerpt; for one page also its published text and the published pages that text links to;
 * for «Spør Wiki» the answer, its status and its citations (D21) — page, published version,
 * heading and the quoted Wiki text. Out: the working version and whether one exists, page status,
 * content blocks and their source excerpts, claims and the verification basis, source documents,
 * runs and findings, figures, owners, reviewers and QA, internal version ids, the retrieval
 * ranking, and web routes.
 *
 * Built from EnterpriseWikiPublishedKnowledgeReader's catalog entries, which already hold only
 * published versions; nothing here reads a page or version of its own.
 */
class WikiPresenter
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public function rows(array $entries): array
    {
        return array_map(fn (array $entry): array => $this->row($entry), $entries);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    public function detail(array $entry, array $links): array
    {
        return [
            ...$this->row($entry),
            'content_markdown' => (string) $entry['content_markdown'],
            'links' => array_map(fn (array $target): array => $this->reference($target), $links),
        ];
    }

    /**
     * @param  array{answer_status: string, answer: string, citations: list<array<string, mixed>>, retrieval: array<string, mixed>}  $result
     * @return array<string, mixed>
     */
    public function answer(array $result): array
    {
        return [
            'answer_status' => $result['answer_status'],
            'answer' => $result['answer'],
            'citations' => array_map(static fn (array $citation): array => [
                'page_id' => (int) $citation['page_id'],
                'title' => (string) $citation['page_title'],
                'slug' => (string) $citation['page_slug'],
                'version_number' => (int) $citation['page_version_number'],
                'heading' => $citation['heading'],
                'excerpt' => (string) $citation['excerpt'],
            ], $result['citations']),
            'pages_used' => (int) $result['retrieval']['pages_used'],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function row(array $entry): array
    {
        return [
            ...$this->reference($entry),
            'scope' => (string) $entry['scope'] !== '' ? (string) $entry['scope'] : null,
            'version_number' => (int) $entry['version_number'],
            'headings' => array_values((array) $entry['headings']),
            'excerpt' => (string) $entry['excerpt'],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{id: int, title: string, slug: string, page_type: string}
     */
    private function reference(array $entry): array
    {
        return [
            'id' => (int) $entry['page_id'],
            'title' => (string) $entry['title'],
            'slug' => (string) $entry['slug'],
            'page_type' => (string) $entry['page_type'],
        ];
    }
}
