<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Test-only cleanup helper for the Wiki source-upload readability E2E spec
 * (tests/e2e/wiki-source-upload-readability.spec.js). No seed step is needed — the upload form is
 * always present on the Kildedokumenter tab — but the spec performs one real upload against the
 * shared dev-data customer, so this removes that document (and its stored file) afterward.
 */
class WikiSourceUploadReadabilityE2EFixture
{
    private const UPLOAD_FILENAME = 'e2e-source-upload-readability.pdf';

    public static function cleanup(int $customerId): void
    {
        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('original_filename', self::UPLOAD_FILENAME)
            ->get();

        foreach ($documents as $document) {
            if ($document->file_path !== null && $document->file_path !== '') {
                Storage::disk('local')->delete($document->file_path);
            }

            $document->delete();
        }
    }
}
