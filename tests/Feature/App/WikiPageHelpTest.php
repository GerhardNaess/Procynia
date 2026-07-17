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

        app()->setLocale('en');
        $this->assertSame('How Wiki pages work', trans('procynia.wiki.page_help_title'));
        $this->assertSame('Awaiting sync', trans('procynia.wiki.page_help_item_status_sync_title'));
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
