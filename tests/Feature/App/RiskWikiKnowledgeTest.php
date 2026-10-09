<?php

namespace Tests\Feature\App;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Risiko → «Lag kunnskapsartikkel» → Enterprise Wiki.
 *
 * What these tests defend:
 *
 *  - The handoff is the shared one (WikiKnowledgeHandoffService): an ordinary Wiki source and the
 *    ordinary document run. Nothing is published by it.
 *  - Nothing of the risk reaches the Wiki unless the person opts that section in; the provenance
 *    row (enterprise_wiki_document_origins) keeps ids only.
 *  - risk.edit in the area AND wiki.source.manage; neither alone is enough, and no Wiki permission
 *    ever makes a hidden or foreign risk reachable.
 *  - The Wiki never reveals which risk a page came out of.
 *  - Deleting either side removes only the provenance.
 */
class RiskWikiKnowledgeTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    private const RISK_EDITOR = [
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::RISK_EDIT,
    ];

    private const WIKI_HANDOFF = [
        CustomerPermissionCatalog::WIKI_VIEW,
        CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
    ];

    private const SECRET_TITLE = 'Uoppdaget sårbarhet i lønnssystemet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('local');
        Queue::fake();
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

    public function test_an_authorized_user_hands_knowledge_over_through_the_ordinary_wiki_source_flow(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$hr]);

        $props = $this->showProps($user, $risk);
        $this->assertTrue($props['knowledge_handoff']['can_create']);
        $this->assertSame([], $props['knowledge_handoff']['entries']);

        $this->actingAs($user)->post($this->url($risk), [
            'title' => 'Avstemming av lønnsfiler',
            'learning' => 'Lønnsfiler bør avstemmes mot hovedbok før utbetaling.',
            'sections' => [],
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        // An ordinary Wiki source with the person's text — and, with no section chosen, nothing of the risk.
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();
        $text = (string) $document->extracted_text;
        $this->assertSame('Avstemming av lønnsfiler.md', $document->original_filename);
        $this->assertSame((int) $user->id, (int) $document->uploaded_by_user_id);
        $this->assertStringContainsString('# Avstemming av lønnsfiler', $text);
        $this->assertStringContainsString('Lønnsfiler bør avstemmes', $text);
        foreach ([self::SECRET_TITLE, 'Hemmelig årsak', 'Hemmelig hendelse', 'Hemmelig konsekvens', 'Hemmelig utfyllende'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }

        // The ordinary document run, queued on Wiki's own queue — no page, nothing published.
        $run = EnterpriseWikiIngestRun::query()
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id)
            ->sole();
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, fn (RunEnterpriseWikiDocumentFlow $job): bool => (int) $job->runId === (int) $run->id);
        $this->assertSame(0, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());

        // Provenance is ids only.
        $this->assertDatabaseHas('enterprise_wiki_document_origins', [
            'customer_id' => $customer->id,
            'source_module' => 'risk',
            'source_type' => 'risk',
            'source_id' => $risk->id,
            'enterprise_wiki_document_id' => $document->id,
            'created_by_user_id' => $user->id,
        ]);
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'enterprise_wiki_document_id', 'source_module', 'source_type', 'source_id', 'created_by_user_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('enterprise_wiki_document_origins'),
        );

        // While the run works, the risk shows the source, linked to Kildedokumenter.
        $entries = $this->showProps($user, $risk)['knowledge_handoff']['entries'];
        $this->assertCount(1, $entries);
        $this->assertSame('source', $entries[0]['kind']);
        $this->assertSame('Avstemming av lønnsfiler', $entries[0]['title']);
        $this->assertSame(route('app.wiki.index', ['tab' => 'sources']), $entries[0]['url']);

        // The run creates a draft page: the risk shows the page, read live, with its Wiki status.
        $page = $this->createWikiPage($customer, 'Avstemming av lønnsfiler');
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
        // A page the run only updated was not produced by the risk.
        $updated = $this->createWikiPage($customer, 'Eksisterende side');
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $updated->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
        ]);

        $entries = $this->showProps($user, $risk)['knowledge_handoff']['entries'];
        $this->assertCount(1, $entries);
        $this->assertSame('page', $entries[0]['kind']);
        $this->assertSame(route('app.wiki.show', ['slug' => $page->slug]), $entries[0]['url']);
        $this->assertNotSame('', $entries[0]['state_label']);
        $this->assertNull($page->fresh()->published_version_id);
    }

    public function test_only_the_sections_the_person_opts_in_reach_the_wiki(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$hr]);

        // The draft is built from the risk on the server, and nothing in it is preselected.
        $draft = $this->showProps($user, $risk)['knowledge_handoff']['draft'];
        $this->assertSame('', $draft['title']);
        $this->assertSame(['description'], array_column($draft['sections'], 'key'));

        $this->actingAs($user)->post($this->url($risk), [
            'title' => 'Lønnsrisiko',
            'learning' => 'Avstem lønnsfiler før utbetaling.',
            'sections' => ['description'],
        ])->assertSessionHasNoErrors();

        $text = (string) EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole()->extracted_text;
        $this->assertStringContainsString('## Læringspunkter', $text);
        $this->assertStringContainsString('Avstem lønnsfiler før utbetaling.', $text);
        $this->assertStringContainsString('Årsak: Hemmelig årsak', $text);
        $this->assertStringContainsString('Konsekvens: Hemmelig konsekvens', $text);
        // «Utfyllende informasjon» is never offered, and the risk title is only what the person typed.
        $this->assertStringNotContainsString('Hemmelig utfyllende', $text);
        $this->assertStringNotContainsString(self::SECRET_TITLE, $text);
    }

    public function test_a_section_the_record_does_not_offer_is_refused(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$hr]);

        // Controls belong to Kvalitet; without a linked control (or Kvalitet access) nothing is offered.
        $this->actingAs($user)->post($this->url($risk), [...$this->payload(), 'sections' => ['controls']])
            ->assertSessionHasErrors(['sections.0']);
        $this->assertNothingHandedOver($customer);
    }

    public function test_without_wiki_view_the_risk_page_lists_the_knowledge_without_links(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $document = $this->sourceFrom($customer, $risk);

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        $props = $this->showProps($reader, $risk);
        $this->assertFalse($props['knowledge_handoff']['can_create']);
        // Nothing of the risk is offered to someone who may not hand it over.
        $this->assertNull($props['knowledge_handoff']['draft']);
        $this->assertCount(1, $props['knowledge_handoff']['entries']);
        $this->assertNull($props['knowledge_handoff']['entries'][0]['url']);
        $this->assertSame((int) $document->id, (int) $this->origins($risk)->value('enterprise_wiki_document_id'));
    }

    public function test_risk_view_without_risk_edit_cannot_hand_over(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, ...self::WIKI_HANDOFF], [$hr]);

        $this->assertFalse($this->showProps($user, $risk)['knowledge_handoff']['can_create']);
        $this->actingAs($user)->post($this->url($risk), $this->payload())->assertForbidden();
        $this->assertNothingHandedOver($customer);
    }

    public function test_risk_edit_without_the_wiki_source_permission_cannot_hand_over(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        // Every Wiki permission except the one the Kvalitet handoff requires.
        $this->grant($customer, $user, [
            ...self::RISK_EDITOR,
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
            CustomerPermissionCatalog::WIKI_REVIEW,
            CustomerPermissionCatalog::WIKI_APPROVE,
        ], [$hr]);

        $this->assertFalse($this->showProps($user, $risk)['knowledge_handoff']['can_create']);
        $this->actingAs($user)->post($this->url($risk), $this->payload())->assertForbidden();
        $this->assertNothingHandedOver($customer);
    }

    public function test_wiki_permissions_give_no_access_to_a_risk_or_its_handoff(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $risk = $this->risk($customer, $hr);

        // Every Wiki permission, no risk permission at all.
        $wikiOnly = $this->member($customer);
        $this->grant($customer, $wikiOnly, [...self::WIKI_HANDOFF, CustomerPermissionCatalog::WIKI_EDIT]);
        $this->actingAs($wikiOnly)->get("/app/risk/risks/{$risk->id}")->assertForbidden();
        $this->actingAs($wikiOnly)->post($this->url($risk), $this->payload())->assertForbidden();

        // Risk editor in another area, and every Wiki permission: the risk is absent, not forbidden.
        $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$finance]);
        $this->actingAs($elsewhere)->get("/app/risk/risks/{$risk->id}")->assertNotFound();
        $this->actingAs($elsewhere)->post($this->url($risk), $this->payload())->assertNotFound();

        // System Owner holds every Wiki permission, but no fagområde: fail-closed.
        $owner = User::query()->where('customer_id', $customer->id)->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)->sole();
        $this->actingAs($owner)->post($this->url($risk), $this->payload())->assertNotFound();

        $this->assertNothingHandedOver($customer);
    }

    public function test_a_risk_of_another_customer_is_a_404(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $foreignRisk = $this->risk($other, $this->area($other, 'HR'));

        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$this->area($customer, 'HR')]);

        $this->actingAs($user)->post($this->url($foreignRisk), $this->payload())->assertNotFound();
        $this->assertNothingHandedOver($customer);
        $this->assertNothingHandedOver($other);
    }

    public function test_the_wiki_does_not_reveal_which_risk_the_knowledge_came_from(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$hr]);

        $this->actingAs($user)->post($this->url($risk), $this->payload())->assertSessionHasNoErrors();

        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();
        $run = EnterpriseWikiIngestRun::query()->where('source_id', $document->id)->sole();
        $page = $this->createWikiPageWithVersion($customer, 'Generell læring', "# Generell læring\n\nAvstem filer før utbetaling.");
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        // A Wiki reader with no risk access, and the risk editor themselves: neither sees the risk in the Wiki.
        $wikiReader = $this->member($customer);
        $this->grant($customer, $wikiReader, self::WIKI_HANDOFF);

        foreach ([$wikiReader, $user] as $viewer) {
            foreach (["/app/wiki/{$page->slug}", '/app/wiki?tab=sources', '/app/wiki'] as $url) {
                $content = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
                $this->assertStringNotContainsString(self::SECRET_TITLE, $content, $url);
                $this->assertStringNotContainsString("/app/risk/risks/{$risk->id}", $content, $url);
                $this->assertStringNotContainsString('enterprise_wiki_document_origins', $content, $url);
                $this->assertStringNotContainsString('Hemmelig', $content, $url);
            }
        }
    }

    public function test_deleting_the_wiki_source_removes_only_the_provenance(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $document = $this->sourceFrom($customer, $risk);

        EnterpriseWikiIngestRun::query()->where('source_id', $document->id)->update(['status' => EnterpriseWikiIngestRun::STATUS_FAILED]);

        app(EnterpriseWikiDocumentDeletionService::class)->delete($document->fresh(), $owner);

        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $document->id]);
        $this->assertSame(0, $this->origins($risk)->count());
        $this->assertDatabaseHas('risks', ['id' => $risk->id, 'title' => self::SECRET_TITLE, 'cause' => 'Hemmelig årsak']);
    }

    public function test_deleting_a_wiki_page_leaves_the_risk_and_drops_the_page_from_its_list(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $document = $this->sourceFrom($customer, $risk);
        $run = EnterpriseWikiIngestRun::query()->where('source_id', $document->id)->sole();
        $page = $this->createWikiPage($customer, 'Generell læring');
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::WIKI_VIEW], [$hr]);
        $this->assertSame('page', $this->showProps($user, $risk)['knowledge_handoff']['entries'][0]['kind']);

        $page->delete();

        $this->assertDatabaseHas('risks', ['id' => $risk->id]);
        $entries = $this->showProps($user, $risk)['knowledge_handoff']['entries'];
        $this->assertNotContains('Generell læring', array_column(array_filter($entries, fn ($e) => $e['kind'] === 'page'), 'title'));
    }

    public function test_deleting_the_risk_leaves_the_wiki_knowledge(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::RISK_DELETE, ...self::WIKI_HANDOFF], [$hr]);

        $this->actingAs($user)->post($this->url($risk), $this->payload())->assertSessionHasNoErrors();
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();
        $page = $this->createWikiPage($customer, 'Generell læring');
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => EnterpriseWikiIngestRun::query()->where('source_id', $document->id)->value('id'),
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $this->actingAs($user)->delete("/app/risk/risks/{$risk->id}")->assertRedirect();

        $this->assertDatabaseMissing('risks', ['id' => $risk->id]);
        $this->assertDatabaseMissing('enterprise_wiki_document_origins', ['enterprise_wiki_document_id' => $document->id]);
        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $page->id]);
    }

    public function test_title_and_content_are_required(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$hr]);

        $this->actingAs($user)->post($this->url($risk), ['title' => '', 'learning' => '', 'sections' => []])
            ->assertSessionHasErrors(['title', 'learning']);
        $this->assertNothingHandedOver($customer);
    }

    // ---------------------------------------------------------------------

    /** @return array{title: string, learning: string, sections: list<string>} */
    private function payload(): array
    {
        return ['title' => 'Generell læring', 'learning' => 'Avstem filer før utbetaling.', 'sections' => []];
    }

    private function sourceFrom(Customer $customer, Risk $risk): EnterpriseWikiDocument
    {
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [...self::RISK_EDITOR, ...self::WIKI_HANDOFF], [$risk->businessArea]);
        $this->actingAs($editor)->post($this->url($risk), $this->payload())->assertSessionHasNoErrors();

        return EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
    }

    private function assertNothingHandedOver(Customer $customer): void
    {
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, EnterpriseWikiDocumentOrigin::query()->where('customer_id', $customer->id)->count());
        Queue::assertNotPushed(RunEnterpriseWikiDocumentFlow::class);
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): void
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);
    }

    private function url(Risk $risk): string
    {
        return "/app/risk/risks/{$risk->id}/knowledge-handoff";
    }

    private function origins(Risk $risk)
    {
        return EnterpriseWikiDocumentOrigin::query()->where('source_type', 'risk')->where('source_id', $risk->id);
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function risk(Customer $customer, BusinessArea $area): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => self::SECRET_TITLE,
            'cause' => 'Hemmelig årsak',
            'event' => 'Hemmelig hendelse',
            'consequence' => 'Hemmelig konsekvens',
            'description' => 'Hemmelig utfyllende informasjon',
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    /** @return array<string, mixed> */
    private function showProps(User $user, Risk $risk): array
    {
        return $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertOk()->viewData('page')['props'];
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risiko-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Risiko Wiki AS',
            'slug' => 'risiko-wiki-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        // Basis carries the Wiki module the handoff requires; every real customer holds it.
        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');
        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
