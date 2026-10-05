<?php

namespace App\Support\Improvements;

/**
 * Validation messages and field names for the Avvik og forbedringer forms, in the user's language.
 *
 * The same arrangement as RiskValidationMessages and ObjectiveValidationMessages: the app ships no
 * validation language file, so without these a missing field reads as Laravel's English default.
 */
final class ImprovementValidationMessages
{
    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return [
            'required' => __('procynia.improvements.validation.rules.required'),
            'integer' => __('procynia.improvements.validation.rules.choose'),
            'in' => __('procynia.improvements.validation.rules.choose'),
            'string' => __('procynia.improvements.validation.rules.required'),
            'boolean' => __('procynia.improvements.validation.rules.choose'),
            'array' => __('procynia.improvements.validation.rules.choose'),
            'max' => [
                'string' => __('procynia.improvements.validation.rules.max_string'),
                'array' => __('procynia.improvements.validation.rules.choose'),
            ],
            'date_format' => __('procynia.improvements.validation.rules.date'),
            'occurred_at.before_or_equal' => __('procynia.improvements.validation.occurred_in_future'),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return __('procynia.improvements.validation.attributes');
    }
}
