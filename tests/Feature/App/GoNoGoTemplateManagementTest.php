<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\GoNoGoAssessmentCriterion;
use App\Models\GoNoGoAssessmentTemplate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class GoNoGoTemplateManagementTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private bool $createdTemplatesTable = false;

    private bool $createdCriteriaTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->ensureTemplatesTable();
        $this->ensureCriteriaTable();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($this->createdCriteriaTable) {
            Schema::dropIfExists('go_no_go_assessment_criteria');
        }

        if ($this->createdTemplatesTable) {
            Schema::dropIfExists('go_no_go_assessment_templates');
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_system_owner_can_access_templates_index_and_default_is_auto_created(): void
    {
        $ctx = $this->systemOwnerContext();

        $response = $this->actingAs($ctx['owner'])->get('/app/go-no-go-templates');

        $response->assertOk();

        $this->assertDatabaseHas('go_no_go_assessment_templates', [
            'customer_id' => $ctx['customer']->id,
            'is_default' => true,
        ]);
    }

    public function test_non_system_owner_is_forbidden_from_templates(): void
    {
        $ctx = $this->contributorContext();

        $this->actingAs($ctx['user'])->get('/app/go-no-go-templates')->assertForbidden();
    }

    public function test_system_owner_can_create_a_new_template(): void
    {
        $ctx = $this->systemOwnerContext();

        $response = $this->actingAs($ctx['owner'])->post('/app/go-no-go-templates', [
            'name' => 'Min testmal',
            'description' => 'En beskrivelse',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('go_no_go_assessment_templates', [
            'customer_id' => $ctx['customer']->id,
            'name' => 'Min testmal',
        ]);
    }

    public function test_system_owner_cannot_create_template_for_another_customer(): void
    {
        $ctx = $this->systemOwnerContext();
        $other = $this->createCustomer('Annen Kunde AS');

        $this->actingAs($ctx['owner'])->post('/app/go-no-go-templates', [
            'name' => 'Innbrudd',
        ]);

        $this->assertDatabaseMissing('go_no_go_assessment_templates', [
            'customer_id' => $other->id,
            'name' => 'Innbrudd',
        ]);
    }

    public function test_system_owner_can_toggle_template_active(): void
    {
        $ctx = $this->systemOwnerContext();

        $template = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $ctx['customer']->id,
            'name' => 'Togglebar mal',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($ctx['owner'])->patch("/app/go-no-go-templates/{$template->id}/toggle-active");

        $response->assertRedirect();
        $this->assertDatabaseHas('go_no_go_assessment_templates', [
            'id' => $template->id,
            'is_active' => false,
        ]);
    }

    public function test_default_active_template_cannot_be_deactivated(): void
    {
        $ctx = $this->systemOwnerContext();

        $defaultTemplate = GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $ctx['customer']->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($defaultTemplate === null) {
            $defaultTemplate = GoNoGoAssessmentTemplate::query()->create([
                'customer_id' => $ctx['customer']->id,
                'name' => 'Standard',
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        $this->actingAs($ctx['owner'])->patch("/app/go-no-go-templates/{$defaultTemplate->id}/toggle-active");

        $this->assertDatabaseHas('go_no_go_assessment_templates', [
            'id' => $defaultTemplate->id,
            'is_active' => true,
        ]);
    }

    public function test_system_owner_can_set_another_template_as_default(): void
    {
        $ctx = $this->systemOwnerContext();

        $first = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $ctx['customer']->id,
            'name' => 'Mal 1',
            'is_default' => true,
            'is_active' => true,
        ]);

        $second = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $ctx['customer']->id,
            'name' => 'Mal 2',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($ctx['owner'])->patch("/app/go-no-go-templates/{$second->id}/set-default");

        $response->assertRedirect();

        $this->assertDatabaseHas('go_no_go_assessment_templates', ['id' => $second->id, 'is_default' => true]);
        $this->assertDatabaseHas('go_no_go_assessment_templates', ['id' => $first->id, 'is_default' => false]);
    }

    public function test_system_owner_cannot_access_another_customers_template(): void
    {
        $ctx = $this->systemOwnerContext();
        $other = $this->createCustomer('Fremmed Kunde AS');

        $foreignTemplate = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $other->id,
            'name' => 'Fremmed mal',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($ctx['owner'])
            ->get("/app/go-no-go-templates/{$foreignTemplate->id}/edit")
            ->assertForbidden();
    }

    public function test_system_owner_can_add_criterion_to_template(): void
    {
        $ctx = $this->systemOwnerContext();

        $template = GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $ctx['customer']->id,
            'name' => 'Mal med kriterier',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($ctx['owner'])->post(
            "/app/go-no-go-templates/{$template->id}/criteria",
            [
                'title' => 'Strategisk relevans',
                'weight' => 2,
                'sort_order' => 1,
                'is_score_reversed' => false,
            ],
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('go_no_go_assessment_criteria', [
            'template_id' => $template->id,
            'title' => 'Strategisk relevans',
            'weight' => 2,
        ]);
    }

    public function test_default_template_is_seeded_with_nine_criteria(): void
    {
        $ctx = $this->systemOwnerContext();

        $this->actingAs($ctx['owner'])->get('/app/go-no-go-templates');

        $defaultTemplate = GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $ctx['customer']->id)
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($defaultTemplate);

        $criteriaCount = GoNoGoAssessmentCriterion::query()
            ->where('template_id', $defaultTemplate->id)
            ->count();

        $this->assertSame(9, $criteriaCount);
    }

    public function test_risiko_criterion_has_reversed_scoring(): void
    {
        $ctx = $this->systemOwnerContext();

        $this->actingAs($ctx['owner'])->get('/app/go-no-go-templates');

        $defaultTemplate = GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $ctx['customer']->id)
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($defaultTemplate);

        $risiko = GoNoGoAssessmentCriterion::query()
            ->where('template_id', $defaultTemplate->id)
            ->where('title', 'Risiko')
            ->first();

        $this->assertNotNull($risiko);
        $this->assertTrue((bool) $risiko->is_score_reversed);
        $this->assertSame(3, (int) $risiko->weight);
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

    private function contributorContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'user' => $user];
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
                $table->index('customer_id');
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
                $table->index(['template_id', 'sort_order']);
            });

            $this->createdCriteriaTable = true;
        }
    }
}
