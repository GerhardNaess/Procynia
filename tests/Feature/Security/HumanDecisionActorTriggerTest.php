<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Phase 0 step 19 (docs/external-api-phase-0-plan.md §4.8, §2m, K5): the database refuses an
 * integration identity in every column that names the person who took a human decision.
 *
 * The list below is written out here on purpose and compared with the catalog: dropping a trigger,
 * a column or a table from the migration — or adding one nobody decided on — fails this test.
 */
class HumanDecisionActorTriggerTest extends TestCase
{
    use UsesProjectPostgresConnection;

    /** @var array<string, list<string>> */
    public const DECISION_COLUMNS = [
        'compliance_assessments' => ['assessed_by_user_id'],
        'compliance_audit_finding_changes' => ['changed_by_user_id'],
        'compliance_audit_status_changes' => ['changed_by_user_id'],
        'compliance_requirement_applicability_changes' => ['changed_by_user_id'],
        'compliance_requirements' => ['applicability_set_by'],
        'customer_access_changes' => ['actor_user_id'],
        'enterprise_wiki_claim_decisions' => ['decided_by_user_id'],
        'enterprise_wiki_claims' => ['approved_by_user_id', 'blocking_override_by_user_id'],
        'enterprise_wiki_document_owner_decisions' => ['decided_by_user_id'],
        'enterprise_wiki_page_review_events' => ['actor_user_id'],
        'enterprise_wiki_page_version_document_owner_approvals' => ['decided_by_user_id', 'overridden_by_user_id'],
        'enterprise_wiki_page_versions' => ['submitted_by_user_id', 'reviewer_user_id', 'qa_assigned_by_user_id'],
        'enterprise_wiki_pages' => ['reviewed_by_user_id'],
        'improvement_action_verifications' => ['verified_by_user_id'],
        'improvement_cases' => ['closed_by_user_id'],
        'kpi_measurements' => ['recorded_by_user_id', 'withdrawn_by_user_id'],
        'kpi_status_changes' => ['changed_by_user_id'],
        'management_review_amendments' => ['created_by_user_id'],
        'management_reviews' => ['finalized_by_user_id'],
        'objective_status_changes' => ['changed_by_user_id'],
        'objectives' => ['closed_by_user_id'],
        'quality_process_blueprints' => ['approved_by_user_id'],
        'quality_process_revisions' => ['approved_by_user_id'],
        'risk_acceptances' => ['accepted_by_user_id', 'revoked_by_user_id'],
        'risk_assessments' => ['assessed_by'],
        'saved_notice_ai_requirements' => ['approved_by_user_id', 'rejected_by_user_id'],
        'saved_notice_no_go_decisions' => ['closed_by_user_id', 'reopened_by_user_id'],
        'saved_notice_status_changes' => ['changed_by_user_id'],
        'supplier_assessments' => ['assessed_by_user_id'],
        'supplier_assurance_decisions' => ['decided_by_user_id'],
        'supplier_control_requirement_status_changes' => ['changed_by_user_id'],
        'supplier_criticality_changes' => ['changed_by_user_id'],
        'supplier_due_diligence_assessments' => ['assessed_by_user_id'],
        'supplier_requirement_evaluations' => ['evaluated_by_user_id'],
        'supplier_requirement_overrides' => ['created_by_user_id'],
        'supplier_status_changes' => ['changed_by_user_id'],
    ];

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

