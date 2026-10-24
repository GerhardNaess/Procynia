<?php

namespace App\Rules;

use App\Models\Scopes\HumanUsersScope;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An email address is unique across all users regardless of letter case. Rule::unique compares the
 * column as stored, so "Ola@Firma.no" would pass beside "ola@firma.no" and the insert would then hit
 * the lower(email) unique index as an HTTP 500 instead of a validation message.
 */
final class UniqueUserEmail implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreUserId = null,
        private readonly ?string $message = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        // Uniqueness is about the table, not about people: an integration identity's address is
        // taken too, or the database's own unique index would answer instead of this rule.
        $taken = User::query()
            ->withoutGlobalScope(HumanUsersScope::class)
            ->whereRaw('lower(email) = ?', [User::normalizeEmail($value)])
            ->when($this->ignoreUserId !== null, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail($this->message ?? __('validation.unique', ['attribute' => $attribute]));
        }
    }
}
