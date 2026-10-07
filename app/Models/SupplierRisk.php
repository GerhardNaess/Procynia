<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That a risk in Risiko concerns a supplier — registered from it («created_from_supplier») or
 * connected afterwards («linked»). Nothing about the risk is kept here: its level, status, owner,
 * treatment and assessments are read from Risiko, through that module's access rules, when they are
 * shown. Once it exists the risk is an ordinary risk; the supplier neither owns nor steers it.
 *
 * Written only by SupplierRiskService. Has no access rules of its own: reach it through a supplier
 * from SupplierAccessService and show a risk only when RiskAccessService::visibleRisks() returns it.
 */
class SupplierRisk extends Model
{
    public const ORIGIN_CREATED_FROM_SUPPLIER = 'created_from_supplier';

    public const ORIGIN_LINKED = 'linked';

    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'risk_id',
        'origin',
        'created_by',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'risk_id');
    }
}
