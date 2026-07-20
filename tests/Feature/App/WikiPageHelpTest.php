<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class WikiPageHelpTest extends TestCase
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

    public function test_wiki_index_exposes_page_help_translation_keys(): void
    {
        $locale = app()->getLocale();
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/Wiki/Index'
                && data_get($page, 'props.active_tab') === 'pages';
        });

        $this->assertSame('Slik fungerer Wiki-sider', trans('procynia.wiki.page_help_title'));
        $this->assertSame('Avventer synkronisering', trans('procynia.wiki.page_help_item_status_sync_title'));
        $this->assertSame('Verifikasjonsgrunnlag', trans('procynia.wiki.page_help_section_verification'));

        app()->setLocale('en');
        $this->assertSame('How Wiki pages work', trans('procynia.wiki.page_help_title'));
        $this->assertSame('Awaiting sync', trans('procynia.wiki.page_help_item_status_sync_title'));
        $this->assertSame('Verification basis', trans('procynia.wiki.page_help_section_verification'));
        app()->setLocale($locale);
    }

    public function test_wiki_sources_tab_exposes_sources_page_help_translation_keys(): void
    {
        $locale = app()->getLocale();
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/Wiki/Index'
                && data_get($page, 'props.active_tab') === 'sources';
        });

        $this->assertSame('Slik fungerer Kildedokumenter', trans('procynia.wiki.sources_page_help_title'));
        $this->assertSame('Faglig grunnlag for Wiki', trans('procynia.wiki.sources_page_help_item_what_title'));
        $this->assertSame(
            '«Slett dokument og generert Wiki-innhold»',
            trans('procynia.wiki.sources_page_help_item_delete_full_title'),
        );
        $this->assertStringContainsString(
            'kan ikke angres',
            trans('procynia.wiki.sources_page_help_item_delete_full_text'),
        );
        $this->assertStringContainsString(
            'Wiki-kjøring pågår',
            trans('procynia.wiki.sources_page_help_item_delete_full_text'),
        );

        app()->setLocale('en');
        $this->assertSame('How source documents work', trans('procynia.wiki.sources_page_help_title'));
        $this->assertSame('Grounding for Wiki content', trans('procynia.wiki.sources_page_help_item_what_title'));
        $this->assertSame(
            '"Delete document and generated Wiki content"',
            trans('procynia.wiki.sources_page_help_item_delete_full_title'),
        );
        app()->setLocale($locale);
    }

    public function test_wiki_runs_tab_exposes_runs_page_help_translation_keys(): void
    {
        $locale = app()->getLocale();
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/Wiki/Index'
                && data_get($page, 'props.active_tab') === 'runs';
        });

        $this->assertSame('Slik fungerer Kjøringer', trans('procynia.wiki.runs_page_help_title'));
        $this->assertSame('Venter', trans('procynia.wiki.runs_page_help_item_status_queued_title'));

        app()->setLocale('en');
        $this->assertSame('How runs work', trans('procynia.wiki.runs_page_help_title'));
        $this->assertSame('Pending', trans('procynia.wiki.runs_page_help_item_status_queued_title'));
        app()->setLocale($locale);
    }

    public function test_wiki_quality_tab_exposes_quality_page_help_translation_keys(): void
    {
        $locale = app()->getLocale();
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/Wiki/Index'
                && data_get($page, 'props.active_tab') === 'quality';
        });

        $this->assertSame('Slik fungerer Kvalitet', trans('procynia.wiki.quality_page_help_title'));
        $this->assertSame('Åpne kvalitetsfunn', trans('procynia.wiki.quality_row_open'));

        app()->setLocale('en');
        $this->assertSame('How quality works', trans('procynia.wiki.quality_page_help_title'));
        $this->assertSame('Open quality finding', trans('procynia.wiki.quality_row_open'));
        app()->setLocale($locale);
    }

    private function createCustomer(string $name = 'Testkunde AS'): Customer
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
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
