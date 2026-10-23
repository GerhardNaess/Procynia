<?php

namespace Tests\Support;

use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test-only fixture for tests/e2e/a11y-tenders-ai.spec.js (WCAG Fase 4). Not autoloaded in production;
 * invoked through `php artisan tinker --execute=...` from the spec, like WikiAnswerMarkdownTableE2EFixture.
 *
 * It seeds the spec's own two cases so nothing it asserts depends on, or changes, real customer data:
 *   - a case in Go / No-Go, for the assessment and the case page's forms;
 *   - an AI case with one approved and one draft requirement, a stored Wiki answer and one case
 *     document, for the AI workspace.
 *
 * No AI call is ever made: the document is a database row with a status the spec sets directly
 * (setDocumentStatus), and the spec intercepts the one request that would generate an answer.
 * Deleting the two cases removes everything else through the database's cascades.
 */
class A11yTenderAiE2EFixture
{
    public const GO_NO_GO_EXTERNAL_ID = 'E2E-A11Y-GONOGO';

    public const AI_EXTERNAL_ID = 'E2E-A11Y-AI';

    public const APPROVED_REQUIREMENT_TEXT = 'E2E a11y: beskriv leveransemodellen.';

    public const DRAFT_REQUIREMENT_TEXT = 'E2E a11y: oppgi responstid for support.';

    public const DOCUMENT_FILENAME = 'e2e-a11y-konkurransegrunnlag.pdf';

    /** Returns "<go/no-go case id>,<AI case id>" for the spec. */
    public static function seed(int $customerId): string
    {
        self::cleanup($customerId);

        return DB::transaction(fn (): string => self::seedCases($customerId));
    }

    private static function seedCases(int $customerId): string
    {
        $owner = User::query()
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->first();

        $goNoGo = self::createCase($customerId, $owner, self::GO_NO_GO_EXTERNAL_ID, 'E2E a11y — Go/No-go-vurdering', SavedNotice::BID_STATUS_GO_NO_GO);
        $ai = self::createCase($customerId, $owner, self::AI_EXTERNAL_ID, 'E2E a11y — AI-arbeidsflate', SavedNotice::BID_STATUS_QUALIFYING);

        SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $ai->id,
            'uploaded_by_user_id' => $owner?->id,
            'original_filename' => self::DOCUMENT_FILENAME,
            'stored_path' => 'e2e/'.Str::lower(self::AI_EXTERNAL_ID).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
            'processing_finished_at' => now(),
        ]);

        $approved = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $ai->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'requirement_identifier' => 'E2E-A11Y-1',
            'requirement_text' => self::APPROVED_REQUIREMENT_TEXT,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'published_at' => now(),
        ]);

        SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $ai->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'requirement_identifier' => 'E2E-A11Y-2',
            'requirement_text' => self::DRAFT_REQUIREMENT_TEXT,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'published_at' => now(),
        ]);

        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $approved->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => "Leverandøren leverer etter en fast modell.\n\n- Oppstart\n- Drift",
            'sources' => [],
            'model' => 'e2e-fixture',
            'engine_version' => 'e2e-fixture',
            'generated_at' => now(),
        ]);

        return $goNoGo->id.','.$ai->id;
    }

    /** The AI case's document moves to `status`, as the extraction job would move it. */
    public static function setDocumentStatus(int $customerId, string $status): void
    {
        SavedNoticeAiDocument::query()
            ->whereHas('savedNotice', fn ($query) => $query
                ->where('customer_id', $customerId)
                ->where('external_id', self::AI_EXTERNAL_ID))
            ->update(['processing_status' => $status]);
    }

    public static function cleanup(int $customerId): void
    {
        // The AI case holds an approved requirement, which A42 keeps from an ordinary delete.
        TenderTestDataPurge::delete(SavedNotice::query()
            ->where('customer_id', $customerId)
            ->whereIn('external_id', [self::GO_NO_GO_EXTERNAL_ID, self::AI_EXTERNAL_ID]));
    }

    private static function createCase(int $customerId, ?User $owner, string $externalId, string $title, string $bidStatus): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customerId,
            'saved_by_user_id' => $owner?->id,
            'bid_status' => $bidStatus,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => $externalId,
            'title' => $title,
            'buyer_name' => 'Procynia E2E',
            'external_url' => 'https://doffin.no/notices/'.Str::lower($externalId),
            'summary' => 'Kontrollert testsak for universell utforming (Fase 4).',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-31 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);
    }
}
