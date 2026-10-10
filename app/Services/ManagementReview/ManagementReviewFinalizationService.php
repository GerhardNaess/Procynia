<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use App\Models\ManagementReviewEvent;
use App\Models\ManagementReviewSnapshotSection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ferdigstill (plan §6.4). One transaction:
 *
 *  1. The review row is locked and must still be a draft.
 *  2. Readiness is computed again on the server — never trusted from the page.
 *  3. The basis is built again with the finalizing person's access — never the page's copy, which may
 *     be minutes old or built for someone else — and frozen as one row per section plus the review's
 *     own decisions.
 *  4. The framework versions are recorded, the status set, and the event written.
 *
 * From then on the database refuses changes to what was decided and to the basis. Corrections are
 * amendments; there is no reopening.
 */
final class ManagementReviewFinalizationService
{
    public function __construct(
        private readonly ManagementReviewBasisService $basis,
        private readonly ManagementReviewReadiness $readiness,
        private readonly ManagementReviewService $reviews,
    ) {}

    public function finalize(User $actor, ManagementReview $review): ManagementReview
    {
        return DB::transaction(function () use ($actor, $review): ManagementReview {
            $locked = ManagementReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->reviews->assertDraft($locked);

            $now = CarbonImmutable::now();
            $views = $this->basis->live($actor, $locked, $now);
            $readiness = $this->readiness->evaluate($locked, $views, $locked->sections()->get()->keyBy('section_key'), $locked->participants()->count(), CarbonImmutable::parse($now->toDateString()));

            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['review' => __('procynia.management_review.validation.not_ready')]);
            }

            foreach ($this->basis->capture($actor, $locked, $now) as $row) {
                ManagementReviewSnapshotSection::query()->create($row + [
                    'customer_id' => $locked->customer_id,
                    'management_review_id' => $locked->id,
                    'schema_version' => SectionPayload::SCHEMA_VERSION,
                    'captured_at' => $now,
                ]);
            }

            $catalog = (array) config('management_review.frameworks', []);

            $locked->forceFill([
                'status' => ManagementReview::STATUS_FINALIZED,
                'finalized_at' => $now,
                'finalized_by_user_id' => $actor->id,
                'finalized_by_name' => $actor->name,
                'framework_versions' => (object) collect((array) $locked->frameworks)
                    ->mapWithKeys(fn (string $key): array => [$key => (string) ($catalog[$key]['version'] ?? '')])
                    ->all(),
                'updated_by' => $actor->id,
            ])->save();

            $this->reviews->event($locked, $actor, ManagementReviewEvent::FINALIZED);

            return $locked;
        });
    }
}
