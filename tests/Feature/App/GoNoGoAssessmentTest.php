<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\GoNoGoAssessment;
use App\Models\GoNoGoAssessmentCriterion;
use App\Models\GoNoGoAssessmentTemplate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoNoGoAssessmentTest extends TestCase
{
    private bool $createdSavedNoticesTable = false;

    private bool $createdTemplatesTable = false;

    private bool $createdCriteriaTable = false;

    private bool $createdAssessmentsTable = false;

    private bool $createdAnswersTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->ensureSavedNoticesTable();
        $this->ensureTemplatesTable();
        $this->ensureCriteriaTable();
        $this->ensureAssessmentsTable();
        $this->ensureAnswersTable();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($this->createdAnswersTable) {
            Schema::dropIfExists('go_no_go_assessment_answers');
        }

        if ($this->createdAssessmentsTable) {
            Schema::dropIfExists('go_no_go_assessments');
        }

        if ($this->createdCriteriaTable) {
            Schema::dropIfExists('go_no_go_assessment_criteria');
        }

        if ($this->createdTemplatesTable) {
            Schema::dropIfExists('go_no_go_assessment_templates');
        }

        if ($this->createdSavedNoticesTable) {
            Schema::dropIfExists('saved_notices');
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_user_can_save_assessment_answers(): void
    {
        $ctx = $this->systemOwnerContext();
        [$template, $criteria] = $this->createTemplate($ctx['customer']->id, 3);
        $notice = $this->createSavedNotice($ctx['customer']->id, 'test-notice-save');

        $answers = array_map(fn (GoNoGoAssessmentCriterion $c): array => [
            'criterion_id' => $c->id,
            'selected_value' => 'middels',
            'comment' => '',
        ], $criteria);

        $response = $this->actingAs($ctx['owner'])
            ->patch("/app/notices/saved/{$notice->id}/go-no-go-assessment", [
                'template_id' => $template->id,
                'answers' => $answers,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('go_no_go_assessments', [
            'saved_notice_id' => $notice->id,
            'template_id' => $template->id,
            'customer_id' => $ctx['customer']->id,
        ]);

        foreach ($criteria as $criterion) {
            $this->assertDatabaseHas('go_no_go_assessment_answers', [
                'criterion_id' => $criterion->id,
                'selected_value' => 'middels',
            ]);
        }
    }

    public function test_score_is_computed_correctly_using_weight(): void
    {
        $ctx = $this->systemOwnerContext();
        $template = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $ctx['customer']->id,
            'name' => 'Scoring Test',
            'is_default' => true,
            'is_active' => true,
        ]);

        $criterion = GoNoGoAssessmentCriterion::query()->create([
            'template_id' => $template->id,
            'title' => 'Vektet kriterium',
            'weight' => 3,
            'is_score_reversed' => false,
            'sort_order' => 1,
        ]);

        // hoy = base 3 × weight 3 = score 9
        $score = $criterion->computeScore('hoy');
        $this->assertSame(9, $score);

        // middels = base 2 × weight 3 = score 6
        $score = $criterion->computeScore('middels');
        $this->assertSame(6, $score);

        // lav = base 1 × weight 3 = score 3
        $score = $criterion->computeScore('lav');
        $this->assertSame(3, $score);
    }

    public function test_reversed_criterion_inverts_scores(): void
    {
        $ctx = $this->systemOwnerContext();
        $template = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $ctx['customer']->id,
            'name' => 'Reversed Test',
            'is_default' => true,
            'is_active' => true,
        ]);

        $risiko = GoNoGoAssessmentCriterion::query()->create([
            'template_id' => $template->id,
            'title' => 'Risiko',
            'weight' => 3,
            'is_score_reversed' => true,
            'sort_order' => 1,
        ]);

        // lav risiko = good → (4-1) * 3 = 9
        $this->assertSame(9, $risiko->computeScore('lav'));

        // middels risiko → (4-2) * 3 = 6
        $this->assertSame(6, $risiko->computeScore('middels'));

        // hoy risiko = bad → (4-3) * 3 = 3
        $this->assertSame(3, $risiko->computeScore('hoy'));
    }

    public function test_recommendation_is_go_when_score_75_percent_or_above(): void
    {
        $ctx = $this->systemOwnerContext();
        [$template, $criteria] = $this->createTemplate($ctx['customer']->id, 2, weight: 1);
        $notice = $this->createSavedNotice($ctx['customer']->id, 'test-notice-go');

        // Both hoy (3+3 = 6 out of max 6 = 100%) → go
        $this->actingAs($ctx['owner'])->patch("/app/notices/saved/{$notice->id}/go-no-go-assessment", [
            'template_id' => $template->id,
            'answers' => [
                ['criterion_id' => $criteria[0]->id, 'selected_value' => 'hoy', 'comment' => ''],
                ['criterion_id' => $criteria[1]->id, 'selected_value' => 'hoy', 'comment' => ''],
            ],
        ]);

        $assessment = GoNoGoAssessment::query()
            ->where('saved_notice_id', $notice->id)
            ->first();

        $this->assertSame('go', $assessment->recommendation);
    }

    public function test_recommendation_is_nogo_when_score_below_55_percent(): void
    {
        $ctx = $this->systemOwnerContext();
        [$template, $criteria] = $this->createTemplate($ctx['customer']->id, 2, weight: 1);
        $notice = $this->createSavedNotice($ctx['customer']->id, 'test-notice-nogo');

        // Both lav (1+1 = 2 out of max 6 = 33%) → nogo
        $this->actingAs($ctx['owner'])->patch("/app/notices/saved/{$notice->id}/go-no-go-assessment", [
            'template_id' => $template->id,
            'answers' => [
                ['criterion_id' => $criteria[0]->id, 'selected_value' => 'lav', 'comment' => ''],
                ['criterion_id' => $criteria[1]->id, 'selected_value' => 'lav', 'comment' => ''],
            ],
        ]);

        $assessment = GoNoGoAssessment::query()
            ->where('saved_notice_id', $notice->id)
            ->first();

        $this->assertSame('nogo', $assessment->recommendation);
    }

    public function test_recommendation_is_null_when_not_all_criteria_answered(): void
    {
        $ctx = $this->systemOwnerContext();
        [$template, $criteria] = $this->createTemplate($ctx['customer']->id, 3, weight: 1);
        $notice = $this->createSavedNotice($ctx['customer']->id, 'test-notice-partial');

        // Only 2 of 3 answered
        $this->actingAs($ctx['owner'])->patch("/app/notices/saved/{$notice->id}/go-no-go-assessment", [
            'template_id' => $template->id,
            'answers' => [
                ['criterion_id' => $criteria[0]->id, 'selected_value' => 'hoy', 'comment' => ''],
                ['criterion_id' => $criteria[1]->id, 'selected_value' => 'hoy', 'comment' => ''],
            ],
        ]);

        $assessment = GoNoGoAssessment::query()
            ->where('saved_notice_id', $notice->id)
            ->first();

        $this->assertNull($assessment->recommendation);
        $this->assertNull($assessment->completed_at);
    }

    public function test_template_id_is_locked_at_assessment_creation(): void
    {
        $ctx = $this->systemOwnerContext();
        [$templateA, $criteriaA] = $this->createTemplate($ctx['customer']->id, 1);
        [$templateB] = $this->createTemplate($ctx['customer']->id, 1);
        $notice = $this->createSavedNotice($ctx['customer']->id, 'test-notice-lock');

        // First save with template A
        $this->actingAs($ctx['owner'])->patch("/app/notices/saved/{$notice->id}/go-no-go-assessment", [
            'template_id' => $templateA->id,
            'answers' => [
                ['criterion_id' => $criteriaA[0]->id, 'selected_value' => 'hoy', 'comment' => ''],
            ],
        ]);

        $assessmentId = GoNoGoAssessment::query()
            ->where('saved_notice_id', $notice->id)
            ->value('id');

        // Attempt second save with template B (should be ignored — template locked)
        $this->actingAs($ctx['owner'])->patch("/app/notices/saved/{$notice->id}/go-no-go-assessment", [
            'template_id' => $templateB->id,
            'answers' => [],
        ]);

        $this->assertDatabaseHas('go_no_go_assessments', [
            'id' => $assessmentId,
            'template_id' => $templateA->id,
        ]);
    }

    public function test_user_cannot_save_assessment_for_another_customers_notice(): void
    {
        $ctx = $this->systemOwnerContext('Kunde A AS');
        $other = $this->systemOwnerContext('Kunde B AS');

        [$template, $criteria] = $this->createTemplate($ctx['customer']->id, 1);
        $notice = $this->createSavedNotice($other['customer']->id, 'foreign-notice');

        $response = $this->actingAs($ctx['owner'])
            ->patch("/app/notices/saved/{$notice->id}/go-no-go-assessment", [
                'template_id' => $template->id,
                'answers' => [
                    ['criterion_id' => $criteria[0]->id, 'selected_value' => 'hoy', 'comment' => ''],
                ],
            ]);

        // firstOrFail on cross-customer notice returns 404
        $response->assertNotFound();

        $this->assertDatabaseMissing('go_no_go_assessments', [
            'saved_notice_id' => $notice->id,
        ]);
    }

    private function systemOwnerContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $owner = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }

    /**
     * @return array{0: GoNoGoAssessmentTemplate, 1: list<GoNoGoAssessmentCriterion>}
     */
    private function createTemplate(int $customerId, int $criteriaCount, int $weight = 2): array
    {
        $template = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $customerId,
            'name' => 'Testmal '.Str::random(4),
            'is_default' => false,
            'is_active' => true,
        ]);

        $criteria = [];

        for ($i = 1; $i <= $criteriaCount; $i++) {
            $criteria[] = GoNoGoAssessmentCriterion::query()->create([
                'template_id' => $template->id,
                'title' => "Kriterium {$i}",
                'weight' => $weight,
                'is_score_reversed' => false,
                'sort_order' => $i,
            ]);
        }

        return [$template, $criteria];
    }

    private function createSavedNotice(int $customerId, string $externalId): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customerId,
            'external_id' => $externalId.'-'.Str::random(4),
            'title' => 'Testkunngjøring',
            'buyer_name' => 'Test oppdragsgiver',
            'external_url' => 'https://doffin.no/notices/'.$externalId,
            'summary' => 'En kort beskrivelse',
            'publication_date' => '2026-01-01 00:00:00',
            'deadline' => '2026-06-30 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
            'bid_status' => SavedNotice::BID_STATUS_GO_NO_GO,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
        ]);
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

    private function ensureSavedNoticesTable(): void
    {
        if (! Schema::hasTable('saved_notices')) {
            Schema::create('saved_notices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('saved_by_user_id')->nullable();
                $table->string('bid_status')->default(SavedNotice::BID_STATUS_DISCOVERED);
                $table->string('source_type')->default(SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE);
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
                $table->string('reference_number')->nullable();
                $table->string('contact_person_name')->nullable();
                $table->string('contact_person_email')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('publication_date')->nullable();
                $table->timestamp('deadline')->nullable();
                $table->string('status')->nullable();
                $table->string('cpv_code')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->string('history_type')->nullable()->index();
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
        }
    }

    private function ensureTemplatesTable(): void
    {
        if (! Schema::hasTable('go_no_go_assessment_templates')) {
            Schema::create('go_no_go_assessment_templates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            $this->createdTemplatesTable = true;
        }
    }

    private function ensureCriteriaTable(): void
    {
        if (! Schema::hasTable('go_no_go_assessment_criteria')) {
            Schema::create('go_no_go_assessment_criteria', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')->constrained('go_no_go_assessment_templates')->cascadeOnDelete();
                $table->string('title', 255);
                $table->string('short_description', 500)->nullable();
                $table->text('help_what_is_assessed')->nullable();
                $table->text('help_why_it_matters')->nullable();
                $table->text('help_what_to_investigate')->nullable();
                $table->text('help_positive_indicators')->nullable();
                $table->text('help_warning_signs')->nullable();
                $table->text('help_example_assessment')->nullable();
                $table->unsignedTinyInteger('weight')->default(1);
                $table->boolean('is_score_reversed')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            $this->createdCriteriaTable = true;
        }
    }

    private function ensureAssessmentsTable(): void
    {
        if (! Schema::hasTable('go_no_go_assessments')) {
            Schema::create('go_no_go_assessments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
                $table->foreignId('template_id')->constrained('go_no_go_assessment_templates')->restrictOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('recommendation', 20)->nullable();
                $table->unsignedSmallInteger('total_score')->default(0);
                $table->unsignedSmallInteger('max_score')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['saved_notice_id', 'template_id']);
            });

            $this->createdAssessmentsTable = true;
        }
    }

    private function ensureAnswersTable(): void
    {
        if (! Schema::hasTable('go_no_go_assessment_answers')) {
            Schema::create('go_no_go_assessment_answers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assessment_id')->constrained('go_no_go_assessments')->cascadeOnDelete();
                $table->foreignId('criterion_id')->constrained('go_no_go_assessment_criteria')->cascadeOnDelete();
                $table->string('selected_value', 10);
                $table->unsignedSmallInteger('score')->default(0);
                $table->text('comment')->nullable();
                $table->timestamps();

                $table->unique(['assessment_id', 'criterion_id']);
            });

            $this->createdAnswersTable = true;
        }
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
                $values[trim($envKey)] = trim($envValue, " \t\n\r\0\x0B\"'");
            }
        }

        return (string) ($values[$key] ?? $default);
    }
}
