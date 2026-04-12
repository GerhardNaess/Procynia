<?php

namespace App\Data\Ai\Requirements;

use App\Models\SavedNoticeAiRequirement;
use JsonSerializable;

final readonly class RequirementEditData implements JsonSerializable
{
    public function __construct(
        public ?string $requirementIdentifier,
        public string $requirementText,
        public string $requirementType,
        public ?string $reason = null,
    ) {
    }

    public static function fromArray(array $input): self
    {
        return new self(
            requirementIdentifier: array_key_exists('requirement_identifier', $input)
                ? self::normalizeNullableString($input['requirement_identifier'])
                : null,
            requirementText: self::normalizeRequiredString((string) ($input['requirement_text'] ?? '')),
            requirementType: (string) ($input['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED),
            reason: array_key_exists('reason', $input)
                ? self::normalizeNullableString($input['reason'])
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'requirement_identifier' => $this->requirementIdentifier,
            'requirement_text' => $this->requirementText,
            'requirement_type' => $this->requirementType,
            'reason' => $this->reason,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        $collapsed = preg_replace('/\s+/u', ' ', $normalized);

        if ($normalized === '') {
            return null;
        }

        return is_string($collapsed) && $collapsed !== ''
            ? $collapsed
            : $normalized;
    }

    private static function normalizeRequiredString(string $value): string
    {
        $normalized = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $normalized);

        return is_string($collapsed) && $collapsed !== ''
            ? $collapsed
            : $normalized;
    }
}
