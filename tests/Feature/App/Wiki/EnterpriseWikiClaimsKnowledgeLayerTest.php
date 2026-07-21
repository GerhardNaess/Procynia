<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v0.8 binding presiseringsregel (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.8"):
 * claims er Procynias atomære, maskinlesbare kunnskapslag og skal fortsatt brukes aktivt av
 * relasjonsbyggende prosesser — v0.7's endring av hvilke claims som blir en BRUKERSAK i Kjøringer
 * → Funn-panelet (EnterpriseWikiRunFindingsService) må aldri leses som at claims som datastruktur
 * er mindre viktige eller kan filtreres bort andre steder.
 *
 * Disse testene beviser at commit 39d62c3 (v0.7) ikke utilsiktet filtrerte claims bort fra
 * EnterpriseWikiGraphDataService (den ene relasjonsbyggende prosessen som faktisk leser claims i
 * dag — som et visnings-metrikk, ikke som kanter, se kartleggingen i v0.8-notatet), og at en
 * claims fulle proveniens (content_block_key, sideversjon, kildereferanser) forblir intakt og
 * spørbar uavhengig av content_origin eller interne verifiseringssignaler.
 */
class EnterpriseWikiClaimsKnowledgeLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_based_claim_is_counted_in_graph_relationship_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Kildestøttet side');
        $version = $this->createVersion($page, true);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame(1, $node['claim_count']);
    }

    public function test_best_practice_claim_is_also_counted_in_graph_relationship_data(): void
    {
        // v0.8: best-practice claims are permitted knowledge-layer relations too — they must not
        // be silently excluded from the graph's claim metric just because v0.7 hid them as a
        // separate user-facing "unsupported" finding category in a different panel.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Beste praksis side');
        $version = $this->createVersion($page, true);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame(2, $node['claim_count'], 'Both source_based and best_practice claims must count toward the relationship graph metric.');
    }

    public function test_a_claim_flagged_by_an_internal_mismatch_signal_is_still_counted_not_removed(): void
    {
        // v0.8: "Claims skal ikke fjernes, deaktiveres eller reduseres til kun en
        // verifiseringsmekanisme." A claim carrying a negation_mismatch signal (hidden from the
        // Funn user-facing panel per v0.7) must still exist as real data and still count here.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Negasjonsavvik side');
        $version = $this->createVersion($page, true);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, [
            'content_block_key' => 'block-0001',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'deterministic_reason' => 'negation_mismatch',
            ],
        ]);

        $this->assertSame(1, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $page->id)->count());

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame(1, $node['claim_count']);
    }

    public function test_claim_relation_is_traceable_to_block_version_and_source_with_correct_provenance_type(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPage($customer, 'Sporbar side');
        $version = $this->createVersion($page, true);

        $sourceBasedClaim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, [
            'content_block_key' => 'block-source',
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $sourceBasedClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => hash('sha256', 'x'),
            'excerpt' => 'Kildeutdrag.',
        ]);

        $bestPracticeClaim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, [
            'content_block_key' => 'block-best-practice',
        ]);

        // Traceable: claim -> content_block_key -> page version -> (for source_based) document +
        // source_elements. A best_practice claim is traceable to the same chain minus a source
        // document, and its provenance type is explicit — never presented as documented fact.
        $this->assertSame('block-source', $sourceBasedClaim->fresh()->content_block_key);
        $this->assertSame($version->id, $sourceBasedClaim->fresh()->enterprise_wiki_page_version_id);
        $this->assertTrue($sourceBasedClaim->fresh()->sourceReferences()->exists());
        $this->assertSame($document->id, $sourceBasedClaim->fresh()->sourceReferences()->first()->source_id);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $sourceBasedClaim->fresh()->content_origin);

        $this->assertSame('block-best-practice', $bestPracticeClaim->fresh()->content_block_key);
        $this->assertSame($version->id, $bestPracticeClaim->fresh()->enterprise_wiki_page_version_id);
        $this->assertFalse($bestPracticeClaim->fresh()->sourceReferences()->exists());
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $bestPracticeClaim->fresh()->content_origin);
    }

    public function test_mixed_provenance_page_retains_both_claim_types_distinctly(): void
    {
        // "Mixed" grunnlagstype: a single page's current version legitimately carries both
        // source_based and best_practice claims side by side — each must remain independently
        // identifiable by its own content_origin, never collapsed into one undifferentiated type.
        $customer = $this->createCustomer();
        $page = $this->createPage($customer, 'Blandet grunnlag side');
        $version = $this->createVersion($page, true);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, ['content_block_key' => 'block-1']);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, ['content_block_key' => 'block-2']);

        $claims = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->get();

        $this->assertCount(2, $claims);
        $this->assertCount(1, $claims->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED));
        $this->assertCount(1, $claims->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Kunnskapslag AS'): Customer
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

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Knowledge Layer Tester',
            'email' => Str::lower(Str::random(8)).'@knowledge-layer-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kilde.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createVersion(EnterpriseWikiPage $page, bool $isCurrent = false): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => $isCurrent,
            'content_markdown' => '# '.$page->title,
        ]);
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $contentOrigin,
        array $overrides = [],
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test claim.',
            'content_origin' => $contentOrigin,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => 0,
        ], $overrides));
    }
}
