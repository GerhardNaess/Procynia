<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReadsMyTasks;
use Tests\TestCase;

/**
 * Oppfølging is a Procynia-wide page, not an Anbud page: whichever modules a customer holds, «Mine
 * oppgaver» shows the work from exactly those modules, the bell shows exactly their notifications, and
 * nothing from a module the customer does not hold reaches the page — not as a task, not as a count,
 * not as a title in the HTML.
 *
 * One person holds every relevant permission and has work waiting in every module; only the packages
 * change. Packages are the product's own: `tender` carries the Wiki module with it, `basis` is Wiki
 * (with Kvalitet and Avvik), `supplier` is Leverandøroppfølging.
 */
class InfoCenterModuleMatrixTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsMyTasks;

    /** Packages are this test's subject, so no customer gets Anbud for free. */
    protected bool $customersHoldTenderPackage = false;

    private const SUPPLIER = 'Matrise Leverandor AS';

    private const PAGE = 'Matrise Wikiside';

    private const AKSJON = 'Matrise aksjon';

    private const CASE = 'Matrise anbudssak';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> */
    public static function configurations(): array
    {
        return [
            'bare Leverandører' => [['supplier'], ['supplier']],
            'bare Wiki' => [['basis'], ['wiki']],
            // The Anbud package always carries the Wiki module.
            'bare Anbud' => [['tender'], ['tender', 'wiki']],
            'Leverandører + Wiki' => [['basis', 'supplier'], ['wiki', 'supplier']],
            'alle relevante moduler' => [['basis', 'tender', 'supplier'], ['tender', 'wiki', 'supplier']],
            'ingen relevante moduler' => [[], []],
        ];
    }

    /**
     * @param  list<string>  $packages
     * @param  list<string>  $expectedModules
     */
    #[DataProvider('configurations')]
    public function test_my_tasks_shows_exactly_the_modules_the_customer_holds(array $packages, array $expectedModules): void
    {
        [$user] = $this->scenario($packages);

        $response = $this->actingAs($user)->get(route('app.info-center.index'))->assertOk();
        $infoCenter = $response->viewData('page')['props']['infoCenter'];
        $html = $response->getContent();

        $this->assertSame('my_tasks', $infoCenter['active_view']);
        $this->assertEqualsCanonicalizing($expectedModules, array_values(array_unique(array_column($this->myTasksIn($infoCenter), 'module'))));
        $this->assertSame(count($expectedModules), $infoCenter['my_tasks']['count']);
        $this->assertSame(count($expectedModules), (int) collect($infoCenter['summary']['items'])->firstWhere('key', 'my_tasks')['count']);

        // Nothing from a module the customer does not hold, anywhere on the page.
        foreach (['supplier' => self::SUPPLIER, 'wiki' => self::PAGE, 'tender' => self::AKSJON] as $module => $title) {
            in_array($module, $expectedModules, true)
                ? $this->assertStringContainsString($title, $html)
                : $this->assertStringNotContainsString($title, $html, "{$module} leaked");
        }

        if (! in_array('tender', $expectedModules, true)) {
            $this->assertStringNotContainsString(self::CASE, $html);
            $this->assertSame([], $infoCenter['items']);
        }

        $hasTender = in_array('tender', $expectedModules, true);

        // The other views are lists of Anbud aksjoner. With Anbud they open as before; without it
        // the page is «Mine oppgaver» alone — one panel, one view — and an old link to another view
        // lands there instead of on a list that can only be empty.
        $this->assertSame(
            $hasTender ? ['my_tasks', 'awaiting_response', 'outbound', 'inbound'] : ['my_tasks'],
            array_column($infoCenter['view_options'], 'value'),
        );
        $this->assertSame('my_tasks', array_column($infoCenter['summary']['items'], 'key')[0]);

        if (! $hasTender) {
            $this->assertSame(['my_tasks'], array_column($infoCenter['summary']['items'], 'key'));
            $this->assertStringNotContainsString('aksjoner', mb_strtolower($infoCenter['role_context']['headline']));
        }

        foreach (['awaiting_response', 'outbound', 'inbound'] as $view) {
            $other = $this->actingAs($user)->get(route('app.info-center.index', ['view' => $view]))->assertOk();
            $otherCenter = $other->viewData('page')['props']['infoCenter'];

            $this->assertSame($hasTender ? $view : 'my_tasks', $otherCenter['active_view']);

            if (! $hasTender) {
                $this->assertStringNotContainsString(self::AKSJON, $other->getContent());
            }
        }
    }

    /**
     * @param  list<string>  $packages
     * @param  list<string>  $expectedModules
     */
    #[DataProvider('configurations')]
    public function test_the_bell_counts_exactly_the_modules_the_customer_holds(array $packages, array $expectedModules): void
    {
        [$user, $customer, $notice, $supplier] = $this->scenario($packages);

        $this->notify($user, 'supplier.owner_assigned', ['supplier_id' => (int) $supplier->id]);
        $this->notify($user, 'wiki.review_assigned');
        $this->notify($user, 'bid.task_assigned', [], $notice);
        // Account-level, not a module object: always shown.
        $this->notify($user, 'ai_quota.warning');

        $panel = $this->actingAs($user)->getJson(route('app.notifications.index'))->assertOk()->json('notifications');
        $expectedEvents = array_map(fn (string $module): string => match ($module) {
            'supplier' => 'supplier.owner_assigned',
            'wiki' => 'wiki.review_assigned',
            'tender' => 'bid.task_assigned',
        }, $expectedModules);

        $this->assertEqualsCanonicalizing([...$expectedEvents, 'ai_quota.warning'], array_column($panel['items'], 'event_type'));
        $this->assertSame(count($expectedEvents) + 1, $panel['unread_count']);
    }

    /** @return array<string, array{0: list<string>, 1: bool, 2: string}> */
    public static function helpConfigurations(): array
    {
        $cases = [];

        foreach ([
            'bare Leverandører' => [['supplier'], false],
            'bare Wiki' => [['basis'], false],
            'Anbud' => [['tender'], true],
            'Leverandører + Wiki' => [['basis', 'supplier'], false],
            'ingen relevante moduler' => [[], false],
        ] as $name => [$packages, $tender]) {
            foreach (['no', 'en'] as $language) {
                $cases["{$name} ({$language})"] = [$packages, $tender, $language];
            }
        }

        return $cases;
    }

    /**
     * The help explains the page the person has: without Anbud, «Mine oppgaver» alone, in their own
     * language and with no word about Anbud views; with Anbud, the views as before. The strings are
     * read from the page's own shared translations, exactly as the help component reads them.
     *
     * @param  list<string>  $packages
     */
    #[DataProvider('helpConfigurations')]
    public function test_the_help_matches_the_modules_and_the_language(array $packages, bool $tender, string $language): void
    {
        [$user] = $this->scenario($packages);
        $languageModel = Language::query()->firstOrCreate(['code' => $language], ['name_en' => $language, 'name_no' => $language]);
        $user->forceFill(['preferred_language_id' => $languageModel->id])->save();

        $props = $this->actingAs($user->fresh())->get(route('app.info-center.index'))->assertOk()->viewData('page')['props'];
        $infoCenter = $props['infoCenter'];
        // The help strings in this language, as infoCenterHelp.js reads them from info_center_page.
        // Read from the language file rather than the shared prop: the shared translations are built
        // before SetCustomerLocale runs (pre-existing middleware order, see the punkt 6 report), so
        // the prop is always the default language. The controller's own strings are not affected.
        $ic = (require base_path("lang/{$language}/procynia.php"))['info_center_page'];

        $this->assertSame($tender, $infoCenter['tender_available']);
        $this->assertSame($language, app()->getLocale(), 'the request ran in the person\'s language');

        // What the help shows without Anbud (resources/js/Pages/App/InfoCenter/infoCenterHelp.js).
        $myTasksOnly = [
            $ic['page_help_intro_my_tasks_only'],
            $ic['page_help_section_my_tasks'],
            $ic['page_help_item_my_tasks_title'],
            $ic['page_help_item_my_tasks_only_text'],
            $ic['page_help_item_modules_title'],
            $ic['page_help_item_modules_text'],
            $ic['page_help_section_practical'],
            $ic['page_help_item_practical_title'],
            $ic['page_help_item_practical_my_tasks_only_text'],
            $ic['my_tasks']['neutral_panel_description'],
            // Written by the controller in the request's language.
            $infoCenter['role_context']['headline'],
            $infoCenter['role_context']['subheadline'],
        ];
        $anbudWords = $language === 'no'
            ? '/venter på svar|beslutning|avklaring|aksjon|anbud|opprettet av meg|innkommende|frister innen/iu'
            : '/awaiting|decision|clarification|action|tender|created by me|inbound|deadlines within/iu';

        if (! $tender) {
            foreach ($myTasksOnly as $line) {
                $this->assertDoesNotMatchRegularExpression($anbudWords, (string) $line);
            }

            $this->assertSame(['my_tasks'], array_column($infoCenter['summary']['items'], 'key'));
        } else {
            // With Anbud the views keep their explanation.
            $this->assertNotSame('', $ic['page_help_item_awaiting_title']);
            $this->assertSame(['my_tasks', 'awaiting_response', 'outbound', 'inbound'], array_column($infoCenter['view_options'], 'value'));
        }

        // Old links to the Anbud views, and their legacy aliases, land where the page can answer.
        foreach (['awaiting_response', 'outbound', 'inbound', 'action_required', 'my_open', 'bogus'] as $view) {
            $active = $this->actingAs($user)->get(route('app.info-center.index', ['view' => $view]))->assertOk()
                ->viewData('page')['props']['infoCenter']['active_view'];

            $expected = $tender && in_array($view, ['awaiting_response', 'outbound', 'inbound'], true) ? $view : 'my_tasks';
            $this->assertSame($expected, $active, $view);
        }
    }

    // ── Scenario ────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $packages
     * @return array{0: User, 1: Customer, 2: SavedNotice, 3: Supplier}
     */
    private function scenario(array $packages): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Matrise AS',
            'slug' => 'matrise-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);

        foreach ($packages as $package) {
            CustomerPackageEntitlement::query()->create([
                'customer_id' => $customer->id,
                'package_key' => $package,
                'status' => CustomerPackageEntitlement::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);
        }

        $user = User::query()->create([
            'name' => 'Matrise Bruker',
            'email' => Str::lower(Str::random(10)).'@matrise.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Alt', 'is_active' => true]);
        $role->syncPermissions([
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_REVIEW,
            CustomerPermissionCatalog::SUPPLIER_VIEW,
            CustomerPermissionCatalog::SUPPLIER_EDIT,
        ]);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        // Leverandører: an expired document on a supplier the person is responsible for.
        $supplier = new Supplier([
            'customer_id' => $customer->id,
            'name' => self::SUPPLIER,
            'category' => 'it_cloud',
            'deliverable_description' => 'Drift',
            'owner_user_id' => $user->id,
        ]);
        $supplier->status = Supplier::STATUS_ACTIVE;
        $supplier->save();
        SupplierDocument::query()->create([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id,
            'document_type' => 'insurance_certificate', 'title' => 'Forsikring', 'valid_until' => '2026-10-06',
        ]);

        // Wiki: a page waiting for this person's review.
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $user->id,
            'slug' => 'matrise-'.Str::lower(Str::random(8)),
            'title' => self::PAGE,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Matrise',
            'generated_by_model' => 'gpt-5',
            'reviewer_user_id' => $user->id,
            'submitted_at' => now(),
        ]);

        // Anbud: an open aksjon on a case the person manages.
        $reference = Str::upper(Str::random(10));
        Notice::query()->firstOrCreate(['notice_id' => $reference], ['title' => 'Kunngjøring '.$reference, 'source' => 'doffin']);
        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => $reference,
            'title' => self::CASE,
            'buyer_name' => 'Testetaten',
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'bid_manager_user_id' => $user->id,
        ]);
        (new SavedNoticeInfoItem)->forceFill([
            'saved_notice_id' => $notice->id,
            'type' => SavedNoticeInfoItem::TYPE_MESSAGE,
            'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL,
            'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL,
            'subject' => self::AKSJON,
            'body' => self::AKSJON,
            'status' => SavedNoticeInfoItem::STATUS_OPEN,
            'requires_response' => false,
            'owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
        ])->save();

        return [$user->fresh(), $customer, $notice, $supplier];
    }

    /** @param  array<string, mixed>  $metadata */
    private function notify(User $user, string $eventType, array $metadata = [], ?SavedNotice $notice = null): void
    {
        UserNotification::query()->create([
            'customer_id' => $user->customer_id,
            'user_id' => $user->id,
            'saved_notice_id' => $notice?->id,
            'event_type' => $eventType,
            'dedupe_key' => $eventType.':'.Str::random(12),
            'severity' => UserNotification::SEVERITY_INFO,
            'title' => 'Varsel '.$eventType,
            'message' => 'Melding',
            'target_url' => '/app',
            'metadata' => $metadata,
        ]);
    }
}
