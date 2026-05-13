<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class OperationalRunbookAttachment extends Model
{
    protected $fillable = [
        'operational_runbook_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'description',
        'sort_order',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'operational_runbook_id' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attachment): void {
            $originalPath = (string) $attachment->getOriginal('stored_path');
            $currentPath = (string) $attachment->stored_path;

            if ($originalPath !== '' && $originalPath !== $currentPath && Storage::disk('local')->exists($originalPath)) {
                Storage::disk('local')->delete($originalPath);
            }
        });

        static::deleting(function (self $attachment): void {
            $storedPath = (string) $attachment->stored_path;

            if ($storedPath !== '' && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
        });
    }

    public function runbook(): BelongsTo
    {
        return $this->belongsTo(OperationalRunbook::class, 'operational_runbook_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function getFileTypeLabelAttribute(): string
    {
        $extension = strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION));

        return match ($extension) {
            'doc', 'docx' => 'Word',
            'pdf' => 'PDF',
            'png', 'jpg', 'jpeg', 'webp' => 'Bilde',
            'xls', 'xlsx' => 'Excel',
            'txt' => 'Tekst',
            default => filled($extension) ? strtoupper($extension) : 'Fil',
        };
    }

    public function getFormattedSizeAttribute(): ?string
    {
        $bytes = (int) ($this->size_bytes ?? 0);

        if ($bytes <= 0) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return $unitIndex === 0
            ? sprintf('%d %s', $bytes, $units[$unitIndex])
            : sprintf('%.1f %s', $size, $units[$unitIndex]);
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('admin.operational-runbook-attachments.download', ['attachment' => $this]);
    }
}
