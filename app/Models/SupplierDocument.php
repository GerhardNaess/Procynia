<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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
 * Once a row is the basis of a control it can no longer be deleted — nor can a row that renews it,
 * so the chain from the control to the current edition stays (supplier-assurance-v2-plan §10.4). It
 * can still be corrected: the control keeps its own snapshot.
 *
 * Written only by SupplierDocumentService. Has no access rules of its own — reach it only through a
 * supplier from SupplierAccessService::visibleSuppliers().
 */
class SupplierDocument extends Model
{
    /**
     * Avtale · Databehandleravtale · Taushetserklæring · Sertifikat · Forsikringsbevis ·
     * Sikkerhetsdokumentasjon · Egenerklæring · Etiske retningslinjer · Revisjonsrapport ·
     * Kontrollrapport · Underleverandørliste · Offentlig attest · Økonomisk dokumentasjon ·
     * Miljødokumentasjon · Policy/rutine · Annet (the v2 types: supplier-assurance-v2-plan §10.1).
     */
    public const TYPES = [
        'agreement',
        'data_processing_agreement',
        'confidentiality_agreement',
        'certificate',
        'insurance_certificate',
        'security_documentation',
        'self_declaration',
        'code_of_conduct',
        'audit_report',
        'control_report',
        'subcontractor_list',
        'public_certificate',
        'financial_statement',
        'environmental_documentation',
        'policy',
        'other',
    ];

    /** Suggestions for «Standard» in the form; free text, never read by a rule (§10.1). */
    public const STANDARD_SUGGESTIONS = ['ISO 27001', 'ISO 9001', 'ISO 14001', 'ISO 45001', 'ISO 22301', 'Miljøfyrtårn', 'EMAS', 'SOC 2 Type II'];

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NO_EXPIRY = 'no_expiry';

    public const STATUS_REPLACED = 'replaced';

    /**
     * «Utløper snart»: «Gyldig til» within this many days from today, today and the last day included
     * (docs/supplier-management-v1-plan.md §8). The one place the window is set.
     */
    public const EXPIRING_SOON_DAYS = 60;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'document_type',
        'title',
        'standard',
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
     * read, never stored. Whether one is about to expire is isExpiringSoon().
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

    /**
     * Still valid, not replaced, and «Gyldig til» no more than EXPIRING_SOON_DAYS away. A replaced row
     * never counts; if its renewal is deleted it is the current row again and counts once more.
     */
    public function isExpiringSoon(CarbonInterface $today): bool
    {
        return $this->validityStatus($today) === self::STATUS_VALID
            && $this->daysUntilExpiry($today) <= self::EXPIRING_SOON_DAYS;
    }

    /** Whole days from today to «Gyldig til» (0 on the last valid day); null without a date. */
    public function daysUntilExpiry(CarbonInterface $today): ?int
    {
        if ($this->valid_until === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($today->toDateString())->diffInDays(CarbonImmutable::parse($this->valid_until->toDateString()), false);
    }

    /**
     * Not the basis of any control, and neither is any row it renews (directly or further back): the
     * row may be deleted as registered by mistake. Otherwise the history would lose its evidence or
     * the way from it to the current edition.
     */
    public function isDeletable(): bool
    {
        $chain = [(int) $this->id];
        $frontier = $chain;

        while ($frontier !== []) {
            $frontier = self::query()->whereIn('replaced_by_document_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $chain = [...$chain, ...$frontier];
        }

        return ! SupplierRequirementEvaluationDocument::query()->whereIn('supplier_document_id', $chain)->exists();
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
