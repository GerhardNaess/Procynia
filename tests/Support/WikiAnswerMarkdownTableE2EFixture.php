<?php

namespace Tests\Support;

use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Test-only fixture for the Wiki-answer Markdown rendering E2E spec
 * (tests/e2e/ai-answer-markdown-table.spec.js). Not autoloaded in production, invoked via
 * `php artisan tinker --execute=...` from the spec, mirroring WikiBestPracticeE2EFixture.
 *
 * The spec used to open a real generated answer and assert the tables it happened to contain.
 * That coupled a rendering test to whatever the answer engine last produced: once the Wiki gained
 * approved pages, the same requirement was answered from real Wiki content in flowing prose, the
 * invented tables disappeared, and a green rendering path started failing a rendering test.
 *
 * This seeds its own case, requirement and stored answer instead, so the spec owns the exact
 * Markdown it asserts on and no regeneration of real customer content can touch it. No Wiki page,
 * document or figure is involved — the panel renders `answer_text`, and that is all this proves.
 */
class WikiAnswerMarkdownTableE2EFixture
{
    public const EXTERNAL_ID = 'E2E-ANSWER-MARKDOWN-TABLE';

    public const REQUIREMENT_TEXT = 'E2E: beskriv samhandlingsmodellen med tabell.';

    /**
     * Deliberately mixed: prose, a heading, a list and one GFM pipe table — the constructs a real
     * answer uses. The table is the point; the rest guards against a renderer that only handles
     * tables.
     */
    public const ANSWER_MARKDOWN = <<<'MARKDOWN'
Leverandøren etablerer en samhandlingsmodell med tydelig ansvarsdeling.

## Hovedelementer

| Element | Beskrivelse |
| --- | --- |
| A | Første rad |
| B | Andre rad |

Modellen tilpasses den enkelte leveransen:

- Roller avklares ved oppstart.
- Møtestrukturen justeres underveis.
MARKDOWN;

    /** Returns the saved-notice id the spec navigates to. */
    public static function seed(int $customerId): int
    {
        self::cleanup($customerId);

        $owner = User::query()
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->first();

        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customerId,
            'saved_by_user_id' => $owner?->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => self::EXTERNAL_ID,
            'title' => 'E2E — Markdown-rendering i Wiki-svar',
            'buyer_name' => 'Procynia E2E',
            'external_url' => 'https://doffin.no/notices/'.Str::lower(self::EXTERNAL_ID),
            'summary' => 'Kontrollert testsak for rendering av Wiki-svar.',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-31 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'requirement_identifier' => 'E2E-1',
            'requirement_text' => self::REQUIREMENT_TEXT,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'published_at' => now(),
        ]);

        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => self::ANSWER_MARKDOWN,
            'sources' => [],
            'model' => 'e2e-fixture',
            'engine_version' => 'e2e-fixture',
            'generated_at' => now(),
        ]);

        return (int) $savedNotice->id;
    }

    public static function cleanup(int $customerId): void
    {
        // Cascades take the requirement and its Wiki answer with the case.
        SavedNotice::query()
            ->where('customer_id', $customerId)
            ->where('external_id', self::EXTERNAL_ID)
            ->delete();
    }
}
