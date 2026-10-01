<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A semantic edge between two classified Wiki pages.
 *
 * Directional and stored once. Unlike EnterpriseWikiPageLink — which keeps a reverse row per
 * relation so traversal never needs a reverse lookup — a quality relation has a real direction that
 * carries meaning: a policy governs a process, a process does not govern a policy. Writing a
 * mirrored row would therefore state something false. Reverse traversal is a `to_page_id` lookup,
 * which the index on that column covers.
 */
class QualityRelation extends Model
{
    /** Policy -> Process. The governing document sets the frame for the process. */
    public const TYPE_GOVERNS = 'governs';

    /** Process -> Checklist. The process is carried out with this checklist in hand. */
    public const TYPE_USES = 'uses';

    /** Control -> Process. The control is what confirms the process is actually followed. */
    public const TYPE_VERIFIES = 'verifies';

    /** Process -> Process. One process cannot run until the other has delivered. */
    public const TYPE_DEPENDS_ON = 'depends_on';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_GOVERNS,
        self::TYPE_USES,
        self::TYPE_VERIFIES,
        self::TYPE_DEPENDS_ON,
    ];

    /**
     * Which quality types may sit at each end of each relation.
     *
     * Declared as data rather than as conditionals so widening the model is one line here —
     * "policy governs procedure", "procedure uses checklist" and the rest of the matrix are
     * deliberately left out of V1, not designed out of it.
     *
     * @var array<string, array{from: list<string>, to: list<string>}>
     */
    public const TYPE_MATRIX = [
        self::TYPE_GOVERNS => [
            'from' => [QualityPageClassification::TYPE_POLICY],
            'to' => [QualityPageClassification::TYPE_PROCESS],
        ],
        self::TYPE_USES => [
            'from' => [QualityPageClassification::TYPE_PROCESS],
            'to' => [QualityPageClassification::TYPE_CHECKLIST],
        ],
        self::TYPE_VERIFIES => [
            'from' => [QualityPageClassification::TYPE_CONTROL],
            'to' => [QualityPageClassification::TYPE_PROCESS],
        ],
        self::TYPE_DEPENDS_ON => [
            'from' => [QualityPageClassification::TYPE_PROCESS],
            'to' => [QualityPageClassification::TYPE_PROCESS],
        ],
    ];

    /** A person drew this edge. */
    public const SOURCE_MANUAL = 'manual';

    /** Reserved for the later derived process definition. Nothing writes it yet. */
    public const SOURCE_DERIVED = 'derived';

    protected $fillable = [
        'customer_id',
        'from_page_id',
        'to_page_id',
        'relation_type',
        'source',
        'created_by_user_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'from_page_id');
    }

    public function toPage(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'to_page_id');
    }

    /**
     * @return list<string>
     */
    public static function allowedFromTypes(string $relationType): array
    {
        return self::TYPE_MATRIX[$relationType]['from'] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function allowedToTypes(string $relationType): array
    {
        return self::TYPE_MATRIX[$relationType]['to'] ?? [];
    }
}
