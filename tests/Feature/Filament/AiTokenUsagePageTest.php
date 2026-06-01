<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiTokenUsage;
use App\Models\AiTokenEvent;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTokenUsagePageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_internal_super_admin_can_access_ai_token_usage_page(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)->get(AiTokenUsage::getUrl());

        $response->assertOk();
        $response->assertSee('AI-tokenforbruk');
        $response->assertSee('Intern Procynia-visning');
    }

    public function test_customer_admin_cannot_access_ai_token_usage_page(): void
    {
        $customer = $this->createCustomer('Kunde uten tilgang');
        $user = User::factory()->create([
            'role'        => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active'   => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(AiTokenUsage::canAccess());
    }

    public function test_regular_user_cannot_access_ai_token_usage_page(): void
    {
        $customer = $this->createCustomer('Vanlig kundekunde');
        $user = User::factory()->create([
            'role'        => User::ROLE_USER,
            'customer_id' => $customer->id,
            'is_active'   => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(AiTokenUsage::canAccess());
    }

    public function test_super_admin_with_customer_id_cannot_access_page(): void
    {
        $customer = $this->createCustomer('Feil super admin');
        $user = User::factory()->create([
            'role'        => User::ROLE_SUPER_ADMIN,
            'customer_id' => $customer->id,
            'is_active'   => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(AiTokenUsage::canAccess());
    }

    public function test_page_shows_aggregated_token_data_per_customer(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $admin = $this->internalAdmin();
        $customerA = $this->createCustomer('Kunde Alpha');
        $customerB = $this->createCustomer('Kunde Beta');
        $userA = $this->createUser($customerA);
        $userB = $this->createUser($customerB);

        $this->createTokenEvent($customerA, $userA, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 100, 40, 140);
        $this->createTokenEvent($customerA, $userA, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 200, 80, 280);
        $this->createTokenEvent($customerB, $userB, 'saved_notice_requirement_answer_draft', 'gpt-5', 500, 200, 700);

        $response = $this->actingAs($admin)->get(AiTokenUsage::getUrl());

        $response->assertOk();
        $response->assertSee('Kunde Alpha');
        $response->assertSee('Kunde Beta');
    }

    public function test_customer_a_tokens_do_not_mix_with_customer_b(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $customerA = $this->createCustomer('Isolert Kunde A');
        $customerB = $this->createCustomer('Isolert Kunde B');
        $userA = $this->createUser($customerA);
        $userB = $this->createUser($customerB);

        $this->createTokenEvent($customerA, $userA, 'draft', 'gpt-4.1-mini', 1000, 0, 1000);
        $this->createTokenEvent($customerB, $userB, 'draft', 'gpt-4.1-mini', 5000, 0, 5000);

        $tokenSumA = AiTokenEvent::query()
            ->where('customer_id', $customerA->id)
            ->sum('total_tokens');

        $tokenSumB = AiTokenEvent::query()
            ->where('customer_id', $customerB->id)
            ->sum('total_tokens');

        $this->assertSame(1000, (int) $tokenSumA, 'Kunde A sine tokens skal ikke inkludere Kunde B.');
        $this->assertSame(5000, (int) $tokenSumB, 'Kunde B sine tokens skal ikke inkludere Kunde A.');
    }

    public function test_token_sum_is_correctly_aggregated(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $customer = $this->createCustomer('Aggregert Kunde');
        $user = $this->createUser($customer);

        $this->createTokenEvent($customer, $user, 'draft', 'gpt-4.1-mini', 100, 40, 140);
        $this->createTokenEvent($customer, $user, 'draft', 'gpt-4.1-mini', 200, 80, 280);
        $this->createTokenEvent($customer, $user, 'draft', 'gpt-4.1-mini', 50, 20, 70);

        $inputSum  = (int) AiTokenEvent::query()->where('customer_id', $customer->id)->sum('input_tokens');
        $outputSum = (int) AiTokenEvent::query()->where('customer_id', $customer->id)->sum('output_tokens');
        $totalSum  = (int) AiTokenEvent::query()->where('customer_id', $customer->id)->sum('total_tokens');

        $this->assertSame(350, $inputSum);
        $this->assertSame(140, $outputSum);
        $this->assertSame(490, $totalSum);
    }

    public function test_operation_key_grouping_gives_correct_sums(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $customer = $this->createCustomer('Operasjonskunde');
        $user = $this->createUser($customer);

        $this->createTokenEvent($customer, $user, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 100, 40, 140);
        $this->createTokenEvent($customer, $user, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 200, 80, 280);
        $this->createTokenEvent($customer, $user, 'knowledge_document_upload', 'gpt-4.1-mini', 300, 60, 360);

        $draftSum = (int) AiTokenEvent::query()
            ->where('operation_key', 'saved_notice_requirement_answer_draft')
            ->sum('total_tokens');

        $knowledgeSum = (int) AiTokenEvent::query()
            ->where('operation_key', 'knowledge_document_upload')
            ->sum('total_tokens');

        $this->assertSame(420, $draftSum);
        $this->assertSame(360, $knowledgeSum);
    }

    public function test_model_grouping_gives_correct_sums(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $customer = $this->createCustomer('Modellkunde');
        $user = $this->createUser($customer);

        $this->createTokenEvent($customer, $user, 'draft', 'gpt-4.1-mini', 100, 40, 140);
        $this->createTokenEvent($customer, $user, 'draft', 'gpt-4.1-mini', 200, 80, 280);
        $this->createTokenEvent($customer, $user, 'draft', 'gpt-5', 500, 200, 700);

        $miniSum = (int) AiTokenEvent::query()->where('model', 'gpt-4.1-mini')->sum('total_tokens');
        $gpt5Sum = (int) AiTokenEvent::query()->where('model', 'gpt-5')->sum('total_tokens');

        $this->assertSame(420, $miniSum);
        $this->assertSame(700, $gpt5Sum);
    }

    public function test_page_handles_empty_data_without_errors(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)->get(AiTokenUsage::getUrl());

        $response->assertOk();
        $response->assertSee('Ingen token-events registrert');
    }

    public function test_events_from_other_months_are_excluded(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $customer = $this->createCustomer('Månedkunde');
        $user = $this->createUser($customer);

        $this->createTokenEventAt($customer, $user, 'draft', 'gpt-4.1-mini', 100, 40, 140, '2026-05-10 12:00:00');
        $this->createTokenEventAt($customer, $user, 'draft', 'gpt-4.1-mini', 200, 80, 280, '2026-06-10 12:00:00');

        $juneCount = AiTokenEvent::query()
            ->where('customer_id', $customer->id)
            ->whereBetween('created_at', [
                Carbon::create(2026, 6, 1)->startOfMonth(),
                Carbon::create(2026, 6, 1)->endOfMonth(),
            ])
            ->count();

        $this->assertSame(1, $juneCount, 'Kun juni-events skal telles for juni.');
    }

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'name'        => 'Internal Super Admin',
            'email'       => 'super.admin@example.test',
            'role'        => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active'   => true,
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
            'name'            => $name,
            'slug'            => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'nationality_id'  => $nationality->id,
            'language_id'     => $language->id,
            'is_active'       => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::factory()->create([
            'role'        => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active'   => true,
        ]);
    }

    private function createTokenEvent(
        Customer $customer,
        User $user,
        string $operationKey,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $totalTokens,
    ): void {
        AiTokenEvent::query()->create([
            'customer_id'   => $customer->id,
            'user_id'       => $user->id,
            'operation_key' => $operationKey,
            'model'         => $model,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens'  => $totalTokens,
        ]);
    }

    private function createTokenEventAt(
        Customer $customer,
        User $user,
        string $operationKey,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $totalTokens,
        string $timestamp,
    ): void {
        $originalNow = Carbon::getTestNow();
        Carbon::setTestNow($timestamp);

        try {
            $this->createTokenEvent($customer, $user, $operationKey, $model, $inputTokens, $outputTokens, $totalTokens);
        } finally {
            Carbon::setTestNow($originalNow);
        }
    }
}
