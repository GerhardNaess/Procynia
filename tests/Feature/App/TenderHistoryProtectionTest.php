<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\SavedNoticeStatusService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The tender decisions are kept by the database, whatever entry writes (migration
 * 2026_10_23_000005): who changed a bid status, the No-Go as decided, and who approved, rejected
 * or edited a requirement. Each goes only with what it belongs to — the requirement, the case or
 * the customer — and the one change allowed is nulling a deleted user (and reopening a No-Go once).
 */
class TenderHistoryProtectionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_status_change_is_never_changed_or_deleted_on_its_own(): void
    {
        $world = $this->decidedWorld();
        $change = DB::table('saved_notice_status_changes')->where('saved_notice_id', $world['notice']->id)->first();

        foreach (['to_status' => 'lost', 'from_status' => 'qualifying', 'reason' => 'Omskrevet', 'changed_at' => now()->subYear(), 'changed_by_user_id' => $world['other']->id] as $column => $value) {
            $this->assertRefused(fn () => DB::table('saved_notice_status_changes')->where('id', $change->id)->update([$column => $value]), $column);
        }

        $this->assertRefused(fn () => DB::table('saved_notice_status_changes')->where('id', $change->id)->delete(), 'delete');
        $this->assertSame((array) $change, (array) DB::table('saved_notice_status_changes')->where('id', $change->id)->first());
    }

    public function test_a_no_go_is_kept_as_decided_and_reopened_only_once(): void
    {
        $world = $this->decidedWorld();
        $decision = DB::table('saved_notice_no_go_decisions')->where('saved_notice_id', $world['noGo']->id)->first();

        foreach (['closure_reason' => 'customer_cancelled', 'closure_note' => 'Omskrevet', 'closed_at' => now()->subYear(), 'closed_by_user_id' => $world['other']->id, 'saved_notice_id' => $world['notice']->id] as $column => $value) {
            $this->assertRefused(fn () => DB::table('saved_notice_no_go_decisions')->where('id', $decision->id)->update([$column => $value]), $column);
        }

        $this->assertRefused(fn () => DB::table('saved_notice_no_go_decisions')->where('id', $decision->id)->delete(), 'delete');

        // Reopened through the product: allowed once.
        app(SavedNoticeStatusService::class)->reopenAfterNoGo($world['owner'], $world['noGo'], 'Ny vurdering.', true);
        $reopened = DB::table('saved_notice_no_go_decisions')->where('id', $decision->id)->first();
        $this->assertSame($world['owner']->id, (int) $reopened->reopened_by_user_id);

        foreach (['reopen_reason' => 'Omskrevet', 'reopened_at' => now()->subYear(), 'reopened_by_user_id' => $world['other']->id, 'reopened_at ' => null] as $column => $value) {
            $this->assertRefused(fn () => DB::table('saved_notice_no_go_decisions')->where('id', $decision->id)->update([trim($column) => $value]), $column);
        }
    }

    public function test_requirement_revisions_are_never_changed_or_deleted_on_their_own(): void
    {
        $world = $this->decidedWorld();
        $revision = DB::table('saved_notice_ai_requirement_revisions')
            ->where('saved_notice_ai_requirement_id', $world['requirement']->id)
            ->where('change_type', 'approve')
            ->first();
        $this->assertNotNull($revision);

        foreach (['change_type' => 'reject', 'changed_by_user_id' => $world['other']->id, 'after_snapshot' => '{}', 'reason' => 'Omskrevet'] as $column => $value) {
            $this->assertRefused(fn () => DB::table('saved_notice_ai_requirement_revisions')->where('id', $revision->id)->update([$column => $value]), $column);
        }

        $this->assertRefused(fn () => DB::table('saved_notice_ai_requirement_revisions')->where('id', $revision->id)->delete(), 'delete');
        $this->assertRefused(fn () => DB::table('saved_notice_ai_requirement_revisions')->where('saved_notice_id', $world['notice']->id)->delete(), 'delete for the case');
    }

    public function test_a_deleted_person_is_nulled_and_the_history_stays(): void
    {
        $world = $this->decidedWorld();
        $person = $world['contributor'];

        $person->delete();

        $this->assertNull(DB::table('saved_notice_status_changes')->where('saved_notice_id', $world['notice']->id)->value('changed_by_user_id'));
        $this->assertNull(DB::table('saved_notice_no_go_decisions')->where('saved_notice_id', $world['noGo']->id)->value('closed_by_user_id'));
        $this->assertSame(0, DB::table('saved_notice_ai_requirement_revisions')->where('changed_by_user_id', $person->id)->count());
        $this->assertGreaterThan(0, DB::table('saved_notice_ai_requirement_revisions')->where('saved_notice_ai_requirement_id', $world['requirement']->id)->count());
    }

    /** The history goes with what it belongs to: deleting a requirement, a case or the customer still works. */
    public function test_the_history_goes_with_its_requirement_its_case_and_its_customer(): void
    {
        $world = $this->decidedWorld();

        $world['requirement']->delete();
        $this->assertSame(0, DB::table('saved_notice_ai_requirement_revisions')->where('saved_notice_ai_requirement_id', $world['requirement']->id)->count());

        $world['noGo']->delete();
        $this->assertSame(0, DB::table('saved_notice_no_go_decisions')->where('saved_notice_id', $world['noGo']->id)->count());
        $this->assertSame(0, DB::table('saved_notice_status_changes')->where('saved_notice_id', $world['noGo']->id)->count());

        $customerId = $world['customer']->id;
        DB::table('customers')->where('id', $customerId)->delete();
        $this->assertSame(0, DB::table('saved_notices')->where('customer_id', $customerId)->count());
        $this->assertSame(0, DB::table('saved_notice_status_changes')->where('customer_id', $customerId)->count());
    }

    /** Deleting a whole case takes its requirements and their revisions along in one statement. */
    public function test_deleting_a_case_takes_its_requirements_and_their_revisions_along(): void
    {
        $world = $this->decidedWorld();
        $this->assertGreaterThan(0, DB::table('saved_notice_ai_requirement_revisions')->where('saved_notice_id', $world['notice']->id)->count());

        DB::table('saved_notices')->where('id', $world['notice']->id)->delete();

        $this->assertSame(0, DB::table('saved_notice_ai_requirement_revisions')->where('saved_notice_id', $world['notice']->id)->count());
        $this->assertSame(0, DB::table('saved_notice_status_changes')->where('saved_notice_id', $world['notice']->id)->count());
    }

    private function assertRefused(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("The database accepted a statement it should refuse: {$what}");
        } catch (QueryException) {
            // refused, as it should be
        } finally {
            DB::rollBack();
        }
    }

    /**
     * A case taken through two status changes and a requirement decided, and a second case closed
     * as No-Go — all through the product's own services.
     *
     * @return array<string, mixed>
     */
    private function decidedWorld(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Historikk AS',
            'slug' => 'historikk-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $person = fn (string $label, string $bidRole, string $role = User::ROLE_USER): User => User::factory()->create([
            'name' => $label,
            'email' => Str::slug($label).'.'.Str::lower(Str::random(6)).'@example.test',
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $owner = $person('Eier', User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);
        $contributor = $person('Bidragsyter', User::BID_ROLE_CONTRIBUTOR);
        $other = $person('Annen', User::BID_ROLE_CONTRIBUTOR);

        $notice = $this->notice($customer, $owner);
        $noGo = $this->notice($customer, $owner);

        foreach ([$notice, $noGo] as $case) {
            SavedNoticeUserAccess::query()->create([
                'saved_notice_id' => $case->id,
                'user_id' => $contributor->id,
                'granted_by_user_id' => $owner->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ]);
        }

        $status = app(SavedNoticeStatusService::class);
        $status->transition($contributor, $notice, SavedNotice::BID_STATUS_QUALIFYING);
        $status->transition($contributor, $notice, SavedNotice::BID_STATUS_GO_NO_GO);
        $status->transition($contributor, $noGo, SavedNotice::BID_STATUS_NO_GO, SavedNotice::BID_CLOSURE_REASON_CAPACITY, 'Ingen kapasitet.');

        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
            'requirement_text' => 'Leverandøren skal ha beredskapsplan.',
            'original_requirement_text' => 'Leverandøren skal ha beredskapsplan.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);
        app(RequirementEditorService::class)->approveRequirement($requirement, $contributor);

        return compact('customer', 'owner', 'contributor', 'other', 'notice', 'noGo', 'requirement');
    }

    private function notice(Customer $customer, User $savedBy): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $savedBy->id,
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'HIST-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'external_url' => 'https://doffin.no/notices/hist',
            'summary' => 'Kort',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);
    }
}
