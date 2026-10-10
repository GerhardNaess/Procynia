<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ManagementReview;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\ManagementReview\ManagementReviewReportBuilder;
use App\Support\CustomerContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The review as a document (plan §11): a print view, and a PDF rendered from the same document by the
 * dompdf the application already ships. The PDF is generated on request and never stored — the
 * snapshot is the archive, so the same snapshot gives the same document. Both carry exactly what the
 * reader may see (ManagementReviewReportBuilder): the same gates as the page, on every request.
 */
class ManagementReviewReportController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ManagementReviewAccessService $access,
        private readonly ManagementReviewReportBuilder $builder,
    ) {}

    public function show(int $reviewId): Response
    {
        [$user, $review] = $this->review($reviewId);

        return Inertia::render('App/ManagementReview/Report', [
            'document' => $this->builder->document($user, $review),
            'review_id' => (int) $review->id,
            'back_url' => route('app.management-review.show', ['reviewId' => $review->id]),
            'pdf_url' => route('app.management-review.report.pdf', ['reviewId' => $review->id]),
        ]);
    }

    public function pdf(int $reviewId): StreamedResponse
    {
        [$user, $review] = $this->review($reviewId);
        $document = $this->builder->document($user, $review);

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('management-review.report', ['document' => $document])->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $date = $review->finalized_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        $name = Str::slug($review->title) ?: 'ledelsens-gjennomgaelse';
        $output = $dompdf->output();

        return response()->streamDownload(fn () => print ($output), "{$name}-{$date}.pdf", [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array{0: User, 1: ManagementReview} */
    private function review(int $reviewId): array
    {
        $user = $this->customerContext->currentUser();
        abort_unless($this->access->canOpenModule($user), 403);

        return [$user, $this->access->findVisible($user, $reviewId) ?? abort(404)];
    }
}
