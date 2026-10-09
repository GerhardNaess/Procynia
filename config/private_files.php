<?php

/**
 * Private customer files — the small shared core in App\Support\PrivateFiles. A module that keeps
 * files of its own (Leverandøroppfølging first) stores them here, never in the Enterprise Wiki
 * document store, which is the source layer for reusable knowledge and is read by AI.
 *
 * Files are written and read only through the Laravel Storage abstraction, never through a local
 * path, so the disk can move to the private Azure Blob container («documents») by changing `disk`
 * alone.
 */
return [

    // The private disk. «local» is storage/app/private; it is never served over HTTP.
    'disk' => 'local',

    // Upload ceiling for every area, in kilobytes (Laravel's max rule). Below the 50 MB PHP/nginx limit.
    'max_kilobytes' => 20480,

    /*
     * Malware scanning. Procynia has no scanner yet (docs/security/security-audit-2026-08.md): every
     * file is stored with scan status «not_scanned». When a scanner (e.g. Defender for Storage) is
     * connected and writes the status, set this to true and only files scanned «clean» can be
     * downloaded. A file reported «infected» is never served, whatever this says.
     */
    'require_clean_scan' => false,

    // An unreferenced file younger than this is left alone by private-files:prune-orphans, so an
    // upload whose row is still being written is never mistaken for an orphan.
    'orphan_grace_hours' => 24,

    /*
     * Safety brake. A run that finds more orphans than this deletes nothing and fails: so many at once
     * means something is wrong (an empty or wrong database, a missing reference column), not ordinary
     * leftovers. Inspect with --dry-run, then delete deliberately with --force.
     */
    'prune_max_per_run' => 100,

    /*
     * Each area is one module's files, stored under customers/{customer_id}/{area}/{key}.{ext}. A file
     * is referenced — and is never deleted — while any of these columns holds its path or its key.
     */
    'areas' => [
        'supplier-documents' => [
            'references' => [
                ['table' => 'supplier_documents', 'column' => 'file_path', 'holds' => 'path'],
                ['table' => 'supplier_requirement_evaluation_documents', 'column' => 'document_file_key', 'holds' => 'key'],
            ],
        ],
    ],

];
