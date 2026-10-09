<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The shared translations are in the person's language.
 *
 * Inertia's middleware is moved up the priority list to just after the session, so it built the
 * shared props — every page's translations among them — before SetCustomerLocale had run: a person
 * with English as their language got Norwegian everywhere the frontend reads `translations`, while
 * the controller's own __() strings were English. The locale is now set first.
 */
class SharedTranslationsLocaleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_norwegian_and_an_english_person_each_get_their_own_language(): void
    {
        $customer = $this->customer();
        $norwegian = $this->user($customer, 'no');
        $english = $this->user($customer, 'en');

        foreach ([[$norwegian, 'no', 'Mine oppgaver'], [$english, 'en', 'My tasks'], [$norwegian, 'no', 'Mine oppgaver']] as [$user, $locale, $myTasks]) {
            $props = $this->actingAs($user)->get(route('app.info-center.index'))->assertOk()->viewData('page')['props'];

            $this->assertSame($locale, $props['locale']);
            $this->assertSame($myTasks, $props['translations']['info_center_page']['page_help_section_my_tasks']);
            $this->assertSame(__('procynia.frontend.all', [], $locale), $props['translations']['frontend']['all']);
        }
    }

    public function test_the_language_holds_on_every_page_and_never_leaks_between_requests(): void
    {
        $customer = $this->customer();
        $english = $this->user($customer, 'en');
        $norwegian = $this->user($customer, 'no');

        foreach (['app.dashboard', 'app.info-center.index', 'app.customer-environment.index'] as $route) {
            $this->assertSame('en', $this->actingAs($english)->get(route($route))->viewData('page')['props']['locale'], $route);
            $this->assertSame('no', $this->actingAs($norwegian)->get(route($route))->viewData('page')['props']['locale'], $route);
        }
    }

    public function test_the_language_applies_after_a_real_login_and_navigation(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $english = $this->user($this->customer(), 'en');

        $this->post('/login', ['email' => $english->email, 'password' => 'secret'])->assertRedirect();
        $this->assertAuthenticatedAs($english);

        $props = $this->get(route('app.info-center.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame('en', $props['locale']);
        $this->assertSame('My tasks', $props['translations']['info_center_page']['page_help_section_my_tasks']);
    }

    public function test_a_customer_language_applies_when_the_person_has_none(): void
    {
        $customer = $this->customer('en');
        $user = $this->user($customer, null);

        $props = $this->actingAs($user)->get(route('app.info-center.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame('en', $props['locale']);
        $this->assertSame('My tasks', $props['translations']['info_center_page']['page_help_section_my_tasks']);
    }

    private function customer(string $language = 'no'): Customer
    {
        // A Norwegian nationality decides the language before the customer's own, so the English
        // customer is British.
        $nationality = $language === 'no'
            ? Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'])
            : Nationality::query()->firstOrCreate(['code' => 'GB'], ['name_en' => 'British', 'name_no' => 'Britisk', 'flag_emoji' => 'GB']);

        return Customer::query()->create([
            'name' => 'Språk AS',
            'slug' => 'sprak-'.Str::lower(Str::random(8)),
            'language_id' => $this->language($language)->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, ?string $language): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@sprak.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
            'preferred_language_id' => $language !== null ? $this->language($language)->id : null,
        ]);
    }

    private function language(string $code): Language
    {
        return Language::query()->firstOrCreate(['code' => $code], ['name_en' => $code, 'name_no' => $code]);
    }
}
