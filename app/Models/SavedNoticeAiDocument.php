<?php

namespace App\Models;

use App\Data\Ai\Requirements\DocxTableData;
use App\Data\Ai\Requirements\DocxTableRowData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SavedNoticeAiDocument extends Model
{
    public const PROCESSING_STATUS_UPLOADED = 'uploaded';

    public const PROCESSING_STATUS_TEXT_EXTRACTED = 'text_extracted';

    public const PROCESSING_STATUS_QUEUED = 'queued';

    public const PROCESSING_STATUS_PROCESSING = 'processing';

    public const PROCESSING_STATUS_MERGING = 'merging';

    public const PROCESSING_STATUS_COMPLETED = 'completed';

    public const PROCESSING_STATUS_FAILED = 'failed';

    public const PROCESSING_STATUSES = [
        self::PROCESSING_STATUS_UPLOADED,
        self::PROCESSING_STATUS_TEXT_EXTRACTED,
        self::PROCESSING_STATUS_QUEUED,
        self::PROCESSING_STATUS_PROCESSING,
        self::PROCESSING_STATUS_MERGING,
        self::PROCESSING_STATUS_COMPLETED,
        self::PROCESSING_STATUS_FAILED,
    ];

    public const PROCESSING_STATUS_LABELS = [
        self::PROCESSING_STATUS_UPLOADED => 'Lastet opp',
        self::PROCESSING_STATUS_TEXT_EXTRACTED => 'Tekst ekstrahert',
        self::PROCESSING_STATUS_QUEUED => 'I kø',
        self::PROCESSING_STATUS_PROCESSING => 'Behandles',
        self::PROCESSING_STATUS_MERGING => 'Slår sammen',
        self::PROCESSING_STATUS_COMPLETED => 'Fullført',
        self::PROCESSING_STATUS_FAILED => 'Feilet',
    ];

    protected $fillable = [
        'saved_notice_id',
        'uploaded_by_user_id',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size_bytes',
        'processing_status',
        'extracted_text',
        'structured_tables',
        'text_extracted_at',
        'queued_at',
        'processing_started_at',
        'processing_finished_at',
        'processing_error_type',
        'processing_error_message',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'structured_tables' => 'array',
            'text_extracted_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_finished_at' => 'datetime',
        ];
    }

    /**
     * Purpose: Resolve this document's deterministically-parsed DOCX tables (see
     * DocumentTextExtractor::extractDocxTextAndTables()), if any were captured at upload time.
     * Inputs: None.
     * Returns: The document's tables, or an empty array for non-DOCX documents/documents
     * uploaded before this feature existed.
     * Side effects: None.
     *
     * @return list<DocxTableData>
     */
    public function structuredTables(): array
    {
        $raw = $this->structured_tables;

        if (! is_array($raw)) {
            return [];
        }

        return DocxTableData::manyFromArray($raw);
    }

    /**
     * Purpose: Resolve the structured table rows whose position in this document's flat
     * extracted_text falls within a given character range — used to attribute rows to the
     * requirement-extraction chunk/window covering that range.
     * Inputs: Inclusive/exclusive character range [start, end) within extracted_text.
     * Returns: Matching rows, each translated to be relative to $start (see
     * DocxTableRowData::withOffset()) so callers can position them within the window's own text.
     * Side effects: None.
     *
     * @return list<DocxTableRowData>
     */
    public function structuredTableRowsInRange(int $start, int $end): array
    {
        $rows = [];

        foreach ($this->structuredTables() as $table) {
            foreach ($table->rows as $row) {
                if ($row->charStart >= $start && $row->charStart < $end) {
                    $rows[] = $row->withOffset($start);
                }
            }
        }

        return $rows;
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Purpose: Resolve the ordered text chunks extracted from this AI document.
     * Inputs: None.
     * Returns: The related chunk collection query.
     * Side effects: None.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(SavedNoticeAiDocumentChunk::class)
            ->orderBy('chunk_index');
    }

    /**
     * Purpose: Resolve the requirement candidates extracted from this AI document.
     * Inputs: None.
     * Returns: The related requirement collection query.
     * Side effects: None.
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(SavedNoticeAiRequirement::class, 'saved_notice_ai_document_id')
            ->orderBy('saved_notice_ai_document_chunk_id')
            ->orderBy('id');
    }

    /**
     * Purpose: Resolve the extraction runs created for this AI document.
     * Inputs: None.
     * Returns: The related extraction run collection query.
     * Side effects: None.
     */
    public function extractionRuns(): HasMany
    {
        return $this->hasMany(RequirementExtractionRun::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Purpose: Resolve the latest extraction run for this AI document.
     * Inputs: None.
     * Returns: The most recent extraction run relation.
     * Side effects: None.
     */
    public function latestExtractionRun(): HasOne
    {
        return $this->hasOne(RequirementExtractionRun::class)->latestOfMany();
    }
}