    public function test_every_decision_column_carries_the_trigger_and_no_other_table_does(): void
    {
        $triggers = collect(DB::select(<<<'SQL'
            SELECT c.relname AS table_name, pg_get_triggerdef(t.oid) AS definition, t.tgenabled AS enabled
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE t.tgname = 'human_decision_actor' AND n.nspname = current_schema()
            SQL))->keyBy('table_name');

        $this->assertEqualsCanonicalizing(array_keys(self::DECISION_COLUMNS), $triggers->keys()->all());

        foreach (self::DECISION_COLUMNS as $table => $columns) {
            $list = implode(', ', $columns);
            $arguments = implode(', ', array_map(static fn (string $column): string => "'{$column}'", $columns));

            $this->assertSame(
                "CREATE TRIGGER human_decision_actor BEFORE INSERT OR UPDATE OF {$list} ON public.{$table} FOR EACH ROW EXECUTE FUNCTION reject_integration_decision_actor({$arguments})",
                $triggers[$table]->definition,
                $table,
            );
            // 'O' = fires in normal operation; a disabled trigger is no protection.
            $this->assertSame('O', $triggers[$table]->enabled, $table);

            foreach ($columns as $column) {
                $this->assertTrue(
                    DB::table('information_schema.key_column_usage as k')
                        ->join('information_schema.constraint_column_usage as u', 'u.constraint_name', '=', 'k.constraint_name')
                        ->where('k.table_name', $table)->where('k.column_name', $column)->where('u.table_name', 'users')
                        ->exists(),
                    "{$table}.{$column} must reference users",
                );
            }
        }
    }

    /** The check itself, on a table of its own: only an integration is refused, and only a new value is checked. */
    public function test_only_an_integration_is_refused_and_only_when_it_is_written(): void
    {
        $customer = $this->customer();
        $person = $this->user($customer, User::ROLE_USER);
        $admin = $this->user($customer, User::ROLE_CUSTOMER_ADMIN);
        $integration = $this->user($customer, User::ROLE_INTEGRATION);

        DB::statement('CREATE TEMPORARY TABLE decision_probe (id bigserial PRIMARY KEY, decided_by bigint, note text)');
        DB::statement("CREATE TRIGGER human_decision_actor BEFORE INSERT OR UPDATE OF decided_by ON decision_probe FOR EACH ROW EXECUTE FUNCTION reject_integration_decision_actor('decided_by')");

        $this->assertRefused(fn () => DB::table('decision_probe')->insert(['decided_by' => $integration->id]), 'insert integration');

        $id = DB::table('decision_probe')->insertGetId(['decided_by' => $person->id]);
        DB::table('decision_probe')->insert(['decided_by' => $admin->id]);
        DB::table('decision_probe')->insert(['decided_by' => null]);

        $this->assertRefused(fn () => DB::table('decision_probe')->where('id', $id)->update(['decided_by' => $integration->id]), 'update to integration');
        DB::table('decision_probe')->where('id', $id)->update(['decided_by' => null]);

        // A row that already names an integration (written before the trigger) keeps working for
        // everything else, and the unchanged actor is not re-checked.
        DB::statement('ALTER TABLE decision_probe DISABLE TRIGGER human_decision_actor');
        $old = DB::table('decision_probe')->insertGetId(['decided_by' => $integration->id]);
        DB::statement('ALTER TABLE decision_probe ENABLE TRIGGER human_decision_actor');
        DB::table('decision_probe')->where('id', $old)->update(['note' => 'ok', 'decided_by' => $integration->id]);
        $this->assertSame('ok', DB::table('decision_probe')->where('id', $old)->value('note'));

        $this->assertSame(4, DB::table('decision_probe')->count());
    }

