<?php

namespace App\Services\Suppliers\Import;

use RuntimeException;

/**
 * The register changed between the preview and the confirmation — a supplier was registered, ended
 * or changed — so what the person confirmed is no longer what would happen. Nothing is written.
 */
final class StaleSupplierImportException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The supplier register changed since the import preview.');
    }
}
