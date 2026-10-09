<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierControlRequirementWikiPage;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * «Veiledning fra Enterprise Wiki» on a control requirement (docs/supplier-assurance-v2-plan.md §28).
 * One test per rule:
 *
 *  - several existing Wiki pages are linked to a requirement, read on Kontrollkrav and on the
 *    supplier page with a link into the Wiki, and removed again — the page itself never changes;
 *  - the same page twice is refused;
 *  - another customer's page or requirement is never reachable;
 *  - managing needs supplier.assure and Wiki read access; reading needs Wiki read access and the
 *    customer's Wiki module, and without it nothing about the guidance is in the page — no title;
 *  - archived pages and the Wiki's own index pages are not offered; a draft or a page archived later
 *    is shown with what it is; a deleted page or requirement takes its links with it;
 *  - a requirement for an ended supplier is read-only; controls and the Wiki work as before.
 */
class SupplierRequirementWikiGuidanceTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const CATALOGUE = '/app/supplier-management/control-requirements';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    public function test_several_pages_are_linked_read_on_both_pages_and_removed_without_touching_the_wiki(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $howTo = $this->page($customer, 'Slik kontrollerer vi databehandleravtaler');
        $checklist = $this->page($customer, 'Avtalesjekkliste for personvern');

        // Search: the person's own Wiki, by title.
        $found = $this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages?search=databehandler')->assertOk()->json('pages');
        $this->assertSame([$howTo->id], array_column($found, 'id'));

        foreach ([$howTo, $checklist] as $page) {
            $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])->assertSessionHasNoErrors()->assertSessionHas('success');
        }

        // Kontrollkrav shows both, by title, each opening the page in the Wiki.
        $catalogue = $this->actingAs($assurer)->get(self::CATALOGUE)->assertOk()->viewData('page')['props'];
        $this->assertTrue($catalogue['permissions']['can_manage_wiki_guidance']);
        $guidance = $catalogue['requirements'][0]['wiki_guidance'];
        $this->assertSame(['Avtalesjekkliste for personvern', 'Slik kontrollerer vi databehandleravtaler'], array_column($guidance, 'title'));
        $this->assertSame("/app/wiki/{$checklist->slug}", $guidance[0]['url']);
        $this->assertSame('not_published', $guidance[0]['note']);

        // Where the control is done: the same guidance on the requirement on the supplier page.
        $onSupplier = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'][0];
        $this->assertSame(array_column($guidance, 'id'), array_column($onSupplier['wiki_guidance'], 'id'));

        // Removed: the link goes, the page stays as it was.
        $this->actingAs($assurer)->delete($this->linkUrl($requirement, $howTo))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame([$checklist->id], SupplierControlRequirementWikiPage::query()->pluck('enterprise_wiki_page_id')->all());
        $this->assertSame('Slik kontrollerer vi databehandleravtaler', $howTo->fresh()->title);
        $this->actingAs($assurer)->delete($this->linkUrl($requirement, $howTo))->assertSessionHasErrors('wiki_page_id');

        // The Wiki itself still opens the page.
        $this->actingAs($assurer)->get("/app/wiki/{$checklist->slug}")->assertOk();
    }

    public function test_the_same_page_cannot_be_linked_twice(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $page = $this->page($customer, 'Slik kontrollerer vi databehandleravtaler');

        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])
            ->assertSessionHasErrors(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_already_linked')]);
        $this->assertSame(1, SupplierControlRequirementWikiPage::query()->count());

        // And the database refuses a duplicate written around the service.
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirement_wiki_pages')->insert([
            'customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'enterprise_wiki_page_id' => $page->id, 'created_at' => now(), 'updated_at' => now(),
        ]), 'a duplicate link');
    }

    public function test_another_customers_page_or_requirement_is_never_reachable(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreign] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $foreignAssurer = $this->supplierUser($foreign, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $foreignRequirement = $this->requirement($foreign, 'Fremmed krav');
        $foreignPage = $this->page($foreign, 'Fremmed veiledning om databehandlere');

        // Another customer's page reads as not available — the same as one that does not exist.
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $foreignPage->id])
            ->assertSessionHasErrors(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_not_available')]);
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => 999999999])
            ->assertSessionHasErrors(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_not_available')]);
        $this->assertSame([], $this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages?search=fremmed')->json('pages'));

        // Another customer's requirement is a 404.
        $this->actingAs($assurer)->post($this->linkUrl($foreignRequirement), ['wiki_page_id' => $foreignPage->id])->assertNotFound();
        $this->actingAs($foreignAssurer)->post($this->linkUrl($foreignRequirement), ['wiki_page_id' => $foreignPage->id])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->delete($this->linkUrl($foreignRequirement, $foreignPage))->assertNotFound();

        // The database refuses a link across customers.
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirement_wiki_pages')->insert([
            'customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'enterprise_wiki_page_id' => $foreignPage->id, 'created_at' => now(), 'updated_at' => now(),
        ]), 'a link to another customer\'s page');
    }

    public function test_managing_needs_assure_and_wiki_access_and_reading_never_shows_what_the_person_cannot_open(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $page = $this->page($customer, 'Hemmelig veiledning om databehandlere');
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])->assertSessionHasNoErrors();

        // A reader with the Wiki sees the guidance and is offered nothing.
        $wikiReader = $this->supplierUser($customer, [CustomerPermissionCatalog::WIKI_VIEW]);
        $props = $this->actingAs($wikiReader)->get(self::CATALOGUE)->viewData('page')['props'];
        $this->assertSame([$page->id], array_column($props['requirements'][0]['wiki_guidance'], 'id'));
        $this->assertFalse($props['permissions']['can_manage_wiki_guidance']);
        $this->actingAs($wikiReader)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])->assertForbidden();
        $this->actingAs($wikiReader)->delete($this->linkUrl($requirement, $page))->assertForbidden();
        $this->actingAs($wikiReader)->getJson(self::CATALOGUE.'/wiki-pages')->assertForbidden();

        // supplier.assure without Wiki access: cannot link, and nothing about the guidance — not even the title.
        $assurerWithoutWiki = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $reader = $this->supplierUser($customer, []);
        foreach ([$assurerWithoutWiki, $reader] as $person) {
            $catalogue = $this->actingAs($person)->get(self::CATALOGUE)->viewData('page')['props'];
            $this->assertNull($catalogue['requirements'][0]['wiki_guidance']);
            $this->assertStringNotContainsString('Hemmelig veiledning', json_encode($catalogue));
            $onSupplier = $this->actingAs($person)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
            $this->assertNull($onSupplier['control_requirements']['applicable'][0]['wiki_guidance']);
            $this->assertStringNotContainsString('Hemmelig veiledning', json_encode($onSupplier));
        }
        $this->actingAs($assurerWithoutWiki)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])->assertForbidden();
        $this->actingAs($assurerWithoutWiki)->delete($this->linkUrl($requirement, $page))->assertForbidden();
        $this->assertSame(1, SupplierControlRequirementWikiPage::query()->count());

        // The link never opens the page past the Wiki's own rule: without wiki.view the Wiki refuses it.
        $this->actingAs($reader)->get("/app/wiki/{$page->slug}")->assertForbidden();
        $this->actingAs($wikiReader)->get("/app/wiki/{$page->slug}")->assertOk();
    }

    public function test_a_wiki_module_switched_off_hides_the_links_without_deleting_them_and_they_return_when_it_is_back(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $page = $this->page($customer, 'Veiledning som kommer tilbake');
        $other = $this->page($customer, 'Annen veiledning');
        $basis = config('procynia_modules.packages.basis.modules');
        $tender = config('procynia_modules.packages.tender.modules');

        // 1–2. The Wiki is on and the page is linked.
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])->assertSessionHasNoErrors();
        $this->assertSame([$page->id], array_column($this->guidance($assurer), 'id'));

        // 3–5. The Wiki module is switched off (no package carries it): nothing is shown or managed, the link stays.
        config()->set('procynia_modules.packages.basis.modules', ['quality', 'improvements']);
        config()->set('procynia_modules.packages.tender.modules', ['tender']);
        $catalogue = $this->actingAs($assurer)->get(self::CATALOGUE)->viewData('page')['props'];
        $this->assertNull($catalogue['requirements'][0]['wiki_guidance']);
        $this->assertFalse($catalogue['permissions']['can_manage_wiki_guidance']);
        $this->assertStringNotContainsString('Veiledning som kommer tilbake', json_encode($catalogue));
        $onSupplier = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements'];
        $this->assertNull($onSupplier['applicable'][0]['wiki_guidance']);
        $this->assertFalse($onSupplier['permissions']['can_manage_wiki_guidance']);
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $other->id])->assertForbidden();
        $this->actingAs($assurer)->delete($this->linkUrl($requirement, $page))->assertForbidden();
        $this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages')->assertForbidden();
        $this->assertSame([$page->id], SupplierControlRequirementWikiPage::query()->pluck('enterprise_wiki_page_id')->all());

        // 6–7. Back on: the same link works again, nothing registered anew.
        config()->set('procynia_modules.packages.basis.modules', $basis);
        config()->set('procynia_modules.packages.tender.modules', $tender);
        $this->assertSame([$page->id], array_column($this->guidance($assurer), 'id'));
        $this->assertSame(1, SupplierControlRequirementWikiPage::query()->count());
        $this->actingAs($assurer)->delete($this->linkUrl($requirement, $page))->assertSessionHasNoErrors();
    }

    public function test_search_returns_at_most_twenty_and_says_when_there_are_more(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);

        foreach (range(1, 20) as $n) {
            $this->page($customer, sprintf('Leverandørveiledning %02d', $n));
        }
        $this->page($customer, 'Noe helt annet');

        $exact = $this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages?search=leverandørveiledning')->assertOk()->json();
        $this->assertSame([20, false], [count($exact['pages']), $exact['has_more']]);

        $this->page($customer, 'Leverandørveiledning 21');
        $more = $this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages?search=LEVERANDØRVEILEDNING')->assertOk()->json();
        $this->assertSame([20, true], [count($more['pages']), $more['has_more']]);
        $this->assertSame('Leverandørveiledning 01', $more['pages'][0]['title']);

        // A wildcard in the term is a character, not a pattern.
        $this->assertSame([], $this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages?search=%25')->json('pages'));
    }

    public function test_a_customer_without_the_wiki_module_sees_no_guidance_even_with_wiki_view(): void
    {
        // No package carries the Wiki — Basis, and Anbud (which the test customer holds by default) both do normally.
        config()->set('procynia_modules.packages.basis.modules', ['quality', 'improvements']);
        config()->set('procynia_modules.packages.tender.modules', ['tender']);
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $page = $this->page($customer, 'Veiledning uten modul');
        SupplierControlRequirementWikiPage::query()->create(['customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'enterprise_wiki_page_id' => $page->id]);

        $props = $this->actingAs($assurer)->get(self::CATALOGUE)->assertOk()->viewData('page')['props'];
        $this->assertNull($props['requirements'][0]['wiki_guidance']);
        $this->assertFalse($props['permissions']['can_manage_wiki_guidance']);
        $this->assertStringNotContainsString('Veiledning uten modul', json_encode($props));
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])->assertForbidden();
        $this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages')->assertForbidden();
    }

    public function test_archived_and_index_pages_are_not_offered_and_a_deleted_page_or_requirement_takes_its_links(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $draft = $this->page($customer, 'Veiledning databehandler utkast');
        $archived = $this->page($customer, 'Veiledning databehandler gammel', ['status' => EnterpriseWikiPage::STATUS_ARCHIVED]);
        $index = $this->page($customer, 'Veiledning databehandler oversikt', ['page_type' => EnterpriseWikiPage::PAGE_TYPE_INDEX]);

        // Search offers the draft only; the archived page and the Wiki's index page cannot be linked.
        $this->assertSame([$draft->id], array_column($this->actingAs($assurer)->getJson(self::CATALOGUE.'/wiki-pages?search=veiledning')->json('pages'), 'id'));
        foreach ([$archived, $index] as $page) {
            $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $page->id])
                ->assertSessionHasErrors(['wiki_page_id' => __('procynia.supplier_management.validation.wiki_page_not_available')]);
        }

        // A draft is linked and says it is not published; archived later in the Wiki, it says so.
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $draft->id])->assertSessionHasNoErrors();
        $this->assertSame('not_published', $this->guidance($assurer)[0]['note']);
        $draft->forceFill(['status' => EnterpriseWikiPage::STATUS_ARCHIVED])->save();
        $this->assertSame('archived', $this->guidance($assurer)[0]['note']);

        // Deleting the page in the Wiki takes the link with it; nothing in the Wiki is blocked.
        $draft->delete();
        $this->assertSame([], $this->guidance($assurer));
        $this->assertSame(0, SupplierControlRequirementWikiPage::query()->count());

        // Deleting an unused requirement takes its links with it.
        $kept = $this->page($customer, 'Veiledning databehandler ny');
        $this->actingAs($assurer)->post($this->linkUrl($requirement), ['wiki_page_id' => $kept->id])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->delete(self::CATALOGUE."/{$requirement->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, SupplierControlRequirementWikiPage::query()->count());
        $this->assertNotNull($kept->fresh());
    }

    public function test_an_ended_suppliers_requirement_is_read_only_and_controls_work_as_before(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::WIKI_VIEW]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $own = $this->requirement($customer, 'Kontraktsfestet miljøkrav', $supplier);
        $page = $this->page($customer, 'Slik følger vi opp miljøkrav');

        // A requirement for one supplier: guidance is managed on that supplier's page.
        $this->assertTrue($this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['permissions']['can_manage_wiki_guidance']);
        $this->actingAs($assurer)->post($this->linkUrl($own), ['wiki_page_id' => $page->id])->assertSessionHasNoErrors();

        // The control is registered as before, and the guidance does not enter its history.
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/requirement-evaluations", [
            'requirement_id' => $own->id, 'status' => 'missing', 'rationale' => 'Ikke dokumentert ennå.',
            'evaluated_on' => now()->toDateString(), 'accepted_until' => '', 'document_ids' => [],
        ])->assertSessionHasNoErrors();
        $row = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'][0];
        $this->assertSame(['missing', [$page->id]], [$row['display_status'], array_column($row['wiki_guidance'], 'id')]);
        $this->assertArrayNotHasKey('wiki_guidance', $row['evaluations'][0]);

        // Ended: read-only, still readable.
        $supplier->forceFill(['status' => Supplier::STATUS_ENDED])->save();
        $this->assertFalse($this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['permissions']['can_manage_wiki_guidance']);
        $this->actingAs($assurer)->delete($this->linkUrl($own, $page))->assertSessionHasErrors(['wiki_page_id' => __('procynia.supplier_management.validation.reopen_before_edit')]);
        $this->assertSame(1, SupplierControlRequirementWikiPage::query()->count());
    }

    /** @return list<array<string, mixed>> */
    private function guidance(User $user): array
    {
        return $this->actingAs($user)->get(self::CATALOGUE)->viewData('page')['props']['requirements'][0]['wiki_guidance'];
    }

    private function linkUrl(SupplierControlRequirement $requirement, ?EnterpriseWikiPage $page = null): string
    {
        return self::CATALOGUE."/{$requirement->id}/wiki-pages".($page !== null ? "/{$page->id}" : '');
    }

    private function requirement(Customer $customer, string $title, ?Supplier $supplier = null): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'supplier_id' => $supplier?->id, 'title' => $title, 'theme' => 'privacy',
            'level' => 'important', 'control_point' => 'before_contract', 'applies_when' => [],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function page(Customer $customer, string $title, array $attributes = []): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create($attributes + [
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }
}
