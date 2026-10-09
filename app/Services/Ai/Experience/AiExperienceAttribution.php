<?php

namespace App\Services\Ai\Experience;

use Illuminate\Database\Eloquent\Builder;

/**
 * Which module an AI call belongs to, from the ledger's own attribution: the `feature` of the
 * attempt, and — for Wiki work — the module the Wiki source was handed over from, which
 * RunsInAiCallContext writes as `resource_type` (from enterprise_wiki_document_origins).
 *
 * An attribution key is the feature (`tender`, `quality`, …), plain `wiki` for Wiki work on an
 * ordinary source document, or `wiki.<module>` for Wiki work on a source a module handed over.
 */
final class AiExperienceAttribution
{
    /** enterprise_wiki_document_origins.source_type → the module it came from. */
    public const WIKI_ORIGIN_MODULES = [
        'risk' => 'risk',
        'compliance_audit' => 'compliance',
        'supplier' => 'supplier',
        'improvement_case' => 'improvements',
        'objective' => 'objectives',
        'quality_item' => 'quality',
    ];

    /**
     * The attribution keys whose usage is the package's own: its features and the Wiki work on
     * sources it handed over. Used to describe a package's direct usage — never to size anything.
     */
    public const PACKAGE_KEYS = [
        'basis' => ['wiki', 'quality', 'improvements', 'document_analysis', 'wiki.quality', 'wiki.improvements'],
        'risk' => ['risk', 'wiki.risk'],
        'objectives' => ['objectives', 'wiki.objectives'],
        'compliance' => ['compliance', 'wiki.compliance'],
        'supplier' => ['supplier', 'wiki.supplier'],
        'tender' => ['tender'],
    ];

    public static function key(?string $feature, ?string $resourceType): string
    {
        $feature = (string) ($feature ?: 'unclassified');

        if ($feature === 'wiki' && $resourceType !== null && isset(self::WIKI_ORIGIN_MODULES[$resourceType])) {
            return 'wiki.'.self::WIKI_ORIGIN_MODULES[$resourceType];
        }

        return $feature;
    }

    /** Restricts an ai_usage_attempts query to one attribution key. */
    public static function constrain(Builder $query, string $key): Builder
    {
        if (! str_starts_with($key, 'wiki.')) {
            return $key === 'wiki'
                ? $query->where('feature', 'wiki')->where(fn (Builder $inner) => $inner
                    ->whereNull('resource_type')
                    ->orWhereNotIn('resource_type', array_keys(self::WIKI_ORIGIN_MODULES)))
                : $query->where('feature', $key);
        }

        $types = array_keys(self::WIKI_ORIGIN_MODULES, substr($key, 5), true);

        return $query->where('feature', 'wiki')->whereIn('resource_type', $types === [] ? ['-'] : $types);
    }
}
