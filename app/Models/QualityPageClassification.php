<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What kind of styrende dokument one Wiki page is, in the virksomhet's quality system.
 *
 * Separate from EnterpriseWikiPage::PAGE_TYPES on purpose — see the migration for why. The two
 * vocabularies must never be merged: page_type describes how the Wiki pipeline produced the page,
 * this describes what role the document plays in the kvalitetssystem.
 */
class QualityPageClassification extends Model
{
    /** Why we do it. Governs processes. */
    public const TYPE_POLICY = 'policy';

    /** What is done, end to end. The backbone the rest hangs off. */
    public const TYPE_PROCESS = 'process';

    /** How a process step is carried out. */
    public const TYPE_PROCEDURE = 'procedure';

    /** The hands-on detail, for one task at one place. */
    public const TYPE_WORK_INSTRUCTION = 'work_instruction';

    /** What must be ticked off while doing the work. */
    public const TYPE_CHECKLIST = 'checklist';

    /** What verifies that the work was done as described. */
    public const TYPE_CONTROL = 'control';

    /**
     * Ordered from governing to operational. The order is used for presentation, so it is declared
     * once here rather than repeated in the controller and in React.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_POLICY,
        self::TYPE_PROCESS,
        self::TYPE_PROCEDURE,
        self::TYPE_WORK_INSTRUCTION,
        self::TYPE_CHECKLIST,
        self::TYPE_CONTROL,
    ];

    /** A person classified this page. */
    public const SOURCE_MANUAL = 'manual';

    /** Reserved for the later derived process definition. Nothing writes it yet. */
    public const SOURCE_DERIVED = 'derived';

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_page_id',
        'quality_type',
        'quality_code',
        'source',
        'classified_by_user_id',
        'classified_at',
    ];

    protected function casts(): array
    {
        return [
            'classified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function classifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classified_by_user_id');
    }
}
