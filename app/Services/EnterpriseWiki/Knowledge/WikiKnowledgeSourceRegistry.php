<?php

namespace App\Services\EnterpriseWiki\Knowledge;

use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\QualityItem;
use App\Services\EnterpriseWiki\Knowledge\Sources\RiskKnowledgeSource;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The record types that can be handed over to Enterprise Wiki through «Lag kunnskapsartikkel».
 *
 * Adding a module is one adapter and one line here; the handoff, the provenance, the dialog and
 * the Wiki pipeline are shared. Kvalitet hands over through the same service (deposit()) from its
 * own activity-article flow, so it is not listed as a dialog source here.
 */
class WikiKnowledgeSourceRegistry
{
    /** @var array<string, class-string<WikiKnowledgeSource>> */
    public const SOURCES = [
        'risk' => RiskKnowledgeSource::class,
    ];

    /**
     * Sources that deposit through WikiKnowledgeHandoffService::deposit() from their own flow
     * rather than through the shared dialog. Listed so their provenance follows deletion too.
     *
     * @var array<string, class-string<Model>>
     */
    public const DEPOSIT_ONLY_SOURCES = [
        'quality_item' => QualityItem::class,
    ];

    public function get(string $sourceType): WikiKnowledgeSource
    {
        $class = self::SOURCES[$sourceType] ?? throw new InvalidArgumentException("Unknown Wiki knowledge source type [{$sourceType}].");

        return app($class);
    }

    /**
     * Provenance follows its source: when a record is deleted its origin rows go, and the Wiki
     * keeps the knowledge — it was handed over and does not go back. A foreign key cannot do this
     * for a polymorphic source, so each source model gets a listener instead.
     */
    public static function registerDeletionListeners(): void
    {
        // Each adapter's MODEL constant: registered at boot without building the adapters.
        $models = array_map(static fn (string $class): string => $class::MODEL, self::SOURCES)
            + self::DEPOSIT_ONLY_SOURCES;

        foreach ($models as $sourceType => $modelClass) {
            $modelClass::deleted(static function (Model $record) use ($sourceType): void {
                EnterpriseWikiDocumentOrigin::query()
                    ->where('customer_id', $record->getAttribute('customer_id'))
                    ->where('source_type', $sourceType)
                    ->where('source_id', $record->getKey())
                    ->delete();
            });
        }
    }
}
