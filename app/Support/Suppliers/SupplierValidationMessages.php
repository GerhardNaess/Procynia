<?php

namespace App\Support\Suppliers;

/**
 * Validation messages and field names for the Leverandøroppfølging forms, in the user's language —
 * the same arrangement as RiskValidationMessages: the app ships no validation language file, so
 * without these a missing field reads as Laravel's English default.
 */
final class SupplierValidationMessages
{
    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return [
            'required' => __('procynia.supplier_management.validation.rules.required'),
            'integer' => __('procynia.supplier_management.validation.rules.choose'),
            'in' => __('procynia.supplier_management.validation.rules.choose'),
            'array' => __('procynia.supplier_management.validation.rules.choose'),
            'string' => __('procynia.supplier_management.validation.rules.required'),
            'email' => __('procynia.supplier_management.validation.rules.email'),
            // A file the server refused before it reached the app (most often larger than PHP allows).
            'uploaded' => __('procynia.supplier_management.validation.file_upload_failed'),
            'max' => [
                'string' => __('procynia.supplier_management.validation.rules.max_string'),
            ],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return __('procynia.supplier_management.validation.attributes');
    }
}
