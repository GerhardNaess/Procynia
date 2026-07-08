<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;

class EnterpriseWikiIndexContextService
{
    private const EXCERPT_MAX_LENGTH = 200;

    /**
     * Build a structured index of all wiki pages for a customer.
     *
     * Returns one entry per page with title, slug, page_type, status, a short
     * plain-text excerpt from the current page version, open lint count, and
     * last updated timestamp. Intended as read-only context for a future
     * maintainer decision step — no AI calls, no mutations.
     *
     * @return array<int, array{
     *   id: int,
     *   title: string,
     *   slug: string,
     *   page_type: string,
     *   status: string,
     *   excerpt: string|null,
     *   open_lint_count: int,
     *   updated_at: string|null,
     * }>
     */
    public function buildForCustomer(int $customerId): array
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->with('currentVersion')
            ->withCount([
                'lintFindings as open_lint_count' => fn ($q) => $q
                    ->where('customer_id', $customerId)
                    ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN),
            ])
            ->orderByDesc('updated_at')
            ->get();

        return $pages->map(fn (EnterpriseWikiPage $page) => [
            'id'             => $page->id,
            'title'          => $page->title,
            'slug'           => $page->slug,
            'page_type'      => $page->page_type,
            'status'         => $page->status,
            'excerpt'        => $this->extractExcerpt($page->currentVersion?->content_markdown),
            'open_lint_count' => (int) ($page->open_lint_count ?? 0),
            'updated_at'     => $page->updated_at?->toIso8601String(),
        ])->all();
    }

    private function extractExcerpt(?string $markdown): ?string
    {
        if ($markdown === null || trim($markdown) === '') {
            return null;
        }

        $text = $markdown;
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
        $text = preg_replace('/__([^_]+)__/', '$1', $text);
        $text = preg_replace('/\*([^*]+)\*/', '$1', $text);
        $text = preg_replace('/_([^_]+)_/', '$1', $text);
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/', '', $text);
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
        $text = preg_replace('/^\s*[-*+>]\s+/m', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= self::EXCERPT_MAX_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::EXCERPT_MAX_LENGTH) . '…';
    }
}
