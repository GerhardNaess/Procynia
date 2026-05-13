<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationalRunbookAttachment;
use App\Support\CustomerContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OperationalRunbookAttachmentDownloadController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function download(OperationalRunbookAttachment $attachment): BinaryFileResponse
    {
        abort_unless($this->customerContext->isInternalAdmin(), Response::HTTP_FORBIDDEN);

        $storedPath = (string) $attachment->stored_path;

        abort_unless($storedPath !== '' && Storage::disk('local')->exists($storedPath), Response::HTTP_NOT_FOUND);

        return response()->download(
            Storage::disk('local')->path($storedPath),
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            ],
        );
    }
}
