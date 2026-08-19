<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * An .xlsx upload that cannot be turned into requirements safely.
 *
 * Each factory carries a translation key rather than a finished sentence, so the message the user
 * reads comes from the ordinary language files and the technical detail stays in the log. The
 * distinction between the reasons matters to the user: "we could not read the structure" is
 * something they can act on by simplifying the sheet, while "the workbook is too large" is not.
 */
class XlsxRequirementImportException extends RuntimeException
{
    /** @param  list<string>  $details */
    private function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly string $filename,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function aiUnavailable(string $filename): self
    {
        return new self(
            sprintf('Excel structure discovery is unavailable, so [%s] was not imported.', $filename),
            'procynia.ai.excel_import_ai_unavailable',
            $filename,
        );
    }

    /** @param  list<string>  $sheetNames */
    public static function workbookTooLarge(string $filename, array $sheetNames): self
    {
        return new self(
            sprintf('Workbook [%s] exceeds the supported size on sheet(s): %s.', $filename, implode(', ', $sheetNames)),
            'procynia.ai.excel_import_too_large',
            $filename,
            $sheetNames,
        );
    }

    /** @param  list<string>  $errors */
    public static function structureNotUnderstood(string $filename, array $errors): self
    {
        return new self(
            sprintf('Workbook [%s] structure could not be validated: %s', $filename, implode(' | ', $errors)),
            'procynia.ai.excel_import_structure_unclear',
            $filename,
            $errors,
        );
    }

    /** @param  list<string>  $warnings */
    public static function incompleteUnits(string $filename, array $warnings): self
    {
        return new self(
            sprintf('Workbook [%s] produced an incomplete requirement set: %s', $filename, implode(' | ', $warnings)),
            'procynia.ai.excel_import_too_large',
            $filename,
            $warnings,
        );
    }

    public static function noRequirementsFound(string $filename): self
    {
        return new self(
            sprintf('Workbook [%s] produced no requirements.', $filename),
            'procynia.ai.excel_import_no_requirements',
            $filename,
        );
    }
}
