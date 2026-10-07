<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * En kravkilde i Etterlevelse og revisjon: where requirements come from — a standard, a law or
 * regulation, a contract, an internal requirement or something else — optionally in one version.
 *
 * Customer-wide and without a lifecycle of its own. Deletable only while no requirement uses it;
 * the requirements' foreign key refuses it otherwise. Never query this model for a user without
 * going through ComplianceAccessService::visibleSources().
 */
class ComplianceSource extends Model
{
    public const KIND_STANDARD = 'standard';

    public const KIND_LAW = 'law';

    public const KIND_CONTRACT = 'contract';

    public const KIND_INTERNAL = 'internal';

    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_STANDARD,
        self::KIND_LAW,
        self::KIND_CONTRACT,
        self::KIND_INTERNAL,
        self::KIND_OTHER,
    ];

    protected $fillable = [
        'customer_id',
        'name',
        'version',
        'kind',
        'description',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // The database refuses it too; this says so before the query is sent.
        static::saving(function (self $source): void {
            if (! in_array($source->kind, self::KINDS, true)) {
                throw new DomainException("Unknown compliance source kind [{$source->kind}].");
            }
        });
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ComplianceRequirement::class, 'source_id');
    }

    /** Whether any requirement, active or retired, still names this source. */
    public function isInUse(): bool
    {
        return $this->requirements()->exists();
    }
}
