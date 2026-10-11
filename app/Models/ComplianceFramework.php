<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Et rammeverk i Kravbiblioteket: one edition of a standardised requirement package (ISO 9001:2015
 * first), owned by Procynia and shared by every customer. Not customer data: no customer_id, and
 * written only by ComplianceFrameworkLibraryImporter.
 *
 * A customer activates it into its own register (ComplianceFrameworkActivationService); from then
 * on the customer's ComplianceSource carries framework_id and its requirements point at this
 * framework's nodes. key is the stable identity («iso9001:2015»); a new edition is a new framework.
 *
 * content_verified says whether the requirement descriptions have been quality-assured against a
 * licensed copy of the standard. The pages say so when they have not.
 */
class ComplianceFramework extends Model
{
    protected $fillable = [
        'key',
        'catalog_key',
        'reference',
        'edition',
        'name',
        'description',
        'scope_note',
        'content_verified',
        'verification_note',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'content_verified' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** Every node, sections and requirements, withdrawn ones included, in the standard's order. */
    public function nodes(): HasMany
    {
        return $this->hasMany(ComplianceFrameworkNode::class, 'framework_id')->orderBy('position');
    }

    /** The individually assessable requirements still in the library. */
    public function requirementNodes(): HasMany
    {
        return $this->nodes()
            ->where('kind', ComplianceFrameworkNode::KIND_REQUIREMENT)
            ->whereNull('withdrawn_at');
    }

    /** «ISO 9001:2015 + A1:2024». */
    public function label(): string
    {
        return "{$this->reference}:{$this->edition}";
    }
}
