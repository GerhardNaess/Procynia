<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Direct navigation ("Åpne og behandle" from Kjøringer → Funn, and the equivalent ?claim_id= deep
 * link from Kvalitet) must open the correct Wiki page/version, scroll to the claim's concrete
 * content_block_key, and highlight it — for EVERY claim-finding category (confirmed content
 * deviation, possible content deviation, and technical uncertainty), not just best_practice
 * suggestions (the only category EnterpriseWikiBestPracticeSuggestionTest already covers).
 *
 * WikiController::show()'s ?claim_id= → buildReviewReference() → {status, block_key} resolution
 * and Show.jsx's targetBlockKey/highlight rendering are generic — they never look at
 * content_origin — so this is the same mechanism, not a parallel one, just proven here for the
 * categories that were missing coverage.
 */
class WikiClaimDefectReviewReferenceNavigationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_confirmed_content_deviation_navigates_and_highlights_its_block(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Bekreftet avvik');
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Bekreftet avvik'],
            ['block_key' => 'block-0002', 'markdown' => 'Hendelseshåndtering registrerer og prioriterer alle henvendelser.'],
        ]);
        $claim = $this->createClaim($page, $version, 'Hendelseshåndtering registrerer og prioriterer alle henvendelser.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0002',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'deterministic_reason' => 'actor_mismatch',
            ],
        ]);
        $this->createSourceReference($claim, $customer);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($claim): bool {
            $ref = data_get($inertia, 'props.review_reference');

            return $ref['status'] === 'ready'
                && $ref['claim_id'] === $claim->id
                && $ref['block_key'] === 'block-0002';
        });
    }

    public function test_possible_content_deviation_navigates_and_highlights_its_block(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Mulig avvik');
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Mulig avvik'],
            ['block_key' => 'block-0002', 'markdown' => 'Leverandøren bruker ITIL som styringsverktøy.'],
        ]);
        $claim = $this->createClaim($page, $version, 'Leverandøren bruker ITIL som styringsverktøy.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0002',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'checks' => ['actor' => 'match', 'modality' => 'match'],
            ],
        ]);
        $this->createSourceReference($claim, $customer);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'ready'
            && data_get($inertia, 'props.review_reference.block_key') === 'block-0002');
    }

    public function test_technical_uncertainty_with_known_block_navigates_and_highlights_its_block(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Teknisk usikkerhet med blokk');
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Teknisk usikkerhet med blokk'],
            ['block_key' => 'block-0002', 'markdown' => 'En påstand med kjent blokk men usikkert kildegrunnlag.'],
        ]);
        $claim = $this->createClaim($page, $version, 'En påstand med kjent blokk men usikkert kildegrunnlag.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'generation_issue' => 'genuine_content_mismatch',
            'content_block_key' => 'block-0002',
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'ready'
            && data_get($inertia, 'props.review_reference.block_key') === 'block-0002');
    }

    public function test_technical_uncertainty_without_confident_block_shows_honest_fallback(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Ingen sikker blokk');
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Ingen sikker blokk'],
        ]);
        // No content_block_key at all — the claim was never anchored to a specific block.
        $claim = $this->createClaim($page, $version, 'En påstand uten kjent blokk.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'generation_issue' => 'genuine_content_mismatch',
            'content_block_key' => null,
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        // status stays 'ready' (the claim itself resolved fine) but block_key is null — the
        // frontend must render an explicit "could not highlight a specific paragraph" message
        // for this case instead of silently opening the page with nothing highlighted.
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'ready'
            && data_get($inertia, 'props.review_reference.block_key') === null);
    }

    public function test_kjoringer_funn_action_url_resolves_to_the_correct_highlighted_block(): void
    {
        // End-to-end: the exact URL EnterpriseWikiRunFindingsService puts on the "Åpne og
        // behandle" action must itself resolve to the claim's own block when followed.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $document);
        $page = $this->createPage($customer, 'Fra Kjøringer');
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Fra Kjøringer'],
            ['block_key' => 'block-0002', 'markdown' => 'Påstand navigert fra Kjøringer -> Funn.'],
        ]);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Påstand navigert fra Kjøringer -> Funn.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0002',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'deterministic_reason' => 'modality_mismatch',
            ],
        ]);
        $this->createSourceReference($claim, $customer);

        $findings = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, $user, false);
        $finding = collect($findings['findings'])->firstWhere('claim_id', $claim->id);

        $this->assertNotNull($finding, 'Expected a claim-defect finding for the created claim.');
        $this->assertNotNull($finding['url']);
        $this->assertStringContainsString('claim_id='.$claim->id, $finding['url']);

        $path = parse_url($finding['url'], PHP_URL_PATH).'?'.parse_url($finding['url'], PHP_URL_QUERY);
        $response = $this->actingAs($user)->get($path);

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'ready'
            && data_get($inertia, 'props.review_reference.block_key') === 'block-0002');
    }

    private function createCustomer(string $name = 'Navigasjon AS'): Customer
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
            'name' => 'Navigasjon Tester',
            'email' => Str::lower(Str::random(8)).'@navigasjon-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
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

    private function createVersionWithBlocks(EnterpriseWikiPage $page, array $blocks): EnterpriseWikiPageVersion
    {
        $markdown = "# {$page->title}\n\n".implode("\n\n", array_column($blocks, 'markdown'));

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => array_map(
                fn (array $block, int $position): array => array_merge($block, ['position' => $position]),
                $blocks,
                array_keys($blocks),
            ),
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function createClaim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $text, array $overrides = []): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'page_excerpt' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => 0,
            'verified_at' => now(),
        ], $overrides));
    }

    private function createSourceReference(EnterpriseWikiClaim $claim, Customer $customer): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => 1,
            'source_label' => 'Kilde',
            'source_hash' => str_pad('h', 64, '0'),
            'excerpt' => 'Brukerstøtte håndterer registrering og prioritering av hendelser og forespørsler.',
            'page_reference' => null,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'test-document.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createIngestRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', "enterprise_wiki_document:{$document->id}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);
    }

    private function createRunPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
    }
}
