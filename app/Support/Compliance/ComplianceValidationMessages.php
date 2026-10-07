<?php

namespace App\Support\Compliance;

/**
 * Validation messages and field names for the Etterlevelse og revisjon forms, in the user's
 * language — the same arrangement as ImprovementValidationMessages: the app ships no validation
 * language file, so without these a missing field reads as Laravel's English default.
 */
final class ComplianceValidationMessages
{
    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return [
            'required' => __('procynia.compliance.validation.rules.required'),
            'integer' => __('procynia.compliance.validation.rules.choose'),
            'in' => __('procynia.compliance.validation.rules.choose'),
            'array' => __('procynia.compliance.validation.rules.choose'),
            'min' => [
                'array' => __('procynia.compliance.validation.rules.required'),
            ],
            'date_format' => __('procynia.compliance.validation.rules.date'),
            'string' => __('procynia.compliance.validation.rules.required'),
            'max' => [
                'string' => __('procynia.compliance.validation.rules.max_string'),
                'array' => __('procynia.compliance.validation.rules.choose'),
            ],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return __('procynia.compliance.validation.attributes');
    }
}