    /** On real tables: a tender requirement decision, a Wiki review and the access trail. */
    public function test_real_decision_tables_refuse_an_integration_and_keep_working_for_people(): void
    {
        $customer = $this->customer();
        $person = $this->user($customer, User::ROLE_USER);
        $integration = $this->user($customer, User::ROLE_INTEGRATION);

        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $this->savedNotice($customer)->id,
            'requirement_text' => 'Leverandøren skal ha ISO 27001.',
            'requirement_type' => 'requirement',
            'extraction_method' => 'manual',
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
        ]);
        $this->assertRefused(fn () => DB::table('saved_notice_ai_requirements')->where('id', $requirement->id)->update(['approved_by_user_id' => $integration->id]), 'requirement approved by integration');
        $this->assertRefused(fn () => DB::table('saved_notice_ai_requirements')->where('id', $requirement->id)->update(['rejected_by_user_id' => $integration->id]), 'requirement rejected by integration');
        DB::table('saved_notice_ai_requirements')->where('id', $requirement->id)->update(['approved_by_user_id' => $person->id]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'side-'.Str::lower(Str::random(6)),
            'title' => 'Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        $this->assertRefused(fn () => DB::table('enterprise_wiki_pages')->where('id', $page->id)->update(['reviewed_by_user_id' => $integration->id]), 'page reviewed by integration');
        DB::table('enterprise_wiki_pages')->where('id', $page->id)->update(['reviewed_by_user_id' => $person->id]);

        $trail = ['customer_id' => $customer->id, 'subject_type' => 'user', 'subject_id' => $person->id, 'change_type' => 'updated', 'created_at' => now()];
        $this->assertRefused(fn () => DB::table('customer_access_changes')->insert($trail + ['actor_user_id' => $integration->id]), 'access change by integration');
        DB::table('customer_access_changes')->insert($trail + ['actor_user_id' => $person->id]);

        // Deleting the person nulls their decisions (nullOnDelete) — the trigger lets NULL through.
        $person->delete();
        $this->assertSame(1, DB::table('customer_access_changes')->where('subject_id', $person->id)->whereNull('actor_user_id')->count());
        $this->assertNull(DB::table('saved_notice_ai_requirements')->where('id', $requirement->id)->value('approved_by_user_id'));
        $this->assertNull(DB::table('enterprise_wiki_pages')->where('id', $page->id)->value('reviewed_by_user_id'));
    }

    /** §6.1 punkt 5: the trigger never stands in the way of deleting a customer and its decisions. */
    public function test_deleting_the_customer_still_takes_its_decisions_along(): void
    {
        $customer = $this->customer();
        $person = $this->user($customer, User::ROLE_USER);
        $this->user($customer, User::ROLE_INTEGRATION);

        $savedNotice = $this->savedNotice($customer);
        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'requirement_text' => 'Leverandøren skal ha ISO 9001.',
            'requirement_type' => 'requirement',
            'extraction_method' => 'manual',
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
        ]);
        DB::table('saved_notice_ai_requirements')->where('id', $requirement->id)->update(['approved_by_user_id' => $person->id]);
        DB::table('customer_access_changes')->insert([
            'customer_id' => $customer->id, 'subject_type' => 'user', 'subject_id' => $person->id,
            'change_type' => 'updated', 'actor_user_id' => $person->id, 'created_at' => now(),
        ]);

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('saved_notices', ['id' => $savedNotice->id]);
        $this->assertDatabaseMissing('saved_notice_ai_requirements', ['id' => $requirement->id]);
        $this->assertDatabaseMissing('customer_access_changes', ['customer_id' => $customer->id]);
    }

    private function assertRefused(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("The database accepted: {$what}");
        } catch (QueryException $e) {
            $this->assertStringContainsString('An integration cannot be recorded as the person who took a human decision', $e->getMessage(), $what);
        } finally {
            DB::rollBack();
        }
    }

    private function savedNotice(Customer $customer): SavedNotice
    {
        $reference = Str::upper(Str::random(10));

        Notice::query()->firstOrCreate(
            ['notice_id' => $reference],
            ['title' => 'Kunngjøring '.$reference, 'source' => 'doffin'],
        );

        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => $reference,
            'title' => 'Anskaffelse '.$reference,
            'buyer_name' => 'Testetaten',
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);
    }

    private function user(Customer $customer, string $role): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@trigger.test',
            'password' => bcrypt('secret'),
            'role' => $role,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ])->save();

        return $user;
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Beslutningsaktør AS',
            'slug' => 'beslutningsaktor-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
