<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_is_accessible_without_login(): void
    {
        $this->referenceLanguageAndNationality();

        $response = $this->get('/registrer');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'Public/Register';
        });
    }

    public function test_registration_page_exposes_form_options_and_links(): void
    {
        $this->referenceLanguageAndNationality();

        $response = $this->get('/registrer');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            if (data_get($page, 'component') !== 'Public/Register') {
                return false;
            }

            $languages = data_get($page, 'props.publicRegistration.languages');
            $nationalities = data_get($page, 'props.publicRegistration.nationalities');

            return is_array($languages)
                && $languages !== []
                && is_array($nationalities)
                && $nationalities !== []
                && data_get($page, 'props.translations.public.register.title') !== null
                && data_get($page, 'props.translations.public.register.lead') !== null
                && data_get($page, 'props.translations.public.registration.form.title') !== null
                && data_get($page, 'props.translations.public.registration.form.lead') !== null
                && data_get($page, 'props.translations.public.registration.form.submit') !== null
                && data_get($page, 'props.translations.public.registration.form.privacy_link') !== null
                && data_get($page, 'props.translations.public.registration.form.terms_link') !== null;
        });
    }

    public function test_public_registration_creates_customer_and_first_system_owner(): void
    {
        $payload = $this->validPayload();

        $response = $this->postRegistration($payload);

        $response->assertRedirect(route('app.notices.index', ['mode' => 'saved']));
        $response->assertSessionHas('success', __('procynia.public.registration.success'));
        $this->assertAuthenticated();

        $customer = Customer::query()->where('name', $payload['company_name'])->first();
        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertSame($payload['language_id'], $customer->language_id);
        $this->assertSame($payload['nationality_id'], $customer->nationality_id);
        $this->assertTrue($customer->is_active);
        $this->assertSame(Customer::DEFAULT_PERMISSION_SETTINGS, $customer->permission_settings);

        $user = User::query()->where('email', $payload['owner_email'])->first();
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($customer->id, $user->customer_id);
        $this->assertSame(User::ROLE_CUSTOMER_ADMIN, $user->role);
        $this->assertSame(User::BID_ROLE_SYSTEM_OWNER, $user->bid_role);
        $this->assertSame(User::PRIMARY_AFFILIATION_SCOPE_COMPANY, $user->primary_affiliation_scope);
        $this->assertNull($user->bid_manager_scope);
        $this->assertNull($user->primary_department_id);
        $this->assertNull($user->department_id);
        $this->assertSame($payload['language_id'], $user->preferred_language_id);
        $this->assertSame($payload['nationality_id'], $user->nationality_id);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($payload['password'], $user->password));

        $this->assertDatabaseMissing('subscriptions', [
            'customer_id' => $customer->id,
        ]);
    }

    public function test_private_email_domains_are_rejected_without_creating_records(): void
    {
        $payload = $this->validPayload([
            'owner_email' => 'someone@gmail.com',
        ]);

        $response = $this->postRegistration($payload);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/registrer', (string) $response->headers->get('Location'));
        $this->assertValidationErrors($response, ['owner_email']);

        $this->assertDatabaseMissing('customers', [
            'name' => $payload['company_name'],
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => $payload['owner_email'],
        ]);
    }

    public function test_duplicate_email_is_rejected_without_creating_duplicate_records(): void
    {
        $existing = $this->createReferenceCustomer();

        $payload = $this->validPayload([
            'owner_email' => $existing['owner_email'],
            'company_name' => 'Ny Kunde AS',
        ]);

        $response = $this->postRegistration($payload);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/registrer', (string) $response->headers->get('Location'));
        $this->assertValidationErrors($response, ['owner_email']);

        $this->assertSame(1, User::query()->where('email', $existing['owner_email'])->count());
        $this->assertSame(1, Customer::query()->where('name', $existing['customer_name'])->count());
    }

    public function test_missing_acceptances_are_rejected_without_creating_records(): void
    {
        $payload = $this->validPayload([
            'privacy_accepted' => false,
            'terms_accepted' => false,
            'represents_business' => false,
            'owner_email' => 'missing.acceptance@example.test',
        ]);

        $response = $this->postRegistration($payload);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/registrer', (string) $response->headers->get('Location'));
        $this->assertValidationErrors($response, [
            'privacy_accepted',
            'terms_accepted',
            'represents_business',
        ]);

        $this->assertDatabaseMissing('customers', [
            'name' => $payload['company_name'],
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => $payload['owner_email'],
        ]);
    }

    public function test_registration_is_rate_limited(): void
    {
        $payload = $this->validPayload([
            'privacy_accepted' => false,
            'terms_accepted' => false,
            'represents_business' => false,
            'owner_email' => 'rate.limit@example.test',
        ]);

        Cache::flush();
        $rateLimitIp = '10.99.0.1';

        RateLimiter::clear($this->publicRegistrationRateLimitKey($payload['owner_email'], $rateLimitIp));
        RateLimiter::clear($this->publicRegistrationRateLimitKey('anonymous', $rateLimitIp));

        for ($i = 0; $i < 5; $i++) {
            $firstResponse = $this->postRegistration($payload, $rateLimitIp);

            $this->assertSame(302, $firstResponse->getStatusCode());
            $this->assertStringEndsWith('/registrer', (string) $firstResponse->headers->get('Location'));
            $this->assertValidationErrors($firstResponse, [
                'privacy_accepted',
                'terms_accepted',
                'represents_business',
            ]);
        }

        $this->postRegistration($payload)->assertTooManyRequests();
    }

    public function test_guest_app_root_still_redirects_to_login(): void
    {
        $response = $this->get('/app');

        $response->assertRedirect(route('login'));
    }

    private function postRegistration(array $payload, ?string $remoteIp = null)
    {
        $token = 'test-token';

        $request = $this->from('/registrer');

        if ($remoteIp !== null) {
            $request = $request->withServerVariables([
                'REMOTE_ADDR' => $remoteIp,
            ]);
        }

        return $request
            ->withSession(['_token' => $token])
            ->post(route('public.register.store'), ['_token' => $token, ...$payload]);
    }

    private function publicRegistrationRateLimitKey(string $ownerEmail, string $remoteIp = '127.0.0.1'): string
    {
        return sprintf('%s|%s', $remoteIp, strtolower(trim($ownerEmail)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        [$language, $nationality] = $this->referenceLanguageAndNationality();

        return array_merge([
            'company_name' => 'Acme AS',
            'owner_name' => 'Første Systemeier',
            'owner_email' => 'system.owner@acme.test',
            'password' => 'StrongPass123!',
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'privacy_accepted' => true,
            'terms_accepted' => true,
            'represents_business' => true,
        ], $overrides);
    }

    /**
     * @return array{0: Language, 1: Nationality}
     */
    private function referenceLanguageAndNationality(): array
    {
        $language = Language::query()->firstOrCreate([
            'code' => 'no',
        ], [
            'name_en' => 'Norwegian',
            'name_no' => 'Norsk',
        ]);
        $nationality = Nationality::query()->firstOrCreate([
            'code' => 'NO',
        ], [
            'name_en' => 'Norwegian',
            'name_no' => 'Norsk',
            'flag_emoji' => 'NO',
        ]);

        return [$language, $nationality];
    }

    /**
     * @return array{customer_name: string, owner_email: string}
     */
    private function createReferenceCustomer(): array
    {
        [$language, $nationality] = $this->referenceLanguageAndNationality();

        $customer = Customer::query()->create([
            'name' => 'Eksisterende Kunde AS',
            'slug' => 'eksisterende-kunde-as',
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'permission_settings' => Customer::DEFAULT_PERMISSION_SETTINGS,
        ]);

        $ownerEmail = 'existing.system.owner@example.test';

        User::query()->create([
            'name' => 'Eksisterende Systemeier',
            'email' => $ownerEmail,
            'password' => 'StrongPass123!',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'bid_manager_scope' => null,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => null,
            'department_id' => null,
            'nationality_id' => $nationality->id,
            'preferred_language_id' => $language->id,
            'is_active' => true,
            'customer_id' => $customer->id,
        ]);

        return [
            'customer_name' => $customer->name,
            'owner_email' => $ownerEmail,
        ];
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function assertValidationErrors(mixed $response, array $fields): void
    {
        $errors = $response->baseResponse->getSession()->get('errors');

        foreach ($fields as $field) {
            $messages = $this->extractValidationMessages($errors, $field);

            $this->assertNotEmpty($messages, "Failed asserting that validation errors contain '{$field}'.");
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractValidationMessages(mixed $errors, string $field): array
    {
        if ($errors instanceof ViewErrorBag) {
            return $this->extractValidationMessages($errors->getBag('default')->getMessages(), $field);
        }

        if (is_object($errors) && method_exists($errors, 'toArray')) {
            return $this->extractValidationMessages($errors->toArray(), $field);
        }

        if (! is_array($errors)) {
            return [];
        }

        if (array_key_exists($field, $errors) && is_array($errors[$field])) {
            return array_values(array_filter($errors[$field], static fn ($message): bool => is_string($message) && $message !== ''));
        }

        foreach ($errors as $value) {
            if (! is_array($value)) {
                continue;
            }

            $messages = $this->extractValidationMessages($value, $field);

            if ($messages !== []) {
                return $messages;
            }
        }

        return [];
    }
}
