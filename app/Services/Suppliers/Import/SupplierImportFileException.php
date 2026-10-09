<?php

namespace App\Services\Suppliers\Import;

use RuntimeException;

/**
 * The file cannot be imported at all. `reason` names a message in
 * procynia.supplier_management.import.file_errors, written for the person who uploaded the file —
 * never the underlying library's error, which is logged instead.
 */
final class SupplierImportFileException extends RuntimeException
{
    /** @param  array<string, string|int>  $parameters */
    public function __construct(
        public readonly string $reason,
        public readonly array $parameters = [],
    ) {
        parent::__construct("Supplier import file refused: {$reason}");
    }

    public function userMessage(): string
    {
        return (string) __("procynia.supplier_management.import.file_errors.{$this->reason}", $this->parameters);
    }
}
