<?php

namespace App\Support\Risk;

/**
 * Validation messages and field names for every Risiko form, in the user's language.
 *
 * The app ships no validation language file, so without these a missing field reads as Laravel's
 * English default ("The owner user id field is required."). Passed as the messages and attributes
 * arguments of Request::validate(); a controller's own field-specific messages still win.
 */
final class RiskValidationMessages
{
    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return [
            'required' => __('procynia.risk.validation.rules.required'),
            'required_with' => __('procynia.risk.validation.rules.required_with'),
            'integer' => __('procynia.risk.validation.rules.choose'),
            'in' => __('procynia.risk.validation.rules.choose'),
            'string' => __('procynia.risk.validation.rules.required'),
            'max' => ['string' => __('procynia.risk.validation.rules.max_string')],
            'date_format' => __('procynia.risk.validation.rules.date'),
            'after_or_equal' => __('procynia.risk.validation.rules.not_in_past'),
        ];
    }

    /**
     * Field names as the form labels them. A field that means something else in one form — the
     * owner of a tiltak is «Ansvarlig», not «Risikoeier» — is named by that form's overrides.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    public static function attributes(array $overrides = []): array
    {
        return [...__('procynia.risk.validation.attributes'), ...$overrides];
    }
}
