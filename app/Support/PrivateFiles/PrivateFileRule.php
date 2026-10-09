<?php

namespace App\Support\PrivateFiles;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validation for a private file upload: an uploaded file, no larger than the configured ceiling,
 * whose content is an allowed, intact file of the type its name says (PrivateFileType). The messages
 * are the caller's, so each module speaks its own language.
 */
final class PrivateFileRule implements ValidationRule
{
    public function __construct(
        private readonly string $typeMessage,
        private readonly string $sizeMessage,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && in_array($value->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $fail($this->sizeMessage);

            return;
        }

        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail($this->typeMessage);

            return;
        }

        if ($value->getSize() > ((int) config('private_files.max_kilobytes')) * 1024) {
            $fail($this->sizeMessage);

            return;
        }

        if (PrivateFileType::detect((string) $value->getRealPath(), $value->getClientOriginalName()) === null) {
            $fail($this->typeMessage);
        }
    }
}
