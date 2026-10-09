<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One documentation row given as the basis of a control, with the row's type, name, standard,
 * location and validity as the person saw them (docs/supplier-assurance-v2-plan.md §10.2.1) — and,
 * since v2.1, the key, name and SHA-256 of the row's file, which is what shows which file the control
 * was based on (§27).
 *
 * The snapshot is what the history shows; the document row itself stays editable and is what
 * today's status is read from. Correcting the row never changes this one, and the row cannot be
 * deleted while this exists.
 *
 * Immutable, like the control. Written only by SupplierRequirementEvaluationService.
 */
class SupplierRequirementEvaluationDocument extends Model
{
    /** The document fields copied into the snapshot, by snapshot column. */
    public const SNAPSHOT = [
        'document_type' => 'document_type',
        'document_title' => 'title',
        'document_standard' => 'standard',
        'document_location' => 'location',
        'document_valid_from' => 'valid_from',
        'document_valid_until' => 'valid_until',
        'document_file_key' => 'file_key',
        'document_file_name' => 'file_original_name',
        'document_file_sha256' => 'file_sha256',
    ];

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'evaluation_id',
        'supplier_document_id',
        'document_type',
        'document_title',
        'document_standard',
        'document_location',
        'document_valid_from',
        'document_valid_until',
        'document_file_key',
        'document_file_name',
        'document_file_sha256',
    ];

    protected function casts(): array
    {
        return [
            'document_valid_from' => 'date',
            'document_valid_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('The documentation of a requirement control is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('The documentation of a requirement control is history and cannot be deleted.');
        });
    }

    /** The snapshot columns for a document as it is now. */
    public static function snapshotOf(SupplierDocument $document): array
    {
        $snapshot = [];

        foreach (self::SNAPSHOT as $column => $field) {
            $value = $document->{$field};
            $snapshot[$column] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $snapshot;
    }

    /** Whether the document row has been corrected since the control: any snapshot field differs. */
    public function differsFrom(SupplierDocument $document): bool
    {
        foreach (self::snapshotOf($document) as $column => $now) {
            $then = $this->{$column};
            $then = $then instanceof \DateTimeInterface ? $then->format('Y-m-d') : $then;

            if ($then !== $now) {
                return true;
            }
        }

        return false;
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(SupplierRequirementEvaluation::class, 'evaluation_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SupplierDocument::class, 'supplier_document_id');
    }
}
