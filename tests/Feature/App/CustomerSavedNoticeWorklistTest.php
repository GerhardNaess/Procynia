<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticePhaseComment;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerSavedNoticeWorklistTest extends TestCase
{
    private bool $createdSavedNoticesTable = false;

    private bool $createdBidSubmissionsTable = false;

    private bool $createdSavedNoticeUserAccessTable = false;

    private bool $createdSavedNoticePhaseCommentsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->ensureSavedNoticesTable();
        $this->ensureBidSubmissionsTable();
        $this->ensureSavedNoticeUserAccessTable();
        $this->ensureSavedNoticePhaseCommentsTable();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($this->createdSavedNoticesTable) {
            Schema::dropIfExists('saved_notices');
        }

        if ($this->createdBidSubmissionsTable) {
            Schema::dropIfExists('bid_submissions');
        }

        if ($this->createdSavedNoticeUserAccessTable) {
            Schema::dropIfExists('saved_notice_user_access');
        }

        if ($this->createdSavedNoticePhaseCommentsTable) {
            Schema::dropIfExists('saved_notice_phase_comments');
            DB::statement('DROP SEQUENCE IF EXISTS saved_notice_phase_comments_id_seq CASCADE');
        }

        parent::tearDown();
    }

    public function test_saved_and_history_modes_return_customer_scoped_counts_and_lists(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $this->createSavedNotice($primary['customer']->id, '2026-1001', 'Primar lagret');
        $this->createSavedNotice($primary['customer']->id, '2026-1002', 'Primar historikk', archived: true);
        $this->createSavedNotice($secondary['customer']->id, '2026-1003', 'Skjult lagret');
        $this->createSavedNotice($secondary['customer']->id, '2026-1004', 'Skjult historikk', archived: true);

        $savedPage = $this->inertiaPage(
            $this->actingAs($primary['admin'])
                ->get('/app/notices?mode=saved'),
        );

        $this->assertSame('saved', $savedPage['props']['mode']);
        $this->assertSame(1, $savedPage['props']['worklist']['saved_count']);
        $this->assertSame(1, $savedPage['props']['worklist']['history_count']);
        $this->assertSame(1, $savedPage['props']['notices']['meta']['total']);
        $this->assertSame('Primar lagret', $savedPage['props']['notices']['data'][0]['title']);
        $this->assertArrayNotHasKey('pipeline', $savedPage['props']);

        $historyPage = $this->inertiaPage(
            $this->actingAs($primary['admin'])
                ->get('/app/notices?mode=history'),
        );

        $this->assertSame('history', $historyPage['props']['mode']);
        $this->assertSame(1, $historyPage['props']['worklist']['saved_count']);
        $this->assertSame(1, $historyPage['props']['worklist']['history_count']);
        $this->assertSame(1, $historyPage['props']['notices']['meta']['total']);
        $this->assertSame('Primar historikk', $historyPage['props']['notices']['data'][0]['title']);
        $this->assertArrayNotHasKey('pipeline', $historyPage['props']);
    }

    public function test_saved_mode_can_filter_worklist_by_bid_status(): void
    {
        $context = $this->customerAdminContext();

        $this->createSavedNotice($context['customer']->id, '2026-filter-1', 'Registrert sak', bidStatus: SavedNotice::BID_STATUS_DISCOVERED);
        $this->createSavedNotice($context['customer']->id, '2026-filter-2', 'Go No-Go sak', bidStatus: SavedNotice::BID_STATUS_GO_NO_GO);
        $this->createSavedNotice($context['customer']->id, '2026-filter-3', 'Sendt sak', bidStatus: SavedNotice::BID_STATUS_SUBMITTED);

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get('/app/notices?mode=saved&bid_status=go_no_go'),
        );

        $this->assertSame('saved', $page['props']['mode']);
        $this->assertSame('go_no_go', $page['props']['filters']['bid_status']);
        $this->assertSame(1, $page['props']['notices']['meta']['total']);
        $this->assertSame('Go No-Go sak', $page['props']['notices']['data'][0]['title']);
        $this->assertSame(SavedNotice::BID_STATUS_GO_NO_GO, $page['props']['notices']['data'][0]['bid_status']);
    }

    public function test_dashboard_payload_includes_pipeline_summary_for_customer_scope(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $this->createSavedNotice($primary['customer']->id, '2026-pipeline-1', 'Registrert', bidStatus: SavedNotice::BID_STATUS_DISCOVERED);
        $this->createSavedNotice($primary['customer']->id, '2026-pipeline-2', 'Kvalifiseres', bidStatus: SavedNotice::BID_STATUS_QUALIFYING);
        $this->createSavedNotice($primary['customer']->id, '2026-pipeline-3', 'Sendt', bidStatus: SavedNotice::BID_STATUS_SUBMITTED);
        $this->createSavedNotice($primary['customer']->id, '2026-pipeline-4', 'Forhandling', bidStatus: SavedNotice::BID_STATUS_NEGOTIATION);
        $this->createSavedNotice($primary['customer']->id, '2026-pipeline-5', 'Vunnet', bidStatus: SavedNotice::BID_STATUS_WON);
        $this->createSavedNotice($primary['customer']->id, '2026-pipeline-6', 'No-Go', bidStatus: SavedNotice::BID_STATUS_NO_GO);
        $this->createSavedNotice($primary['customer']->id, '2026-pipeline-7', 'Arkiv', archived: true, bidStatus: SavedNotice::BID_STATUS_ARCHIVED);

        $this->createSavedNotice($secondary['customer']->id, '2026-pipeline-foreign-1', 'Skjult tapt', bidStatus: SavedNotice::BID_STATUS_LOST);
        $this->createSavedNotice($secondary['customer']->id, '2026-pipeline-foreign-2', 'Skjult trukket', bidStatus: SavedNotice::BID_STATUS_WITHDRAWN);

        $page = $this->inertiaPage(
            $this->actingAs($primary['admin'])->get('/app/dashboard'),
        );

        $pipeline = $page['props']['pipeline'];
        $stageCounts = collect($pipeline['stages'])->mapWithKeys(fn (array $stage): array => [$stage['key'] => $stage['count']])->all();
        $outcomeCounts = collect($pipeline['outcomes'])->mapWithKeys(fn (array $stage): array => [$stage['key'] => $stage['count']])->all();

        $this->assertSame(7, $pipeline['total_count']);
        $this->assertSame(4, $pipeline['active_total_count']);
        $this->assertSame(3, $pipeline['outcome_total_count']);
        $this->assertSame(
            ['discovered', 'qualifying', 'go_no_go', 'in_progress', 'submitted', 'negotiation'],
            array_column($pipeline['stages'], 'key'),
        );
        $this->assertSame(
            ['won', 'lost', 'no_go', 'withdrawn', 'archived'],
            array_column($pipeline['outcomes'], 'key'),
        );
        $this->assertSame(1, $stageCounts[SavedNotice::BID_STATUS_DISCOVERED]);
        $this->assertSame(1, $stageCounts[SavedNotice::BID_STATUS_QUALIFYING]);
        $this->assertSame(0, $stageCounts[SavedNotice::BID_STATUS_GO_NO_GO]);
        $this->assertSame(0, $stageCounts[SavedNotice::BID_STATUS_IN_PROGRESS]);
        $this->assertSame(1, $stageCounts[SavedNotice::BID_STATUS_SUBMITTED]);
        $this->assertSame(1, $stageCounts[SavedNotice::BID_STATUS_NEGOTIATION]);
        $this->assertSame(1, $outcomeCounts[SavedNotice::BID_STATUS_WON]);
        $this->assertSame(0, $outcomeCounts[SavedNotice::BID_STATUS_LOST]);
        $this->assertSame(1, $outcomeCounts[SavedNotice::BID_STATUS_NO_GO]);
        $this->assertSame(0, $outcomeCounts[SavedNotice::BID_STATUS_WITHDRAWN]);
        $this->assertSame(1, $outcomeCounts[SavedNotice::BID_STATUS_ARCHIVED]);
        $this->assertSame(1, $pipeline['focus_counts']['submitted']);
        $this->assertSame(1, $pipeline['focus_counts']['negotiation']);
        $this->assertSame(1, $pipeline['focus_counts']['won']);
    }

    public function test_customer_can_save_one_time_history_without_follow_up(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1002-h',
            'Historikkpost',
            archived: true,
            status: null,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->patch("/app/notices/saved/{$savedNotice->id}/history-metadata", [
                'selected_supplier_name' => 'Procynia Leverandor AS',
                'contract_value_mnok' => 12.5,
                'procurement_type' => SavedNotice::PROCUREMENT_TYPE_ONE_TIME,
                'follow_up_mode' => SavedNotice::FOLLOW_UP_MODE_NONE,
            ]);

        $response->assertRedirect('/app/notices?mode=history');

        $savedNotice->refresh();

        $this->assertSame('Procynia Leverandor AS', $savedNotice->selected_supplier_name);
        $this->assertSame('12.50', $savedNotice->contract_value_mnok);
        $this->assertSame(SavedNotice::PROCUREMENT_TYPE_ONE_TIME, $savedNotice->procurement_type);
        $this->assertSame(SavedNotice::FOLLOW_UP_MODE_NONE, $savedNotice->follow_up_mode);
        $this->assertNull($savedNotice->follow_up_offset_months);
        $this->assertNull($savedNotice->contract_period_months);
        $this->assertNull($savedNotice->next_process_date_at);
    }

    public function test_customer_can_save_one_time_history_with_manual_offset_follow_up_from_today(): void
    {
        $context = $this->customerAdminContext();
        $expectedNextProcessDate = now()->addMonthsNoOverflow(15);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1002-h-manual',
            'Historikkpost med oppfolging',
            archived: true,
            status: null,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->patch("/app/notices/saved/{$savedNotice->id}/history-metadata", [
                'procurement_type' => SavedNotice::PROCUREMENT_TYPE_ONE_TIME,
                'follow_up_mode' => SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET,
                'follow_up_offset_months' => 15,
            ]);

        $response->assertRedirect('/app/notices?mode=history');

        $savedNotice->refresh();

        $this->assertSame(SavedNotice::PROCUREMENT_TYPE_ONE_TIME, $savedNotice->procurement_type);
        $this->assertSame(SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET, $savedNotice->follow_up_mode);
        $this->assertSame(15, $savedNotice->follow_up_offset_months);
        $this->assertNull($savedNotice->contract_period_months);
        $this->assertSame($expectedNextProcessDate->toDateString(), $savedNotice->next_process_date_at?->toDateString());
    }

    public function test_customer_can_save_recurring_history_without_follow_up(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1002-h-recurring',
            'Historikkpost med avtaleperiode',
            archived: true,
            status: null,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->patch("/app/notices/saved/{$savedNotice->id}/history-metadata", [
                'procurement_type' => SavedNotice::PROCUREMENT_TYPE_RECURRING,
                'follow_up_mode' => SavedNotice::FOLLOW_UP_MODE_NONE,
                'contract_period_months' => 48,
            ]);

        $response->assertRedirect('/app/notices?mode=history');

        $savedNotice->refresh();

        $this->assertSame(SavedNotice::PROCUREMENT_TYPE_RECURRING, $savedNotice->procurement_type);
        $this->assertSame(SavedNotice::FOLLOW_UP_MODE_NONE, $savedNotice->follow_up_mode);
        $this->assertNull($savedNotice->follow_up_offset_months);
        $this->assertSame(48, $savedNotice->contract_period_months);
        $this->assertNull($savedNotice->next_process_date_at);
    }

    public function test_customer_can_save_recurring_history_with_manual_offset_follow_up_from_today(): void
    {
        $context = $this->customerAdminContext();
        $expectedNextProcessDate = now()->addMonthsNoOverflow(24);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1002-h-recurring-manual',
            'Historikkpost med oppfolging og kontraktsinfo',
            archived: true,
            status: null,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->patch("/app/notices/saved/{$savedNotice->id}/history-metadata", [
                'procurement_type' => SavedNotice::PROCUREMENT_TYPE_RECURRING,
                'follow_up_mode' => SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET,
                'follow_up_offset_months' => 24,
                'contract_period_months' => 48,
            ]);

        $response->assertRedirect('/app/notices?mode=history');

        $savedNotice->refresh();

        $this->assertSame(SavedNotice::PROCUREMENT_TYPE_RECURRING, $savedNotice->procurement_type);
        $this->assertSame(SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET, $savedNotice->follow_up_mode);
        $this->assertSame(24, $savedNotice->follow_up_offset_months);
        $this->assertSame(48, $savedNotice->contract_period_months);
        $this->assertSame($expectedNextProcessDate->toDateString(), $savedNotice->next_process_date_at?->toDateString());
    }

    public function test_history_metadata_update_is_rejected_for_active_or_foreign_notice(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $activeNotice = $this->createSavedNotice($primary['customer']->id, '2026-1002-a', 'Aktiv notice', status: null);
        $foreignArchivedNotice = $this->createSavedNotice($secondary['customer']->id, '2026-1002-f', 'Fremmed historikk', archived: true, status: null);

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch("/app/notices/saved/{$activeNotice->id}/history-metadata", [
                'selected_supplier_name' => 'Skal ikke lagres',
            ])
            ->assertNotFound();

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch("/app/notices/saved/{$foreignArchivedNotice->id}/history-metadata", [
                'selected_supplier_name' => 'Skal ikke lagres',
            ])
            ->assertNotFound();

        $this->assertNull($activeNotice->fresh()->selected_supplier_name);
        $this->assertNull($foreignArchivedNotice->fresh()->selected_supplier_name);
    }

    public function test_history_payload_includes_structured_history_metadata_fields(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1002-p',
            'Historikk med metadata',
            archived: true,
            status: null,
            selectedSupplierName: 'Procynia Leverandor AS',
            contractValueMnok: '9.90',
            procurementType: SavedNotice::PROCUREMENT_TYPE_RECURRING,
            followUpMode: SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET,
            followUpOffsetMonths: 24,
            contractPeriodMonths: 36,
            nextProcessDateAt: now()->addMonths(24)->startOfDay()->toDateTimeString(),
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice, 'history');

        $this->assertSame('Procynia Leverandor AS', $payload['selected_supplier_name']);
        $this->assertSame(9.9, $payload['contract_value_mnok']);
        $this->assertSame(SavedNotice::PROCUREMENT_TYPE_RECURRING, $payload['procurement_type']);
        $this->assertSame(SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET, $payload['follow_up_mode']);
        $this->assertSame(24, $payload['follow_up_offset_months']);
        $this->assertSame(36, $payload['contract_period_months']);
        $this->assertSame(substr((string) $savedNotice->next_process_date_at?->toIso8601String(), 0, 10), substr((string) $payload['next_process_date_at'], 0, 10));
    }

    public function test_history_payload_preserves_legacy_contract_period_text_for_history_view(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1002-legacy',
            'Historikk med legacy-periode',
            archived: true,
            status: null,
            contractPeriodText: '3 + 1 år',
            nextProcessDateAt: now()->addMonths(8)->startOfDay()->toDateTimeString(),
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice, 'history');

        $this->assertSame('3 + 1 år', $payload['contract_period_text']);
        $this->assertNull($payload['procurement_type']);
        $this->assertNull($payload['follow_up_mode']);
        $this->assertNotNull($payload['next_process_date_at']);
    }

    public function test_history_metadata_update_rejects_legacy_contract_end_mode_for_new_changes(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, '2026-1002-invalid-contract-end', 'Legacy mode kan ikke lagres pa nytt', archived: true, status: null);

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->patch("/app/notices/saved/{$savedNotice->id}/history-metadata", [
                'procurement_type' => SavedNotice::PROCUREMENT_TYPE_RECURRING,
                'follow_up_mode' => SavedNotice::FOLLOW_UP_MODE_CONTRACT_END,
                'contract_period_months' => 48,
            ])
            ->assertSessionHasErrors(['follow_up_mode']);

        $savedNotice->refresh();

        $this->assertNull($savedNotice->follow_up_mode);
        $this->assertNull($savedNotice->contract_period_months);
        $this->assertNull($savedNotice->next_process_date_at);
    }

    public function test_history_metadata_update_validates_structured_numeric_fields(): void
    {
        $context = $this->customerAdminContext();
        $manualOffsetNotice = $this->createSavedNotice($context['customer']->id, '2026-1002-invalid-manual', 'Ugyldig historikk manual', archived: true, status: null);
        $contractInfoNotice = $this->createSavedNotice($context['customer']->id, '2026-1002-invalid-contract-info', 'Ugyldig kontraktsinfo', archived: true, status: null);

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->patch("/app/notices/saved/{$manualOffsetNotice->id}/history-metadata", [
                'contract_value_mnok' => 'tekst',
                'procurement_type' => SavedNotice::PROCUREMENT_TYPE_ONE_TIME,
                'follow_up_mode' => SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET,
                'follow_up_offset_months' => 'tekst',
            ])
            ->assertSessionHasErrors([
                'contract_value_mnok',
                'follow_up_offset_months',
            ]);

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->patch("/app/notices/saved/{$contractInfoNotice->id}/history-metadata", [
                'procurement_type' => SavedNotice::PROCUREMENT_TYPE_RECURRING,
                'follow_up_mode' => SavedNotice::FOLLOW_UP_MODE_NONE,
                'contract_period_months' => 'tekst',
            ])
            ->assertSessionHasErrors([
                'contract_period_months',
            ]);

        $manualOffsetNotice->refresh();
        $contractInfoNotice->refresh();

        $this->assertNull($manualOffsetNotice->contract_value_mnok);
        $this->assertNull($manualOffsetNotice->follow_up_offset_months);
        $this->assertNull($manualOffsetNotice->next_process_date_at);
        $this->assertNull($contractInfoNotice->contract_period_months);
        $this->assertNull($contractInfoNotice->next_process_date_at);
    }

    public function test_saved_payload_returns_rfi_deadline_when_only_rfi_is_upcoming(): void
    {
        $context = $this->customerAdminContext();
        $rfiDeadline = now()->addDays(6)->startOfDay();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1010',
            'Bare RFI',
            rfiSubmissionDeadlineAt: $rfiDeadline->toDateTimeString(),
            deadline: now()->addDay()->startOfDay()->toDateTimeString(),
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('upcoming', $payload['deadline_state']);
        $this->assertSame('RFI', $payload['next_deadline_type']);
        $this->assertSame($rfiDeadline->format('Y-m-d'), substr((string) $payload['next_deadline_at'], 0, 10));
    }

    public function test_saved_payload_returns_rfp_deadline_when_only_rfp_is_upcoming(): void
    {
        $context = $this->customerAdminContext();
        $rfpDeadline = now()->addDays(9)->startOfDay();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011',
            'Bare RFP',
            rfpSubmissionDeadlineAt: $rfpDeadline->toDateTimeString(),
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('upcoming', $payload['deadline_state']);
        $this->assertSame('RFP', $payload['next_deadline_type']);
        $this->assertSame($rfpDeadline->format('Y-m-d'), substr((string) $payload['next_deadline_at'], 0, 10));
    }

    public function test_saved_payload_returns_nearest_future_submission_deadline_when_both_exist(): void
    {
        $context = $this->customerAdminContext();
        $rfiDeadline = now()->addDays(10)->startOfDay();
        $rfpDeadline = now()->addDays(4)->startOfDay();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1012',
            'Begge frister',
            rfiSubmissionDeadlineAt: $rfiDeadline->toDateTimeString(),
            rfpSubmissionDeadlineAt: $rfpDeadline->toDateTimeString(),
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('upcoming', $payload['deadline_state']);
        $this->assertSame('RFP', $payload['next_deadline_type']);
        $this->assertSame($rfpDeadline->format('Y-m-d'), substr((string) $payload['next_deadline_at'], 0, 10));
    }

    public function test_saved_payload_returns_rfp_when_rfi_is_past_and_rfp_is_upcoming(): void
    {
        $context = $this->customerAdminContext();
        $rfpDeadline = now()->addDays(7)->startOfDay();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1013',
            'RFI passert',
            rfiSubmissionDeadlineAt: now()->subDays(2)->startOfDay()->toDateTimeString(),
            rfpSubmissionDeadlineAt: $rfpDeadline->toDateTimeString(),
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('upcoming', $payload['deadline_state']);
        $this->assertSame('RFP', $payload['next_deadline_type']);
        $this->assertSame($rfpDeadline->format('Y-m-d'), substr((string) $payload['next_deadline_at'], 0, 10));
    }

    public function test_saved_payload_marks_deadline_metadata_missing_when_neither_rfi_nor_rfp_exists(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1014',
            'Mangler metadata',
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('missing', $payload['deadline_state']);
        $this->assertNull($payload['next_deadline_type']);
        $this->assertNull($payload['next_deadline_at']);
    }

    public function test_saved_payload_ignores_question_deadline_when_main_deadline_is_derived(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1014-q',
            'Kun spørsmål',
            questionsDeadlineAt: now()->addDays(2)->startOfDay()->toDateTimeString(),
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('missing', $payload['deadline_state']);
        $this->assertNull($payload['next_deadline_type']);
        $this->assertNull($payload['next_deadline_at']);
        $this->assertSame(substr((string) $savedNotice->questions_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['questions_deadline_at'], 0, 10));
    }

    public function test_saved_payload_ignores_extended_timeline_dates_when_main_deadline_is_derived(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1014-x',
            'Utvidet timeline uten submission',
            questionsRfiDeadlineAt: now()->addDays(2)->startOfDay()->toDateTimeString(),
            questionsRfpDeadlineAt: now()->addDays(6)->startOfDay()->toDateTimeString(),
            awardDateAt: now()->addDays(12)->startOfDay()->toDateTimeString(),
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('missing', $payload['deadline_state']);
        $this->assertNull($payload['next_deadline_type']);
        $this->assertNull($payload['next_deadline_at']);
        $this->assertSame(substr((string) $savedNotice->questions_rfi_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['questions_rfi_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->questions_rfp_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['questions_rfp_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->award_date_at?->toIso8601String(), 0, 10), substr((string) $payload['award_date_at'], 0, 10));
    }

    public function test_saved_payload_marks_deadline_as_expired_when_all_submission_deadlines_are_past(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1015',
            'Utløpte frister',
            rfiSubmissionDeadlineAt: now()->subDays(6)->startOfDay()->toDateTimeString(),
            rfpSubmissionDeadlineAt: now()->subDays(1)->startOfDay()->toDateTimeString(),
            status: null,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame('expired', $payload['deadline_state']);
        $this->assertNull($payload['next_deadline_type']);
        $this->assertNull($payload['next_deadline_at']);
    }

    public function test_saved_payload_includes_compact_expanded_metadata_fields(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1016',
            'Metadata-rik notice',
            questionsDeadlineAt: now()->addDays(1)->startOfDay()->toDateTimeString(),
            questionsRfiDeadlineAt: now()->addDays(2)->startOfDay()->toDateTimeString(),
            rfiSubmissionDeadlineAt: now()->addDays(3)->startOfDay()->toDateTimeString(),
            questionsRfpDeadlineAt: now()->addDays(4)->startOfDay()->toDateTimeString(),
            rfpSubmissionDeadlineAt: now()->addDays(9)->startOfDay()->toDateTimeString(),
            awardDateAt: now()->addDays(15)->startOfDay()->toDateTimeString(),
            savedByUserId: $context['admin']->id,
        );

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame($context['admin']->name, $payload['saved_by_name']);
        $this->assertNotNull($payload['saved_at']);
        $this->assertSame(substr((string) $savedNotice->questions_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['questions_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->questions_rfi_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['questions_rfi_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->rfi_submission_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['rfi_submission_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->questions_rfp_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['questions_rfp_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->rfp_submission_deadline_at?->toIso8601String(), 0, 10), substr((string) $payload['rfp_submission_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->award_date_at?->toIso8601String(), 0, 10), substr((string) $payload['award_date_at'], 0, 10));
    }

    public function test_store_saved_notice_persists_saved_by_user_id_without_assigning_commercial_owner(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices')
            ->post('/app/notices/save', [
                'notice_id' => '2026-1017',
                'title' => 'Ny lagret notice',
                'buyer_name' => 'Procynia',
                'external_url' => 'https://doffin.no/notices/2026-1017',
                'summary' => 'Kort oppsummering',
                'publication_date' => '2026-03-20',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
                'cpv_code' => '72000000',
                'rfi_submission_deadline_at' => now()->addDays(4)->toDateString(),
            ]);

        $response->assertRedirect('/app/notices');

        $record = SavedNotice::query()
            ->where('customer_id', $context['customer']->id)
            ->where('external_id', '2026-1017')
            ->first();

        $this->assertInstanceOf(SavedNotice::class, $record);
        $this->assertSame($context['admin']->id, $record->saved_by_user_id);
        $this->assertNull($record->opportunity_owner_user_id);
        $this->assertNotNull($record->rfi_submission_deadline_at);
    }

    public function test_store_saved_notice_case_payload_leaves_commercial_owner_unset_until_explicitly_chosen(): void
    {
        $context = $this->customerAdminContext();

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices')
            ->post('/app/notices/save', [
                'notice_id' => '2026-1017-owner-payload',
                'title' => 'Ny sak med automatisk eier',
                'buyer_name' => 'Procynia',
                'external_url' => 'https://doffin.no/notices/2026-1017-owner-payload',
                'summary' => 'Kort oppsummering',
                'publication_date' => '2026-03-20',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
                'cpv_code' => '72000000',
            ])
            ->assertRedirect('/app/notices');

        $savedNotice = SavedNotice::query()
            ->where('customer_id', $context['customer']->id)
            ->where('external_id', '2026-1017-owner-payload')
            ->firstOrFail();

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertNull($page['props']['notice']['opportunity_owner']);
    }

    public function test_store_saved_notice_starts_new_records_as_discovered(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices')
            ->post('/app/notices/save', [
                'notice_id' => '2026-1017-discovered',
                'title' => 'Notice med bid-status',
                'buyer_name' => 'Procynia',
                'external_url' => 'https://doffin.no/notices/2026-1017-discovered',
                'summary' => 'Kort oppsummering',
                'publication_date' => '2026-03-20',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
                'cpv_code' => '72000000',
            ]);

        $response->assertRedirect('/app/notices');

        $this->assertDatabaseHas('saved_notices', [
            'customer_id' => $context['customer']->id,
            'external_id' => '2026-1017-discovered',
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ]);
    }

    public function test_store_saved_notice_does_not_overwrite_existing_saved_by_user_id(): void
    {
        $original = $this->customerAdminContext('Procynia AS');
        $otherUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $original['customer']->id,
            'is_active' => true,
        ]);

        $savedNotice = $this->createSavedNotice(
            $original['customer']->id,
            '2026-1017-existing',
            'Eksisterende lagret notice',
            savedByUserId: $original['admin']->id,
            opportunityOwnerUserId: $original['admin']->id,
        );

        $response = $this->actingAs($otherUser)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=saved')
            ->post('/app/notices/save', [
                'notice_id' => $savedNotice->external_id,
                'title' => 'Eksisterende lagret notice',
                'buyer_name' => 'Procynia',
                'external_url' => 'https://doffin.no/notices/2026-1017-existing',
                'summary' => 'Oppdatert oppsummering',
                'publication_date' => '2026-03-20',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
                'cpv_code' => '72000000',
            ]);

        $response->assertRedirect('/app/notices?mode=saved');

        $this->assertSame($original['admin']->id, $savedNotice->fresh()->saved_by_user_id);
        $this->assertSame($original['admin']->id, $savedNotice->fresh()->opportunity_owner_user_id);
    }

    public function test_customer_can_update_deadlines_for_own_active_saved_notice(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, '2026-1018', 'Oppdater frister', status: null);
        $questionsRfiDeadline = now()->addDays(2)->toDateString();
        $rfiDeadline = now()->addDays(5)->toDateString();
        $questionsRfpDeadline = now()->addDays(7)->toDateString();
        $rfpDeadline = now()->addDays(11)->toDateString();
        $awardDate = now()->addDays(18)->toDateString();

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=saved')
            ->patch("/app/notices/saved/{$savedNotice->id}/deadlines", [
                'questions_rfi_deadline_at' => $questionsRfiDeadline,
                'rfi_submission_deadline_at' => $rfiDeadline,
                'questions_rfp_deadline_at' => $questionsRfpDeadline,
                'rfp_submission_deadline_at' => $rfpDeadline,
                'award_date_at' => $awardDate,
            ]);

        $response->assertRedirect('/app/notices?mode=saved');

        $savedNotice->refresh();

        $this->assertSame($questionsRfiDeadline, $savedNotice->questions_rfi_deadline_at?->toDateString());
        $this->assertSame($rfiDeadline, $savedNotice->rfi_submission_deadline_at?->toDateString());
        $this->assertSame($questionsRfpDeadline, $savedNotice->questions_rfp_deadline_at?->toDateString());
        $this->assertSame($rfpDeadline, $savedNotice->rfp_submission_deadline_at?->toDateString());
        $this->assertSame($awardDate, $savedNotice->award_date_at?->toDateString());
    }

    public function test_customer_cannot_update_deadlines_for_foreign_or_archived_saved_notice(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $archivedNotice = $this->createSavedNotice($primary['customer']->id, '2026-1019', 'Arkivert post', archived: true, status: null);
        $foreignNotice = $this->createSavedNotice($secondary['customer']->id, '2026-1020', 'Fremmed post', status: null);

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch("/app/notices/saved/{$archivedNotice->id}/deadlines", [
                'questions_rfi_deadline_at' => now()->addDays(1)->toDateString(),
                'rfi_submission_deadline_at' => now()->addDays(2)->toDateString(),
                'questions_rfp_deadline_at' => now()->addDays(3)->toDateString(),
                'award_date_at' => now()->addDays(5)->toDateString(),
            ])
            ->assertNotFound();

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch("/app/notices/saved/{$foreignNotice->id}/deadlines", [
                'questions_rfi_deadline_at' => now()->addDays(1)->toDateString(),
                'rfp_submission_deadline_at' => now()->addDays(4)->toDateString(),
                'questions_rfp_deadline_at' => now()->addDays(3)->toDateString(),
                'award_date_at' => now()->addDays(5)->toDateString(),
            ])
            ->assertNotFound();

        $this->assertNull($archivedNotice->fresh()->questions_rfi_deadline_at);
        $this->assertNull($archivedNotice->fresh()->rfi_submission_deadline_at);
        $this->assertNull($archivedNotice->fresh()->questions_rfp_deadline_at);
        $this->assertNull($archivedNotice->fresh()->award_date_at);
        $this->assertNull($foreignNotice->fresh()->questions_rfi_deadline_at);
        $this->assertNull($foreignNotice->fresh()->rfp_submission_deadline_at);
        $this->assertNull($foreignNotice->fresh()->questions_rfp_deadline_at);
        $this->assertNull($foreignNotice->fresh()->award_date_at);
    }

    public function test_saved_payload_reflects_updated_deadlines_and_canonical_deadline_after_update(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, '2026-1021', 'Payload oppdateres', status: null);
        $rfiDeadline = now()->addDays(8)->toDateString();
        $rfpDeadline = now()->addDays(3)->toDateString();

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=saved')
            ->patch("/app/notices/saved/{$savedNotice->id}/deadlines", [
                'questions_rfi_deadline_at' => now()->addDays(1)->toDateString(),
                'rfi_submission_deadline_at' => $rfiDeadline,
                'questions_rfp_deadline_at' => now()->addDays(2)->toDateString(),
                'rfp_submission_deadline_at' => $rfpDeadline,
                'award_date_at' => now()->addDays(12)->toDateString(),
            ])
            ->assertRedirect('/app/notices?mode=saved');

        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame(now()->addDays(1)->toDateString(), substr((string) $payload['questions_rfi_deadline_at'], 0, 10));
        $this->assertSame($rfiDeadline, substr((string) $payload['rfi_submission_deadline_at'], 0, 10));
        $this->assertSame(now()->addDays(2)->toDateString(), substr((string) $payload['questions_rfp_deadline_at'], 0, 10));
        $this->assertSame($rfpDeadline, substr((string) $payload['rfp_submission_deadline_at'], 0, 10));
        $this->assertSame(now()->addDays(12)->toDateString(), substr((string) $payload['award_date_at'], 0, 10));
        $this->assertSame('upcoming', $payload['deadline_state']);
        $this->assertSame('RFP', $payload['next_deadline_type']);
        $this->assertSame($rfpDeadline, substr((string) $payload['next_deadline_at'], 0, 10));
    }

    public function test_customer_can_archive_saved_notice_and_see_it_in_history(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1005',
            'Flytt meg',
            questionsRfiDeadlineAt: now()->addDays(1)->startOfDay()->toDateTimeString(),
            rfiSubmissionDeadlineAt: now()->addDays(4)->startOfDay()->toDateTimeString(),
            questionsRfpDeadlineAt: now()->addDays(7)->startOfDay()->toDateTimeString(),
            rfpSubmissionDeadlineAt: now()->addDays(11)->startOfDay()->toDateTimeString(),
            awardDateAt: now()->addDays(18)->startOfDay()->toDateTimeString(),
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=saved')
            ->patch("/app/notices/saved/{$savedNotice->id}/archive");

        $response->assertRedirect('/app/notices?mode=saved');
        $this->assertNotNull($savedNotice->fresh()->archived_at);

        $savedPage = $this->inertiaPage(
            $this->actingAs($context['admin'])
                ->get('/app/notices?mode=saved'),
        );

        $this->assertSame(0, $savedPage['props']['worklist']['saved_count']);
        $this->assertSame(1, $savedPage['props']['worklist']['history_count']);
        $this->assertSame(0, $savedPage['props']['notices']['meta']['total']);

        $historyPage = $this->inertiaPage(
            $this->actingAs($context['admin'])
                ->get('/app/notices?mode=history'),
        );

        $this->assertSame(1, $historyPage['props']['notices']['meta']['total']);
        $this->assertSame('Flytt meg', $historyPage['props']['notices']['data'][0]['title']);
        $this->assertSame(substr((string) $savedNotice->fresh()->questions_rfi_deadline_at?->toIso8601String(), 0, 10), substr((string) $historyPage['props']['notices']['data'][0]['questions_rfi_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->fresh()->rfi_submission_deadline_at?->toIso8601String(), 0, 10), substr((string) $historyPage['props']['notices']['data'][0]['rfi_submission_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->fresh()->questions_rfp_deadline_at?->toIso8601String(), 0, 10), substr((string) $historyPage['props']['notices']['data'][0]['questions_rfp_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->fresh()->rfp_submission_deadline_at?->toIso8601String(), 0, 10), substr((string) $historyPage['props']['notices']['data'][0]['rfp_submission_deadline_at'], 0, 10));
        $this->assertSame(substr((string) $savedNotice->fresh()->award_date_at?->toIso8601String(), 0, 10), substr((string) $historyPage['props']['notices']['data'][0]['award_date_at'], 0, 10));
    }

    public function test_customer_can_delete_only_active_saved_notices_from_own_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $savedNotice = $this->createSavedNotice($primary['customer']->id, '2026-1006', 'Slett meg');
        $archivedNotice = $this->createSavedNotice($primary['customer']->id, '2026-1007', 'Historikkpost', archived: true);
        $foreignNotice = $this->createSavedNotice($secondary['customer']->id, '2026-1008', 'Annen kunde');

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=saved')
            ->delete("/app/notices/saved/{$savedNotice->id}")
            ->assertRedirect('/app/notices?mode=saved');

        $this->assertDatabaseMissing('saved_notices', [
            'id' => $savedNotice->id,
        ]);

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->delete("/app/notices/saved/{$archivedNotice->id}")
            ->assertNotFound();

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->delete("/app/notices/saved/{$foreignNotice->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('saved_notices', [
            'id' => $foreignNotice->id,
            'customer_id' => $secondary['customer']->id,
        ]);
    }

    public function test_customer_can_delete_only_archived_history_notices_from_own_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $historyNotice = $this->createSavedNotice($primary['customer']->id, '2026-1006-h', 'Historikk som slettes', archived: true);
        $activeNotice = $this->createSavedNotice($primary['customer']->id, '2026-1006-a', 'Aktiv notice');
        $foreignHistoryNotice = $this->createSavedNotice($secondary['customer']->id, '2026-1006-f', 'Fremmed historikk', archived: true);

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->delete("/app/notices/history/{$historyNotice->id}")
            ->assertRedirect('/app/notices?mode=history');

        $this->assertDatabaseMissing('saved_notices', [
            'id' => $historyNotice->id,
        ]);

        $historyPage = $this->inertiaPage(
            $this->actingAs($primary['admin'])
                ->get('/app/notices?mode=history'),
        );

        $this->assertSame(1, $historyPage['props']['worklist']['saved_count']);
        $this->assertSame(0, $historyPage['props']['worklist']['history_count']);
        $this->assertSame(0, $historyPage['props']['notices']['meta']['total']);

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->delete("/app/notices/history/{$activeNotice->id}")
            ->assertNotFound();

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->delete("/app/notices/history/{$foreignHistoryNotice->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('saved_notices', [
            'id' => $activeNotice->id,
            'customer_id' => $primary['customer']->id,
        ]);

        $this->assertDatabaseHas('saved_notices', [
            'id' => $foreignHistoryNotice->id,
            'customer_id' => $secondary['customer']->id,
        ]);
    }

    public function test_saving_an_archived_notice_reactivates_it_in_saved_mode(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, '2026-1009', 'Kom tilbake', archived: true);

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/notices?mode=history')
            ->post('/app/notices/save', [
                'notice_id' => $savedNotice->external_id,
                'title' => 'Kom tilbake',
                'buyer_name' => 'Procynia',
                'external_url' => 'https://doffin.no/notices/2026-1009',
                'summary' => 'Oppdatert oppsummering',
                'publication_date' => '2026-03-20',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
                'cpv_code' => '72000000',
            ]);

        $response->assertRedirect('/app/notices?mode=history');

        $savedNotice->refresh();

        $this->assertNull($savedNotice->archived_at);

        $savedPage = $this->inertiaPage(
            $this->actingAs($context['admin'])
                ->get('/app/notices?mode=saved'),
        );

        $this->assertSame(1, $savedPage['props']['worklist']['saved_count']);
        $this->assertSame(0, $savedPage['props']['worklist']['history_count']);
        $this->assertSame('Kom tilbake', $savedPage['props']['notices']['data'][0]['title']);
    }

    public function test_saved_notice_payload_includes_case_link_bid_status_and_submission_count(): void
    {
        $context = $this->customerAdminContext();
        $opportunityOwner = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1010-case-payload',
            'Case payload',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            opportunityOwnerUserId: $opportunityOwner->id,
        );

        $savedNotice->createNextSubmission(now());
        $payload = $this->savedNoticePayload($context['admin'], $savedNotice);

        $this->assertSame(SavedNotice::BID_STATUS_NEGOTIATION, $payload['bid_status']);
        $this->assertSame('Forhandling', $payload['bid_status_label']);
        $this->assertSame(1, $payload['submissions_count']);
        $this->assertSame($opportunityOwner->name, $payload['opportunity_owner_name']);
        $this->assertSame(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]), $payload['show_url']);
    }

    public function test_customer_can_open_saved_notice_case_show_page_for_own_customer(): void
    {
        $context = $this->customerAdminContext();
        $bidManager = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-show',
            'Bid case',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            opportunityOwnerUserId: $context['admin']->id,
            bidManagerUserId: $bidManager->id,
            bidSubmittedAt: '2026-03-25 12:00:00',
        );

        $savedNotice->createNextSubmission(Carbon::parse('2026-03-26 09:00:00'));
        $savedNotice->createNextSubmission(Carbon::parse('2026-03-27 10:00:00'));

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertSame('App/Notices/SavedShow', $page['component']);
        $this->assertSame($savedNotice->id, $page['props']['notice']['id']);
        $this->assertSame('Bid case', $page['props']['notice']['title']);
        $this->assertSame(SavedNotice::BID_STATUS_NEGOTIATION, $page['props']['notice']['bid_status']);
        $this->assertSame('Forhandling', $page['props']['notice']['bid_status_label']);
        $this->assertSame($context['admin']->name, $page['props']['notice']['opportunity_owner']['name']);
        $this->assertSame(User::BID_ROLE_SYSTEM_OWNER, $page['props']['notice']['opportunity_owner']['bid_role']);
        $this->assertSame($bidManager->name, $page['props']['notice']['bid_manager']['name']);
        $this->assertSame(User::BID_ROLE_BID_MANAGER, $page['props']['notice']['bid_manager']['bid_role']);
        $this->assertNotEmpty($page['props']['notice']['actions']['opportunity_owner_options']);
        $this->assertNotEmpty($page['props']['notice']['actions']['bid_manager_options']);
        $this->assertSame(
            ['Initial Submission', 'Revised Submission 1'],
            array_column($page['props']['notice']['submissions'], 'label'),
        );
    }

    public function test_saved_notice_case_show_page_exposes_case_access_controls_for_assigned_bid_manager(): void
    {
        $context = $this->customerAdminContext();
        $bidManager = User::factory()->create([
            'name' => 'Case Bid Manager',
            'email' => 'case.manager@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        User::factory()->create([
            'name' => 'Eligible Contributor',
            'email' => 'eligible.contributor@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-access-visible',
            'Case access visible',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            bidManagerUserId: $bidManager->id,
        );

        $page = $this->inertiaPage(
            $this->actingAs($bidManager)->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertTrue($page['props']['notice']['actions']['case_access']['can_manage']);
        $this->assertSame(
            route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]),
            $page['props']['notice']['actions']['case_access']['store_url'],
        );
        $this->assertNotEmpty($page['props']['notice']['actions']['case_access']['user_options']);
        $this->assertSame([], $page['props']['notice']['actions']['case_access']['accesses']);
    }

    public function test_case_bid_manager_can_grant_and_revoke_contributor_case_access(): void
    {
        $context = $this->customerAdminContext();
        $bidManager = User::factory()->create([
            'name' => 'Case Bid Manager',
            'email' => 'case.manager.grant@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $targetContributor = User::factory()->create([
            'name' => 'Case Contributor',
            'email' => 'case.contributor@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-access-grant',
            'Case access grant',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            bidManagerUserId: $bidManager->id,
        );

        $grantResponse = $this->actingAs($bidManager)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]), [
                'user_id' => $targetContributor->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ]);

        $grantResponse->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]));

        $this->assertDatabaseHas('saved_notice_user_access', [
            'saved_notice_id' => $savedNotice->id,
            'user_id' => $targetContributor->id,
            'granted_by_user_id' => $bidManager->id,
            'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            'revoked_at' => null,
        ]);

        $this->actingAs($bidManager)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]), [
                'user_id' => $targetContributor->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ])
            ->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]));

        $this->assertSame(
            1,
            SavedNoticeUserAccess::query()
                ->where('saved_notice_id', $savedNotice->id)
                ->where('user_id', $targetContributor->id)
                ->count(),
        );

        $pageAfterGrant = $this->inertiaPage(
            $this->actingAs($bidManager)->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertCount(1, $pageAfterGrant['props']['notice']['actions']['case_access']['accesses']);
        $this->assertSame(
            $targetContributor->id,
            $pageAfterGrant['props']['notice']['actions']['case_access']['accesses'][0]['user']['id'],
        );

        $access = SavedNoticeUserAccess::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('user_id', $targetContributor->id)
            ->firstOrFail();

        $revokeResponse = $this->actingAs($bidManager)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->delete(route('app.notices.saved.case-access.destroy', [
                'savedNotice' => $savedNotice->id,
                'caseAccess' => $access->id,
            ]));

        $revokeResponse->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]));

        $access->refresh();

        $this->assertNotNull($access->revoked_at);

        $pageAfterRevoke = $this->inertiaPage(
            $this->actingAs($bidManager)->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertSame([], $pageAfterRevoke['props']['notice']['actions']['case_access']['accesses']);
    }

    public function test_case_opportunity_owner_can_grant_viewer_case_access(): void
    {
        $context = $this->customerAdminContext();
        $opportunityOwner = User::factory()->create([
            'name' => 'Case Owner',
            'email' => 'case.owner@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $targetViewer = User::factory()->create([
            'name' => 'Case Viewer',
            'email' => 'case.viewer@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-owner-access',
            'Case owner access',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            opportunityOwnerUserId: $opportunityOwner->id,
        );

        $response = $this->actingAs($opportunityOwner)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]), [
                'user_id' => $targetViewer->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_VIEWER,
            ]);

        $response->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]));

        $this->assertDatabaseHas('saved_notice_user_access', [
            'saved_notice_id' => $savedNotice->id,
            'user_id' => $targetViewer->id,
            'granted_by_user_id' => $opportunityOwner->id,
            'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_VIEWER,
            'revoked_at' => null,
        ]);

        $page = $this->inertiaPage(
            $this->actingAs($opportunityOwner)->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertTrue($page['props']['notice']['actions']['case_access']['can_manage']);
        $this->assertSame(1, count($page['props']['notice']['actions']['case_access']['accesses']));
    }

    public function test_unrelated_bid_manager_cannot_grant_case_access(): void
    {
        $context = $this->customerAdminContext();
        $assignedBidManager = User::factory()->create([
            'name' => 'Assigned Bid Manager',
            'email' => 'assigned.manager@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $unrelatedBidManager = User::factory()->create([
            'name' => 'Unrelated Bid Manager',
            'email' => 'unrelated.manager@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-unrelated-manager',
            'Unrelated manager case',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            bidManagerUserId: $assignedBidManager->id,
        );

        $this->actingAs($unrelatedBidManager)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]), [
                'user_id' => $assignedBidManager->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ])
            ->assertForbidden();
    }

    public function test_contributor_cannot_grant_case_access(): void
    {
        $context = $this->customerAdminContext();
        $assignedBidManager = User::factory()->create([
            'name' => 'Assigned Bid Manager',
            'email' => 'assigned.manager.contributor@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $contributor = User::factory()->create([
            'name' => 'Contributor User',
            'email' => 'contributor.user@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-contributor-forbidden',
            'Contributor forbidden case',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            bidManagerUserId: $assignedBidManager->id,
        );

        $this->actingAs($contributor)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]), [
                'user_id' => $assignedBidManager->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ])
            ->assertForbidden();
    }

    public function test_non_managing_user_does_not_see_case_access_controls(): void
    {
        $context = $this->customerAdminContext();
        $assignedBidManager = User::factory()->create([
            'name' => 'Assigned Bid Manager',
            'email' => 'assigned.manager.hidden@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $viewer = User::factory()->create([
            'name' => 'Visible Viewer',
            'email' => 'visible.viewer@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-controls-hidden',
            'Hidden controls case',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            bidManagerUserId: $assignedBidManager->id,
        );

        $page = $this->inertiaPage(
            $this->actingAs($viewer)->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertFalse($page['props']['notice']['actions']['case_access']['can_manage']);
        $this->assertNull($page['props']['notice']['actions']['case_access']['store_url']);
        $this->assertSame([], $page['props']['notice']['actions']['case_access']['user_options']);
    }

    public function test_saved_notice_case_show_page_exposes_phase_comments_and_allows_new_comment_for_non_viewer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-01 11:15:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-phase-comments-visible',
            'Phase comments case',
            bidStatus: SavedNotice::BID_STATUS_QUALIFYING,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.notices.saved.phase-comments.store', ['savedNotice' => $savedNotice->id]), [
                'comment' => 'Qualification note from system owner.',
            ]);

        $response->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]));

        $this->assertDatabaseHas('saved_notice_phase_comments', [
            'saved_notice_id' => $savedNotice->id,
            'user_id' => $context['admin']->id,
            'phase_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'comment' => 'Qualification note from system owner.',
        ]);

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id])),
        );

        $this->assertTrue($page['props']['notice']['phase_comments']['can_comment']);
        $this->assertSame(route('app.notices.saved.phase-comments.store', ['savedNotice' => $savedNotice->id]), $page['props']['notice']['phase_comments']['store_url']);
        $this->assertSame(SavedNotice::BID_STATUS_QUALIFYING, $page['props']['notice']['phase_comments']['active_phase_status']);
        $this->assertCount(1, $page['props']['notice']['phase_comments']['comments']);
        $this->assertSame('Qualification note from system owner.', $page['props']['notice']['phase_comments']['comments'][0]['comment']);
    }

    public function test_viewer_cannot_store_phase_comment_for_saved_notice(): void
    {
        $context = $this->customerAdminContext();
        $viewer = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-phase-comments-viewer',
            'Phase comments viewer case',
            bidStatus: SavedNotice::BID_STATUS_IN_PROGRESS,
        );

        $this->actingAs($viewer)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.notices.saved.phase-comments.store', ['savedNotice' => $savedNotice->id]), [
            'comment' => 'Viewer comment should fail.',
        ])
            ->assertForbidden();

        $page = $this->inertiaPage(
            $this->actingAs($viewer)->get(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id])),
        );

        $this->assertFalse($page['props']['notice']['phase_comments']['can_comment']);
        $this->assertDatabaseMissing('saved_notice_phase_comments', [
            'saved_notice_id' => $savedNotice->id,
            'comment' => 'Viewer comment should fail.',
        ]);
    }

    public function test_phase_comments_are_persisted_per_phase_when_status_changes(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-phase-comments-history',
            'Phase comments history case',
            bidStatus: SavedNotice::BID_STATUS_QUALIFYING,
        );

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->post(route('app.notices.saved.phase-comments.store', ['savedNotice' => $savedNotice->id]), [
                'comment' => 'Qualification comment.',
            ])
            ->assertRedirect();

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch(route('app.notices.saved.status.update', ['savedNotice' => $savedNotice->id]), [
                'status' => SavedNotice::BID_STATUS_GO_NO_GO,
            ])
            ->assertRedirect();

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch(route('app.notices.saved.status.update', ['savedNotice' => $savedNotice->id]), [
                'status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            ])
            ->assertRedirect();

        $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->post(route('app.notices.saved.phase-comments.store', ['savedNotice' => $savedNotice->id]), [
                'comment' => 'In progress comment.',
            ])
            ->assertRedirect();

        $phaseStatuses = SavedNoticePhaseComment::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->orderBy('created_at')
            ->pluck('phase_status')
            ->all();

        $this->assertSame([
            SavedNotice::BID_STATUS_QUALIFYING,
            SavedNotice::BID_STATUS_IN_PROGRESS,
        ], $phaseStatuses);

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id])),
        );

        $this->assertCount(2, $page['props']['notice']['phase_comments']['comments']);
        $this->assertSame(
            [
                SavedNotice::BID_STATUS_QUALIFYING,
                SavedNotice::BID_STATUS_IN_PROGRESS,
            ],
            array_column($page['props']['notice']['phase_comments']['comments'], 'phase_status'),
        );
    }

    public function test_multiple_contributors_can_be_granted_to_the_same_saved_notice(): void
    {
        $context = $this->customerAdminContext();
        $bidManager = User::factory()->create([
            'name' => 'Case Bid Manager',
            'email' => 'case.manager.multiple@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $firstContributor = User::factory()->create([
            'name' => 'First Contributor',
            'email' => 'first.contributor@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $secondContributor = User::factory()->create([
            'name' => 'Second Contributor',
            'email' => 'second.contributor@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-multiple-access',
            'Multiple access case',
            bidStatus: SavedNotice::BID_STATUS_NEGOTIATION,
            bidManagerUserId: $bidManager->id,
        );

        $this->actingAs($bidManager)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]), [
                'user_id' => $firstContributor->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ])
            ->assertRedirect();

        $this->actingAs($bidManager)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $savedNotice->id]), [
                'user_id' => $secondContributor->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_VIEWER,
            ])
            ->assertRedirect();

        $this->assertSame(2, $savedNotice->fresh()->userAccesses()->active()->count());

        $page = $this->inertiaPage(
            $this->actingAs($bidManager)->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $this->assertCount(2, $page['props']['notice']['actions']['case_access']['accesses']);
        $this->assertEqualsCanonicalizing(
            [$firstContributor->id, $secondContributor->id],
            array_map(
                fn (array $access): int => (int) $access['user']['id'],
                $page['props']['notice']['actions']['case_access']['accesses'],
            ),
        );
    }

    public function test_customer_can_update_opportunity_owner_for_own_saved_notice(): void
    {
        $context = $this->customerAdminContext();
        $opportunityOwner = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-owner-update',
            'Owner update case',
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch("/app/notices/saved/{$savedNotice->id}/opportunity-owner", [
                'opportunity_owner_user_id' => $opportunityOwner->id,
            ]);

        $response->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]));
        $savedNotice->refresh();

        $this->assertSame($opportunityOwner->id, $savedNotice->opportunity_owner_user_id);
    }

    public function test_customer_cannot_set_opportunity_owner_from_another_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $foreignOwner = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $secondary['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice(
            $primary['customer']->id,
            '2026-1011-owner-foreign',
            'Foreign owner case',
        );

        $response = $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->patch("/app/notices/saved/{$savedNotice->id}/opportunity-owner", [
                'opportunity_owner_user_id' => $foreignOwner->id,
            ]);

        $response->assertSessionHasErrors('opportunity_owner_user_id');
        $savedNotice->refresh();

        $this->assertNull($savedNotice->opportunity_owner_user_id);
    }

    public function test_customer_can_update_bid_manager_for_own_saved_notice(): void
    {
        $context = $this->customerAdminContext();
        $bidManager = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-bid-manager-update',
            'Bid manager update case',
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch("/app/notices/saved/{$savedNotice->id}/bid-manager", [
                'bid_manager_user_id' => $bidManager->id,
            ]);

        $response->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]));
        $savedNotice->refresh();

        $this->assertSame($bidManager->id, $savedNotice->bid_manager_user_id);
    }

    public function test_customer_cannot_set_bid_manager_to_user_without_bid_manager_role(): void
    {
        $context = $this->customerAdminContext();
        $contributor = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-bid-manager-invalid',
            'Invalid bid manager case',
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]))
            ->patch("/app/notices/saved/{$savedNotice->id}/bid-manager", [
                'bid_manager_user_id' => $contributor->id,
            ]);

        $response->assertSessionHasErrors('bid_manager_user_id');
        $savedNotice->refresh();

        $this->assertNull($savedNotice->bid_manager_user_id);
    }

    public function test_saved_notice_case_show_page_exposes_backend_defined_actions_for_discovered_case(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-actions',
            'Case actions',
            bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
        );

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $actions = $page['props']['notice']['actions']['status_actions'];

        $this->assertSame(
            [SavedNotice::BID_STATUS_QUALIFYING, SavedNotice::BID_STATUS_NO_GO],
            array_column($actions, 'status'),
        );
        $this->assertSame(
            [false, true],
            array_column($actions, 'requires_closure_reason'),
        );
        $this->assertSame(
            route('app.notices.saved.status.update', ['savedNotice' => $savedNotice->id]),
            $page['props']['notice']['actions']['update_status_url'],
        );
    }

    public function test_saved_notice_case_show_page_exposes_go_no_go_decision_actions(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-go-no-go-actions',
            'Go No-Go case',
            bidStatus: SavedNotice::BID_STATUS_GO_NO_GO,
        );

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $actions = $page['props']['notice']['actions']['status_actions'];

        $this->assertSame(
            [SavedNotice::BID_STATUS_IN_PROGRESS, SavedNotice::BID_STATUS_NO_GO],
            array_column($actions, 'status'),
        );
        $this->assertSame(
            ['Move to In Progress', 'Set as No-Go'],
            array_column($actions, 'label'),
        );
    }

    public function test_saved_notice_case_show_page_exposes_late_stage_actions_for_submitted_case(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-submitted-actions',
            'Submitted case',
            bidStatus: SavedNotice::BID_STATUS_SUBMITTED,
        );

        $page = $this->inertiaPage(
            $this->actingAs($context['admin'])->get("/app/notices/saved/{$savedNotice->id}"),
        );

        $actions = $page['props']['notice']['actions']['status_actions'];

        $this->assertSame(
            [
                SavedNotice::BID_STATUS_NEGOTIATION,
                SavedNotice::BID_STATUS_WON,
                SavedNotice::BID_STATUS_LOST,
                SavedNotice::BID_STATUS_WITHDRAWN,
            ],
            array_column($actions, 'status'),
        );
        $this->assertTrue($page['props']['notice']['actions']['can_create_submission']);
    }

    public function test_customer_can_move_saved_notice_forward_from_case_page(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-progress',
            'Progress case',
            bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from("/app/notices/saved/{$savedNotice->id}")
            ->patch("/app/notices/saved/{$savedNotice->id}/status", [
                'status' => SavedNotice::BID_STATUS_QUALIFYING,
            ]);

        $response->assertRedirect("/app/notices/saved/{$savedNotice->id}");

        $savedNotice->refresh();

        $this->assertSame(SavedNotice::BID_STATUS_QUALIFYING, $savedNotice->bid_status);
        $this->assertNull($savedNotice->bid_closed_at);
        $this->assertNull($savedNotice->bid_closure_reason);
    }

    public function test_customer_must_provide_closure_reason_when_setting_case_as_no_go(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-no-go-required',
            'No-Go case',
            bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from("/app/notices/saved/{$savedNotice->id}")
            ->patch("/app/notices/saved/{$savedNotice->id}/status", [
                'status' => SavedNotice::BID_STATUS_NO_GO,
            ]);

        $response->assertSessionHasErrors(['bid_closure_reason']);
        $this->assertSame(SavedNotice::BID_STATUS_DISCOVERED, $savedNotice->fresh()->bid_status);
    }

    public function test_customer_can_close_saved_notice_as_no_go_with_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 16:45:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-no-go',
            'Close as no-go',
            bidStatus: SavedNotice::BID_STATUS_GO_NO_GO,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from("/app/notices/saved/{$savedNotice->id}")
            ->patch("/app/notices/saved/{$savedNotice->id}/status", [
                'status' => SavedNotice::BID_STATUS_NO_GO,
                'bid_closure_reason' => SavedNotice::BID_CLOSURE_REASON_CAPACITY,
                'bid_closure_note' => 'Too many parallel bids.',
            ]);

        $response->assertRedirect("/app/notices/saved/{$savedNotice->id}");

        $savedNotice->refresh();

        $this->assertSame(SavedNotice::BID_STATUS_NO_GO, $savedNotice->bid_status);
        $this->assertSame(SavedNotice::BID_CLOSURE_REASON_CAPACITY, $savedNotice->bid_closure_reason);
        $this->assertSame('Too many parallel bids.', $savedNotice->bid_closure_note);
        $this->assertSame('2026-03-31 16:45:00', $savedNotice->bid_closed_at?->format('Y-m-d H:i:s'));
    }

    public function test_invalid_saved_notice_status_transition_is_rejected_by_status_endpoint(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-invalid-transition',
            'Invalid transition case',
            bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from("/app/notices/saved/{$savedNotice->id}")
            ->patch("/app/notices/saved/{$savedNotice->id}/status", [
                'status' => SavedNotice::BID_STATUS_LOST,
            ]);

        $response->assertSessionHasErrors(['status']);
        $this->assertSame(SavedNotice::BID_STATUS_DISCOVERED, $savedNotice->fresh()->bid_status);
    }

    public function test_customer_can_archive_terminal_saved_notice_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 17:00:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1011-case-archive',
            'Archive case',
            bidStatus: SavedNotice::BID_STATUS_WON,
            bidClosedAt: '2026-03-20 10:00:00',
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from("/app/notices/saved/{$savedNotice->id}")
            ->patch("/app/notices/saved/{$savedNotice->id}/status", [
                'status' => SavedNotice::BID_STATUS_ARCHIVED,
            ]);

        $response->assertRedirect("/app/notices/saved/{$savedNotice->id}");

        $savedNotice->refresh();

        $this->assertSame(SavedNotice::BID_STATUS_ARCHIVED, $savedNotice->bid_status);
        $this->assertSame('2026-03-20 10:00:00', $savedNotice->bid_closed_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 17:00:00', $savedNotice->archived_at?->format('Y-m-d H:i:s'));
    }

    public function test_customer_cannot_change_saved_notice_status_outside_customer_scope(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $foreignNotice = $this->createSavedNotice(
            $secondary['customer']->id,
            '2026-1011-case-foreign',
            'Foreign case',
            bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
        );

        $this->actingAs($primary['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->patch("/app/notices/saved/{$foreignNotice->id}/status", [
                'status' => SavedNotice::BID_STATUS_QUALIFYING,
            ])
            ->assertNotFound();

        $this->assertSame(SavedNotice::BID_STATUS_DISCOVERED, $foreignNotice->fresh()->bid_status);
    }

    public function test_customer_can_create_submission_from_saved_notice_case_page_when_status_allows_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 14:30:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1012-case-submission',
            'Submission case',
            bidStatus: SavedNotice::BID_STATUS_SUBMITTED,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from("/app/notices/saved/{$savedNotice->id}")
            ->post("/app/notices/saved/{$savedNotice->id}/submissions");

        $response->assertRedirect("/app/notices/saved/{$savedNotice->id}");

        $submission = $savedNotice->fresh()->submissions()->first();

        $this->assertNotNull($submission);
        $this->assertSame(1, $submission->sequence_number);
        $this->assertSame('Initial Submission', $submission->label);
        $this->assertSame('2026-03-31 14:30:00', $submission->submitted_at?->format('Y-m-d H:i:s'));
    }

    public function test_customer_cannot_create_submission_from_saved_notice_case_page_when_status_is_not_allowed(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-1013-case-blocked',
            'Blocked submission case',
            bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
        );

        $response = $this->actingAs($context['admin'])
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from("/app/notices/saved/{$savedNotice->id}")
            ->post("/app/notices/saved/{$savedNotice->id}/submissions");

        $response->assertRedirect("/app/notices/saved/{$savedNotice->id}");
        $response->assertSessionHas('error');
        $this->assertSame(0, $savedNotice->fresh()->submissions()->count());
    }

    public function test_department_affiliated_contributor_can_only_see_saved_notices_within_primary_department_or_explicit_case_access(): void
    {
        $context = $this->customerAdminContext();
        $sales = $this->createDepartment($context['customer']->id, 'Sales');
        $delivery = $this->createDepartment($context['customer']->id, 'Delivery');
        $contributor = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT,
            'primary_department_id' => $sales->id,
            'department_id' => $sales->id,
        ]);

        $visibleNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-access-visible',
            'Sales-sak',
            organizationalDepartmentId: $sales->id,
        );
        $hiddenNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-access-hidden',
            'Delivery-sak',
            organizationalDepartmentId: $delivery->id,
        );

        $savedPage = $this->inertiaPage(
            $this->actingAs($contributor)->get('/app/notices?mode=saved'),
        );

        $this->assertSame(['Sales-sak'], array_column($savedPage['props']['notices']['data'], 'title'));

        $this->actingAs($contributor)
            ->get("/app/notices/saved/{$visibleNotice->id}")
            ->assertOk();

        $this->actingAs($contributor)
            ->get("/app/notices/saved/{$hiddenNotice->id}")
            ->assertNotFound();

        $hiddenNotice->userAccesses()->create([
            'user_id' => $contributor->id,
            'granted_by_user_id' => $context['admin']->id,
            'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
        ]);

        $pageWithExplicitAccess = $this->inertiaPage(
            $this->actingAs($contributor)->get('/app/notices?mode=saved'),
        );

        $this->assertEqualsCanonicalizing(
            ['Sales-sak', 'Delivery-sak'],
            array_column($pageWithExplicitAccess['props']['notices']['data'], 'title'),
        );

        $this->actingAs($contributor)
            ->get("/app/notices/saved/{$hiddenNotice->id}")
            ->assertOk();
    }

    public function test_department_scoped_bid_manager_can_manage_saved_notices_in_managed_departments_only(): void
    {
        $context = $this->customerAdminContext();
        $sales = $this->createDepartment($context['customer']->id, 'Sales');
        $delivery = $this->createDepartment($context['customer']->id, 'Delivery');
        $manager = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
        ]);
        $manager->managedDepartments()->sync([$sales->id]);

        $managedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-manager-visible',
            'Sales-sak',
            organizationalDepartmentId: $sales->id,
        );
        $blockedNotice = $this->createSavedNotice(
            $context['customer']->id,
            '2026-manager-hidden',
            'Delivery-sak',
            organizationalDepartmentId: $delivery->id,
        );

        $this->actingAs($manager)
            ->patch("/app/notices/saved/{$managedNotice->id}/deadlines", [
                'rfi_submission_deadline_at' => now()->addDays(4)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertNotNull($managedNotice->fresh()->rfi_submission_deadline_at);

        $this->actingAs($manager)
            ->patch("/app/notices/saved/{$blockedNotice->id}/deadlines", [
                'rfi_submission_deadline_at' => now()->addDays(6)->toDateString(),
            ])
            ->assertNotFound();
    }

    private function customerAdminContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $admin = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
        ]);

        return [
            'customer' => $customer,
            'admin' => $admin,
        ];
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function createDepartment(int $customerId, string $name): Department
    {
        return Department::query()->create([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function createSavedNotice(
        int $customerId,
        string $externalId,
        string $title,
        bool $archived = false,
        ?string $questionsDeadlineAt = null,
        ?string $questionsRfiDeadlineAt = null,
        ?string $rfiSubmissionDeadlineAt = null,
        ?string $questionsRfpDeadlineAt = null,
        ?string $rfpSubmissionDeadlineAt = null,
        ?string $awardDateAt = null,
        ?string $selectedSupplierName = null,
        ?string $contractValueMnok = null,
        ?string $contractPeriodText = null,
        ?int $contractPeriodMonths = null,
        ?string $procurementType = null,
        ?string $followUpMode = null,
        ?int $followUpOffsetMonths = null,
        ?string $nextProcessDateAt = null,
        ?string $deadline = '2026-04-20 00:00:00',
        ?string $status = 'ACTIVE',
        ?int $savedByUserId = null,
        ?string $bidStatus = SavedNotice::BID_STATUS_DISCOVERED,
        ?int $opportunityOwnerUserId = null,
        ?int $bidManagerUserId = null,
        ?int $organizationalDepartmentId = null,
        ?string $bidSubmittedAt = null,
        ?string $bidClosedAt = null,
        ?string $bidClosureReason = null,
        ?string $bidClosureNote = null,
    ): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customerId,
            'saved_by_user_id' => $savedByUserId,
            'bid_status' => $bidStatus,
            'opportunity_owner_user_id' => $opportunityOwnerUserId,
            'bid_manager_user_id' => $bidManagerUserId,
            'organizational_department_id' => $organizationalDepartmentId,
            'bid_submitted_at' => $bidSubmittedAt,
            'bid_closed_at' => $bidClosedAt,
            'bid_closure_reason' => $bidClosureReason,
            'bid_closure_note' => $bidClosureNote,
            'external_id' => $externalId,
            'title' => $title,
            'buyer_name' => 'Procynia',
            'external_url' => "https://doffin.no/notices/{$externalId}",
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => $deadline,
            'status' => $status,
            'cpv_code' => '72000000',
            'archived_at' => $archived ? now() : null,
            'questions_deadline_at' => $questionsDeadlineAt,
            'questions_rfi_deadline_at' => $questionsRfiDeadlineAt,
            'rfi_submission_deadline_at' => $rfiSubmissionDeadlineAt,
            'questions_rfp_deadline_at' => $questionsRfpDeadlineAt,
            'rfp_submission_deadline_at' => $rfpSubmissionDeadlineAt,
            'award_date_at' => $awardDateAt,
            'selected_supplier_name' => $selectedSupplierName,
            'contract_value_mnok' => $contractValueMnok,
            'contract_period_text' => $contractPeriodText,
            'contract_period_months' => $contractPeriodMonths,
            'procurement_type' => $procurementType,
            'follow_up_mode' => $followUpMode,
            'follow_up_offset_months' => $followUpOffsetMonths,
            'next_process_date_at' => $nextProcessDateAt,
        ]);
    }

    private function savedNoticePayload(User $user, SavedNotice $savedNotice, string $mode = 'saved'): array
    {
        $page = $this->inertiaPage(
            $this->actingAs($user)->get("/app/notices?mode={$mode}"),
        );

        $payload = collect($page['props']['notices']['data'])
            ->first(fn (array $notice): bool => $notice['saved_notice_id'] === $savedNotice->id);

        $this->assertIsArray($payload);

        return $payload;
    }

    private function inertiaPage(TestResponse $response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');

        if (is_array($page)) {
            return $page;
        }

        $this->assertIsString($page);

        return json_decode($page, true, 512, JSON_THROW_ON_ERROR);
    }

    private function ensureSavedNoticesTable(): void
    {
        if (! Schema::hasTable('saved_notices')) {
            Schema::create('saved_notices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('saved_by_user_id')->nullable();
                $table->string('bid_status')->default(SavedNotice::BID_STATUS_DISCOVERED);
                $table->unsignedBigInteger('opportunity_owner_user_id')->nullable();
                $table->unsignedBigInteger('bid_manager_user_id')->nullable();
                $table->unsignedBigInteger('organizational_department_id')->nullable();
                $table->timestamp('bid_qualified_at')->nullable();
                $table->timestamp('bid_submitted_at')->nullable();
                $table->timestamp('bid_closed_at')->nullable();
                $table->string('bid_closure_reason')->nullable();
                $table->text('bid_closure_note')->nullable();
                $table->string('external_id');
                $table->string('title');
                $table->string('buyer_name')->nullable();
                $table->string('external_url', 2000)->nullable();
                $table->text('summary')->nullable();
                $table->timestamp('publication_date')->nullable();
                $table->timestamp('deadline')->nullable();
                $table->string('status')->nullable();
                $table->string('cpv_code')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('questions_deadline_at')->nullable();
                $table->timestamp('questions_rfi_deadline_at')->nullable();
                $table->timestamp('rfi_submission_deadline_at')->nullable();
                $table->timestamp('questions_rfp_deadline_at')->nullable();
                $table->timestamp('rfp_submission_deadline_at')->nullable();
                $table->timestamp('award_date_at')->nullable();
                $table->string('selected_supplier_name')->nullable();
                $table->string('contract_value')->nullable();
                $table->string('contract_period_text')->nullable();
                $table->decimal('contract_value_mnok', 12, 2)->nullable();
                $table->unsignedInteger('contract_period_months')->nullable();
                $table->string('procurement_type')->nullable();
                $table->string('follow_up_mode')->nullable();
                $table->unsignedInteger('follow_up_offset_months')->nullable();
                $table->timestamp('next_process_date_at')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'external_id']);
            });

            $this->createdSavedNoticesTable = true;

            return;
        }

        if (! Schema::hasColumn('saved_notices', 'archived_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'rfi_submission_deadline_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('rfi_submission_deadline_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'questions_deadline_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('questions_deadline_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'questions_rfi_deadline_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('questions_rfi_deadline_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'rfp_submission_deadline_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('rfp_submission_deadline_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'questions_rfp_deadline_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('questions_rfp_deadline_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'award_date_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('award_date_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'saved_by_user_id')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->unsignedBigInteger('saved_by_user_id')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'bid_status')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('bid_status')->default(SavedNotice::BID_STATUS_DISCOVERED);
            });
        }

        if (! Schema::hasColumn('saved_notices', 'opportunity_owner_user_id')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->unsignedBigInteger('opportunity_owner_user_id')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'bid_manager_user_id')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->unsignedBigInteger('bid_manager_user_id')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'organizational_department_id')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->unsignedBigInteger('organizational_department_id')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'bid_qualified_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('bid_qualified_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'bid_submitted_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('bid_submitted_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'bid_closed_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('bid_closed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'bid_closure_reason')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('bid_closure_reason')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'bid_closure_note')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->text('bid_closure_note')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'selected_supplier_name')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('selected_supplier_name')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'contract_value')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('contract_value')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'contract_period_text')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('contract_period_text')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'contract_value_mnok')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->decimal('contract_value_mnok', 12, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'contract_period_months')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->unsignedInteger('contract_period_months')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'procurement_type')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('procurement_type')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'follow_up_mode')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('follow_up_mode')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'follow_up_offset_months')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->unsignedInteger('follow_up_offset_months')->nullable();
            });
        }

        if (! Schema::hasColumn('saved_notices', 'next_process_date_at')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->timestamp('next_process_date_at')->nullable();
            });
        }

        DB::table('saved_notices')
            ->where(function ($query): void {
                $query->whereNull('bid_status')
                    ->orWhere('bid_status', '');
            })
            ->update([
                'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            ]);
    }

    private function ensureBidSubmissionsTable(): void
    {
        if (Schema::hasTable('bid_submissions')) {
            return;
        }

        Schema::create('bid_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('label');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['saved_notice_id', 'sequence_number']);
        });

        $this->createdBidSubmissionsTable = true;
    }

    private function ensureSavedNoticeUserAccessTable(): void
    {
        if (Schema::hasTable('saved_notice_user_access')) {
            return;
        }

        Schema::create('saved_notice_user_access', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('saved_notice_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->string('access_role');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['saved_notice_id', 'user_id']);
        });

        $this->createdSavedNoticeUserAccessTable = true;
    }

    private function ensureSavedNoticePhaseCommentsTable(): void
    {
        if (Schema::hasTable('saved_notice_phase_comments')) {
            return;
        }

        DB::statement('DROP SEQUENCE IF EXISTS saved_notice_phase_comments_id_seq CASCADE');

        Schema::create('saved_notice_phase_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phase_status');
            $table->text('comment');
            $table->timestamps();

            $table->index(['saved_notice_id', 'phase_status']);
            $table->index(['saved_notice_id', 'created_at']);
        });

        $this->createdSavedNoticePhaseCommentsTable = true;
    }

    private function useProjectPostgresConnection(): void
    {
        $connectionName = 'feature_pgsql';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'pgsql',
                'host' => $this->projectEnv('DB_HOST', '127.0.0.1'),
                'port' => $this->projectEnv('DB_PORT', '5432'),
                'database' => $this->projectEnv('DB_DATABASE', 'procynia'),
                'username' => $this->projectEnv('DB_USERNAME', 'gehard'),
                'password' => $this->projectEnv('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);
    }

    private function projectEnv(string $key, string $default): string
    {
        static $values = null;

        if (! is_array($values)) {
            $values = [];

            foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                    continue;
                }

                [$envKey, $envValue] = explode('=', $trimmed, 2);
                $values[$envKey] = trim($envValue, " \t\n\r\0\x0B\"'");
            }
        }

        return $values[$key] ?? $default;
    }
}
