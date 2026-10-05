<?php

namespace App\Support\Objectives;

/**
 * Validation messages and field names for the Mål og KPI forms, in the user's language.
 *
 * The same arrangement as RiskValidationMessages: the app ships no validation language file, so
 * without these a missing field reads as Laravel's English default. Passed as the messages and
 * attributes arguments of Request::validate(); a controller's own field-specific messages win.
 */
final class ObjectiveValidationMessages
{
    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return [
            'required' => __('procynia.objectives.validation.rules.required'),
            'integer' => __('procynia.objectives.validation.rules.choose'),
            'in' => __('procynia.objectives.validation.rules.choose'),
            'string' => __('procynia.objectives.validation.rules.required'),
            'max' => [
                'string' => __('procynia.objectives.validation.rules.max_string'),
                'numeric' => __('procynia.objectives.validation.rules.max_numeric'),
            ],
            'min' => ['numeric' => __('procynia.objectives.validation.rules.min_numeric')],
            'numeric' => __('procynia.objectives.validation.rules.number'),
            'regex' => __('procynia.objectives.validation.rules.number'),
            'date_format' => __('procynia.objectives.validation.rules.date'),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return __('procynia.objectives.validation.attributes');
    }
}
