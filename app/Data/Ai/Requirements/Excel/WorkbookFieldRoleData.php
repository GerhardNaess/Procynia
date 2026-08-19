<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * What one column appears to be for.
 *
 * The role vocabulary is deliberately small and generic. It says nothing about what a column is
 * CALLED — a requirement-text column may be headed "Krav", "Beskrivelse", "Description" or nothing
 * at all — only what part it plays in how the sheet expresses a requirement. Anything the
 * vocabulary cannot name stays `other` rather than being forced into a role that does not fit.
 *
 * `requirement_text` may legitimately appear more than once: some workbooks split a requirement
 * across a short title column and a longer detail column, and both are part of the same
 * requirement.
 */
final readonly class WorkbookFieldRoleData implements JsonSerializable
{
    public const ROLE_REQUIREMENT_ID = 'requirement_id';

    public const ROLE_REQUIREMENT_TEXT = 'requirement_text';

    public const ROLE_QUALIFICATION = 'qualification';

    public const ROLE_WEIGHTING = 'weighting';

    public const ROLE_RESPONSE = 'response';

    public const ROLE_COMMENT = 'comment';

    public const ROLE_SECTION = 'section';

    public const ROLE_OTHER = 'other';

    public const ROLES = [
        self::ROLE_REQUIREMENT_ID,
        self::ROLE_REQUIREMENT_TEXT,
        self::ROLE_QUALIFICATION,
        self::ROLE_WEIGHTING,
        self::ROLE_RESPONSE,
        self::ROLE_COMMENT,
        self::ROLE_SECTION,
        self::ROLE_OTHER,
    ];

    public function __construct(
        public string $columnLetter,
        public int $columnIndex,
        public string $role,
        public ?string $headerLabel = null,
        public ?float $confidence = null,
    ) {}

    public function toArray(): array
    {
        return [
            'column_letter' => $this->columnLetter,
            'column_index' => $this->columnIndex,
            'role' => $this->role,
            'header_label' => $this->headerLabel,
            'confidence' => $this->confidence,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
