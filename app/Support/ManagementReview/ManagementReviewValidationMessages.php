<?php

namespace App\Support\ManagementReview;

/**
 * Validation messages and field names for the Ledelsens gjennomgåelse forms, in the user's language
 * — the same arrangement as ObjectiveValidationMessages: the app ships no validation language file.
 */
final class ManagementReviewValidationMessages
{
    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return [
            'required' => __('procynia.management_review.validation.rules.required'),
            'integer' => __('procynia.management_review.validation.rules.choose'),
            'in' => __('procynia.management_review.validation.rules.choose'),
            'array' => __('procynia.management_review.validation.rules.choose'),
            'boolean' => __('procynia.management_review.validation.rules.choose'),
            'string' => __('procynia.management_review.validation.rules.required'),
            'max' => ['string' => __('procynia.management_review.validation.rules.max_string')],
            'date_format' => __('procynia.management_review.validation.rules.date'),
            'after_or_equal' => __('procynia.management_review.validation.rules.after_start'),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return __('procynia.management_review.validation.attributes');
    }
}
