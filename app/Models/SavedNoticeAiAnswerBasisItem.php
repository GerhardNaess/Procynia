<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SavedNoticeAiAnswerBasisItem extends Model
{
    public const ANSWER_BASIS_TYPE_DOCUMENT = 'document';

    public const ANSWER_BASIS_TYPE_TEXT = 'text';

    public const ANSWER_BASIS_TYPES = [
        self::ANSWER_BASIS_TYPE_DOCUMENT,
        self::ANSWER_BASIS_TYPE_TEXT,
    ];

    public const ANSWER_BASIS_TYPE_LABELS = [
        self::ANSWER_BASIS_TYPE_DOCUMENT => 'Dokument',
        self::ANSWER_BASIS_TYPE_TEXT => 'Tekst',
    ];

    protected $fillable = [
        'saved_notice_id',
        'created_by_user_id',
        'answer_basis_type',
        'title',
        'original_filename',
        'body_text',
        'stored_path',
        'mime_type',
        'file_size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'created_by_user_id' => 'integer',
            'file_size_bytes' => 'integer',
        ];
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(
            SavedNoticeAiRequirement::class,
            'saved_notice_ai_requirement_answer_basis_selections',
            'saved_notice_ai_answer_basis_item_id',
            'saved_notice_ai_requirement_id',
        )->withTimestamps();
    }

    public function getAnswerBasisTypeLabelAttribute(): string
    {
        $answerBasisType = (string) ($this->answer_basis_type ?: self::ANSWER_BASIS_TYPE_TEXT);

        return self::ANSWER_BASIS_TYPE_LABELS[$answerBasisType] ?? $answerBasisType;
    }

    public function isDocument(): bool
    {
        return $this->answer_basis_type === self::ANSWER_BASIS_TYPE_DOCUMENT;
    }

    public function isText(): bool
    {
        return $this->answer_basis_type === self::ANSWER_BASIS_TYPE_TEXT;
    }
}
