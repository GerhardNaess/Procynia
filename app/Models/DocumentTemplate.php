<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

class DocumentTemplate extends Model
{
    public const TEMPLATE_TYPE_WORD_EXPORT = 'word_export';

    public const CONTENT_PLACEHOLDER = '[[PROCYNIA_CONTENT]]';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public static function validateUploadedWordExportTemplate(UploadedFile $file): void
    {
        $originalFilename = trim((string) $file->getClientOriginalName());

        if ($originalFilename === '' || ! Str::endsWith(Str::lower($originalFilename), '.docx')) {
            throw ValidationException::withMessages([
                'file_path' => __('procynia.document_templates.messages.must_be_docx'),
            ]);
        }

        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'file_path' => __('procynia.document_templates.messages.invalid_docx'),
            ]);
        }

        static::assertDocxContainsPlaceholder($realPath);
    }

    public static function validateStoredWordExportTemplate(string $disk, string $path): void
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw ValidationException::withMessages([
                'file_path' => __('procynia.document_templates.messages.file_missing'),
            ]);
        }

        $resolvedPath = $storage->path($path);

        if (! is_string($resolvedPath) || $resolvedPath === '' || ! is_file($resolvedPath)) {
            throw ValidationException::withMessages([
                'file_path' => __('procynia.document_templates.messages.invalid_docx'),
            ]);
        }

        static::assertDocxContainsPlaceholder($resolvedPath);
    }

    public static function templateTypeLabel(?string $templateType): string
    {
        return match ($templateType) {
            self::TEMPLATE_TYPE_WORD_EXPORT => __('procynia.document_templates.template_types.word_export'),
            default => $templateType !== null && $templateType !== ''
                ? $templateType
                : __('procynia.common.not_available'),
        };
    }

    public static function activeWordExportForCustomer(int $customerId): ?self
    {
        return static::query()
            ->where('customer_id', $customerId)
            ->where('template_type', self::TEMPLATE_TYPE_WORD_EXPORT)
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderByDesc('updated_at')
            ->first();
    }

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            if (! Schema::hasTable('document_templates')) {
                return;
            }

            $template->customer_id = $template->customer_id !== null ? (int) $template->customer_id : null;
            $template->name = Str::squish((string) $template->name);
            $template->description = filled($template->description) ? Str::squish((string) $template->description) : null;
            $template->template_type = self::TEMPLATE_TYPE_WORD_EXPORT;
            $template->file_disk = trim((string) ($template->file_disk ?: 'local')) ?: 'local';
            $template->file_path = trim((string) $template->file_path);
            $template->original_filename = trim((string) $template->original_filename);
            $template->mime_type = filled($template->mime_type) ? trim((string) $template->mime_type) : null;
            $template->file_size = $template->file_size !== null ? (int) $template->file_size : null;
            $template->is_active = (bool) ($template->is_active ?? true);
            $template->is_default = (bool) ($template->is_default ?? false);

            if (blank($template->customer_id)) {
                throw ValidationException::withMessages([
                    'customer_id' => __('procynia.document_templates.messages.customer_required'),
                ]);
            }

            if ($template->name === '') {
                throw ValidationException::withMessages([
                    'name' => __('procynia.common.name'),
                ]);
            }

            if ($template->original_filename === '' && filled($template->file_path)) {
                $basename = basename($template->file_path);
                $template->original_filename = Str::contains($basename, '__')
                    ? (string) Str::after($basename, '__')
                    : $basename;
            }

            if ($template->original_filename === '' || ! Str::endsWith(Str::lower($template->original_filename), '.docx')) {
                throw ValidationException::withMessages([
                    'file_path' => __('procynia.document_templates.messages.must_be_docx'),
                ]);
            }

            if ($template->file_path === '') {
                throw ValidationException::withMessages([
                    'file_path' => __('procynia.document_templates.messages.file_missing'),
                ]);
            }

            static::validateStoredWordExportTemplate($template->file_disk, $template->file_path);

            try {
                $storage = Storage::disk($template->file_disk);
                $template->mime_type = $storage->mimeType($template->file_path)
                    ?: $template->mime_type
                    ?: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

                $template->file_size = $storage->size($template->file_path)
                    ?: $template->file_size
                    ?: (is_string($storage->path($template->file_path)) && is_file($storage->path($template->file_path))
                        ? filesize($storage->path($template->file_path))
                        : $template->file_size);
            } catch (Throwable) {
                // Keep the record saveable if the filesystem driver cannot provide metadata.
            }

            if ($template->is_default) {
                $template->is_active = true;
            }
        });

        static::saved(function (self $template): void {
            if (! Schema::hasTable('document_templates') || ! $template->is_default) {
                return;
            }

            static::query()
                ->where('customer_id', $template->customer_id)
                ->where('template_type', $template->template_type)
                ->whereKeyNot($template->getKey())
                ->update([
                    'is_default' => false,
                    'updated_at' => now(),
                ]);
        });

        static::deleting(function (self $template): void {
            if (blank($template->file_path)) {
                return;
            }

            try {
                $storage = Storage::disk($template->file_disk ?: 'local');

                if ($storage->exists($template->file_path)) {
                    $storage->delete($template->file_path);
                }
            } catch (Throwable) {
                // Ignore cleanup failures; the model delete should still proceed.
            }
        });
    }

    private static function assertDocxContainsPlaceholder(string $path): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw ValidationException::withMessages([
                'file_path' => __('procynia.document_templates.messages.invalid_docx'),
            ]);
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($documentXml) || ! str_contains($documentXml, self::CONTENT_PLACEHOLDER)) {
            throw ValidationException::withMessages([
                'file_path' => __('procynia.document_templates.messages.placeholder_missing'),
            ]);
        }
    }
}
