<?php

namespace Tests\Feature\App;

use App\Jobs\Quality\ProjectQualityPageToGraph;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityPageClassification;
use App\Models\QualityRelation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Quality V1 — classification, semantic relations, and the boundaries around them.
 *
 * What these tests defend:
 *
 *  - Kvalitet is a paid module, and the gate is the backend's, not the rail's.
 *  - A quality relation is a statement about the kvalitetssystem, so it may only exist between
 *    classified pages in a pair the matrix allows — and it must stop being stored the moment a
 *    reclassification makes it untrue.
 *  - Quality adds to Wiki without owning it: unclassifying removes quality rows and leaves the
 *    Wiki page alone.
 *  - Nothing crosses a customer boundary.
 */
class QualityStructureTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
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

    // ---------------------------------------------------------------------
    // Entitlement
    // ---------------------------------------------------------------------

    public function test_quality_is_refused_to_a_customer_without_the_module(): void
    {
        ['owner' => $owner] = $this->context(grantQuality: false);

        $this->actingAs($owner)
            ->get('/app/quality')
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_the_write_actions_are_gated_by_the_same_entitlement_as_the_page(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context(grantQuality: false);
        $page = $this->page($customer, 'Innkjopspolicy');

        // The guard is applied to the route group by name prefix, so a POST is refused exactly as
        // the GET is — a bookmarked form must not be a way past the commercial boundary.
        $this->actingAs($owner)
            ->post('/app/quality/classifications', [
                'page_id' => $page->id,
                'quality_type' => QualityPageClassification::TYPE_POLICY,
            ])
            ->assertRedirect(route('app.dashboard'));

        $this->assertDatabaseCount('quality_page_classifications', 0);
    }

    public function test_the_grc_package_opens_quality_too(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context(grantQuality: false);
        $this->grant($customer, 'grc');

        $this->actingAs($owner)->get('/app/quality')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Classification
    // ---------------------------------------------------------------------

    public function test_classifying_a_wiki_page_records_the_quality_type_and_projects_it(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $page = $this->page($customer, 'Innkjopspolicy');

        $this->actingAs($owner)
            ->post('/app/quality/classifications', [
                'page_id' => $page->id,
                'quality_type' => QualityPageClassification::TYPE_POLICY,
                'quality_code' => 'POL-01',
            ])
            ->assertRedirect();

        $classification = QualityPageClassification::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->sole();

        $this->assertSame(QualityPageClassification::TYPE_POLICY, $classification->quality_type);
        $this->assertSame('POL-01', $classification->quality_code);
        $this->assertSame((int) $customer->id, (int) $classification->customer_id);
        $this->assertSame((int) $owner->id, (int) $classification->classified_by_user_id);

        // The quality type is a separate vocabulary: the Wiki page's own page_type is untouched.
        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $page->fresh()->page_type);

        Queue::assertPushed(
            ProjectQualityPageToGraph::class,
            fn (ProjectQualityPageToGraph $job): bool => $job->pageId === (int) $page->id,
        );
    }

    public function test_a_page_belonging_to_another_customer_cannot_be_classified(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context(name: 'Annen AS');
        $foreignPage = $this->page($otherCustomer, 'Fremmed side');

        $this->actingAs($owner)
            ->post('/app/quality/classifications', [
                'page_id' => $foreignPage->id,
                'quality_type' => QualityPageClassification::TYPE_POLICY,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('quality_page_classifications', 0);
    }

    public function test_a_contributor_without_claim_approval_may_read_but_not_classify(): void
    {
        ['customer' => $customer] = $this->context();
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, 'contributor');
        $page = $this->page($customer, 'Innkjopspolicy');

        $this->actingAs($contributor)->get('/app/quality')->assertOk();

        $this->actingAs($contributor)
            ->post('/app/quality/classifications', [
                'page_id' => $page->id,
                'quality_type' => QualityPageClassification::TYPE_POLICY,
            ])
            ->assertForbidden();
    }

    public function test_unclassifying_removes_the_quality_rows_and_keeps_the_wiki_page(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $process = $this->classified($customer, 'Anskaffelsesprosess', QualityPageClassification::TYPE_PROCESS);
        $this->relate($customer, $policy, $process, QualityRelation::TYPE_GOVERNS);

        $classification = QualityPageClassification::query()
            ->where('enterprise_wiki_page_id', $policy->id)
            ->sole();

        $this->actingAs($owner)
            ->delete("/app/quality/classifications/{$classification->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('quality_page_classifications', ['id' => $classification->id]);
        // The edge went with it: an unclassified page is outside the kvalitetssystem and cannot be
        // either end of a quality relation.
        $this->assertDatabaseCount('quality_relations', 0);
        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $policy->id]);
    }

    // ---------------------------------------------------------------------
    // Semantic relations
    // ---------------------------------------------------------------------

    public function test_the_four_v1_relations_are_accepted(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $policy = $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $process = $this->classified($customer, 'Anskaffelsesprosess', QualityPageClassification::TYPE_PROCESS);
        $secondProcess = $this->classified($customer, 'Leverandorvurdering', QualityPageClassification::TYPE_PROCESS);
        $checklist = $this->classified($customer, 'Tilbudssjekkliste', QualityPageClassification::TYPE_CHECKLIST);
        $control = $this->classified($customer, 'Internkontroll anskaffelser', QualityPageClassification::TYPE_CONTROL);

        $cases = [
            [$policy, $process, QualityRelation::TYPE_GOVERNS],
            [$process, $checklist, QualityRelation::TYPE_USES],
            [$control, $process, QualityRelation::TYPE_VERIFIES],
            [$process, $secondProcess, QualityRelation::TYPE_DEPENDS_ON],
        ];

        foreach ($cases as [$from, $to, $relationType]) {
            $this->actingAs($owner)
                ->post('/app/quality/relations', [
                    'from_page_id' => $from->id,
                    'to_page_id' => $to->id,
                    'relation_type' => $relationType,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('quality_relations', 4);
    }

    public function test_a_relation_the_matrix_forbids_is_refused(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $policy = $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $process = $this->classified($customer, 'Anskaffelsesprosess', QualityPageClassification::TYPE_PROCESS);

        // Direction carries meaning: a process does not govern a policy.
        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_page_id' => $process->id,
                'to_page_id' => $policy->id,
                'relation_type' => QualityRelation::TYPE_GOVERNS,
            ])
            ->assertSessionHasErrors('relation_type');

        $this->assertDatabaseCount('quality_relations', 0);
    }

    public function test_a_relation_requires_both_ends_to_be_classified(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $policy = $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $unclassified = $this->page($customer, 'Vanlig wiki-side');

        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_page_id' => $policy->id,
                'to_page_id' => $unclassified->id,
                'relation_type' => QualityRelation::TYPE_GOVERNS,
            ])
            ->assertSessionHasErrors('relation_type');

        $this->assertDatabaseCount('quality_relations', 0);
    }

    public function test_a_document_cannot_relate_to_itself(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->classified($customer, 'Anskaffelsesprosess', QualityPageClassification::TYPE_PROCESS);

        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_page_id' => $process->id,
                'to_page_id' => $process->id,
                'relation_type' => QualityRelation::TYPE_DEPENDS_ON,
            ])
            ->assertSessionHasErrors('to_page_id');
    }

    public function test_reclassifying_a_page_drops_the_relations_it_invalidates(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $policy = $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $process = $this->classified($customer, 'Anskaffelsesprosess', QualityPageClassification::TYPE_PROCESS);
        $checklist = $this->classified($customer, 'Tilbudssjekkliste', QualityPageClassification::TYPE_CHECKLIST);

        $this->relate($customer, $policy, $process, QualityRelation::TYPE_GOVERNS);
        $this->relate($customer, $process, $checklist, QualityRelation::TYPE_USES);

        // The process becomes a procedure. "Policy governs process" and "process uses checklist"
        // are both statements about a process, so neither is true any more.
        $this->actingAs($owner)
            ->post('/app/quality/classifications', [
                'page_id' => $process->id,
                'quality_type' => QualityPageClassification::TYPE_PROCEDURE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('quality_relations', 0);
        $this->assertSame(
            QualityPageClassification::TYPE_PROCEDURE,
            QualityPageClassification::query()->where('enterprise_wiki_page_id', $process->id)->value('quality_type'),
        );
    }

    public function test_reclassifying_keeps_the_relations_that_stay_true(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $policy = $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $process = $this->classified($customer, 'Anskaffelsesprosess', QualityPageClassification::TYPE_PROCESS);
        $this->relate($customer, $policy, $process, QualityRelation::TYPE_GOVERNS);

        // Renumbering the policy changes nothing about what it governs.
        $this->actingAs($owner)
            ->post('/app/quality/classifications', [
                'page_id' => $policy->id,
                'quality_type' => QualityPageClassification::TYPE_POLICY,
                'quality_code' => 'POL-02',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('quality_relations', 1);
    }

    public function test_a_relation_belonging_to_another_customer_cannot_be_deleted(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context(name: 'Annen AS');

        $policy = $this->classified($otherCustomer, 'Fremmed policy', QualityPageClassification::TYPE_POLICY);
        $process = $this->classified($otherCustomer, 'Fremmed prosess', QualityPageClassification::TYPE_PROCESS);
        $relation = $this->relate($otherCustomer, $policy, $process, QualityRelation::TYPE_GOVERNS);

        $this->actingAs($owner)
            ->delete("/app/quality/relations/{$relation->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('quality_relations', ['id' => $relation->id]);
    }

    // ---------------------------------------------------------------------
    // The view
    // ---------------------------------------------------------------------

    public function test_each_tab_shows_only_its_own_quality_types(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $this->classified($customer, 'Anskaffelsesprosess', QualityPageClassification::TYPE_PROCESS);
        $this->classified($customer, 'Tilbudssjekkliste', QualityPageClassification::TYPE_CHECKLIST);
        $this->classified($customer, 'Internkontroll anskaffelser', QualityPageClassification::TYPE_CONTROL);

        $titles = fn (array $props): array => array_column($props['documents'], 'title');

        $overview = $this->actingAs($owner)->get('/app/quality')->viewData('page')['props'];
        $this->assertCount(4, $overview['documents'], 'Oversikt is the whole hierarchy.');

        $processes = $this->actingAs($owner)->get('/app/quality?tab=processes')->viewData('page')['props'];
        $this->assertSame(['Anskaffelsesprosess'], $titles($processes));

        $controls = $this->actingAs($owner)->get('/app/quality?tab=controls')->viewData('page')['props'];
        $this->assertSame(['Internkontroll anskaffelser'], $titles($controls));

        $checklists = $this->actingAs($owner)->get('/app/quality?tab=checklists')->viewData('page')['props'];
        $this->assertSame(['Tilbudssjekkliste'], $titles($checklists));
    }

    public function test_a_row_carries_the_wiki_page_it_classifies(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $page = $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY, 'POL-01');

        $props = $this->actingAs($owner)->get('/app/quality')->viewData('page')['props'];
        $row = $props['documents'][0];

        $this->assertSame((int) $page->id, $row['page_id']);
        $this->assertSame('POL-01', $row['quality_code']);
        // Kvalitet points at the content, it never copies it.
        $this->assertSame(route('app.wiki.show', ['slug' => $page->slug]), $row['wiki_url']);
        $this->assertNotNull($row['publication'], 'The Wiki publication state is reused, not recomputed.');
    }

    public function test_the_overview_offers_unclassified_pages_and_does_not_repeat_classified_ones(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $this->classified($customer, 'Innkjopspolicy', QualityPageClassification::TYPE_POLICY);
        $this->page($customer, 'Vanlig wiki-side');

        $props = $this->actingAs($owner)->get('/app/quality')->viewData('page')['props'];

        $this->assertSame(['Vanlig wiki-side'], array_column($props['unclassified_pages'], 'title'));
    }

    public function test_one_customers_quality_system_is_invisible_to_another(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context(name: 'Annen AS');
        $this->classified($otherCustomer, 'Fremmed policy', QualityPageClassification::TYPE_POLICY);

        $props = $this->actingAs($owner)->get('/app/quality')->viewData('page')['props'];

        $this->assertSame([], $props['documents']);
        $this->assertSame([], $props['unclassified_pages']);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * @return array{customer: Customer, owner: User}
     */
    private function context(string $name = 'Procynia AS', bool $grantQuality = true): array
    {
        $customer = $this->createCustomer($name);

        if ($grantQuality) {
            $this->grant($customer, 'quality');
        }

        return [
            'customer' => $customer,
            'owner' => $this->user($customer, User::BID_ROLE_SYSTEM_OWNER, 'owner'),
        ];
    }

    private function user(Customer $customer, string $bidRole, string $handle): User
    {
        return User::query()->create([
            'name' => Str::headline($handle),
            'email' => Str::slug($customer->slug).'.'.$handle.'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function page(Customer $customer, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'scope' => EnterpriseWikiPage::SCOPE_COMPANY,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_MANUAL,
        ]);
    }

    private function classified(
        Customer $customer,
        string $title,
        string $qualityType,
        ?string $code = null,
    ): EnterpriseWikiPage {
        $page = $this->page($customer, $title);

        QualityPageClassification::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'quality_type' => $qualityType,
            'quality_code' => $code,
            'source' => QualityPageClassification::SOURCE_MANUAL,
            'classified_at' => now(),
        ]);

        return $page;
    }

    private function relate(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $relationType,
    ): QualityRelation {
        return QualityRelation::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'relation_type' => $relationType,
            'source' => QualityRelation::SOURCE_MANUAL,
        ]);
    }

    private function grant(Customer $customer, string $packageKey): CustomerPackageEntitlement
    {
        return $customer->packageEntitlements()->updateOrCreate(
            ['package_key' => $packageKey],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );
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
}
