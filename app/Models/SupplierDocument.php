<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a supplier's dokumentasjonsoversikt: which document exists, where it is kept and how
 * long it is valid (docs/supplier-management-v1-plan.md §4.4). A description of a document, never
 * the document — there is no file, and location is only text: nothing fetches, downloads or
 * previews it.
 *
 * Mutable. Corrected in place and deleted when registered by mistake, both only while the supplier
 * is not ended. A renewed document is a new row; the old one points at it (replaced_by_document_id)
 * and reads as Erstattet.
 *
 * Written only by SupplierDocumentService. Has no access rules of its own — reach it only through a
 * supplier from SupplierAccessService::visibleSuppliers().
 */
class SupplierDocument extends Model
{
    /** Avtale · Databehandleravtale · Taushetserklæring · Sertifikat · Forsikringsbevis · Sikkerhetsdokumentasjon · Annet. */
    public const TYPES = [
        'agreement',
        'data_processing_agreement',
        'confidentiality_agreement',
        'certificate',
        'insurance_certificate',
        'security_documentation',
        'other',
    ];

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NO_EXPIRY = 'no_expiry';

    public const STATUS_REPLACED = 'replaced';

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'document_type',
        'title',
        'location',
        'valid_from',
        'valid_until',
        'comment',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function isReplaced(): bool
    {
        return $this->replaced_by_document_id !== null;
    }

    /**
     * Erstattet once renewed; otherwise Utløpt when «Gyldig til» is before today (the last day is
     * still valid), Gyldig with a date not yet passed, and Ingen utløpsdato without one. Computed on
     * read, never stored. Whether one is about to expire belongs to Trenger oppmerksomhet.
     */
    public function validityStatus(CarbonInterface $today): string
    {
        return match (true) {
            $this->isReplaced() => self::STATUS_REPLACED,
            $this->valid_until === null => self::STATUS_NO_EXPIRY,
            $this->valid_until->toDateString() < $today->toDateString() => self::STATUS_EXPIRED,
            default => self::STATUS_VALID,
        };
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_document_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
