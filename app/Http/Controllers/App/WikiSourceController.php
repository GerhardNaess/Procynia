<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiDocument;
use App\Services\DocumentTextExtractor;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WikiSourceController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly DocumentTextExtractor $documentTextExtractor,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx', 'max:20480'],
        ]);

        $file = $validated['file'];
        $fileHash = (string) hash_file('sha256', $file->getRealPath());

        $duplicate = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('file_hash_sha256', $fileHash)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'file' => 'Dette dokumentet er allerede lastet opp for din virksomhet.',
            ]);
        }

        $storedPath = null;

        try {
            $ext = Str::lower(trim((string) $file->getClientOriginalExtension()));
            if ($ext === '') {
                $ext = 'bin';
            }
            $storedFilename = Str::ulid().'.'.$ext;
            $storedPath = Storage::disk('local')->putFileAs(
                sprintf('customers/%d/wiki-documents', $customerId),
                $file,
                $storedFilename,
            );

            abort_unless(is_string($storedPath) && $storedPath !== '', 500, 'Failed to store the wiki document.');

            $absolutePath = Storage::disk('local')->path($storedPath);
            $extractedText = trim($this->documentTextExtractor->extractText($absolutePath));

            DB::transaction(function () use ($customerId, $user, $file, $storedPath, $fileHash, $extractedText): void {
                EnterpriseWikiDocument::query()->create([
                    'customer_id' => $customerId,
                    'uploaded_by_user_id' => $user?->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $storedPath,
                    'file_hash_sha256' => $fileHash,
                    'extracted_text' => $extractedText !== '' ? $extractedText : null,
                    'document_status' => $extractedText !== ''
                        ? EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED
                        : EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
                ]);
            });
        } catch (\Throwable $e) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            Log::error('[PROCYNIA][WIKI_SOURCE] Failed to store wiki document.', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return redirect()->route('app.wiki.index')
            ->with('success', 'Dokumentet er lastet opp og klart for ingest.');
    }
}
