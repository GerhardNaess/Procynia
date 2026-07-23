<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
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

    public function test_possible_content_deviation_without_block_key_uses_unique_page_excerpt_in_legacy_markdown(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Masterdata Samhandling');
        $targetParagraph = 'Strategiske roller ivaretar partnerskap, kontraktsoppfølging og langsiktig utvikling, taktiske roller følger opp tjenestekvalitet og endringer, mens operative roller håndterer daglig drift.';
        $version = $this->createLegacyVersionWithMarkdown($page, implode("\n\n", [
            '# Masterdata Samhandling',
            'Denne siden beskriver samhandling og styring.',
            '## Roller og ansvar',
            '[[roller-i-styringsmodellen|Rollestrukturen]] er utformet for å sikre tydelig ansvar. '.$targetParagraph,
            '## Nøkkelroller',
            'Leveransen ledes gjennom faste roller.',
        ]));
        $claim = $this->createClaim($page, $version, 'Strategiske roller ivaretar partnerskap, kontraktsoppfølging og langsiktig utvikling.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'claim_partially_supported',
            'content_block_key' => null,
            'page_excerpt' => $targetParagraph,
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'partially_supported',
                'unsupported_parts' => 'kontraktsoppfølging',
            ],
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($claim, $targetParagraph): bool {
            $ref = data_get($inertia, 'props.review_reference');
            $targetBlock = collect(data_get($inertia, 'props.current_version.content_blocks_json', []))
                ->firstWhere('block_key', 'markdown-block-0004');

            return $ref['status'] === 'ready'
                && $ref['claim_id'] === $claim->id
                && $ref['block_key'] === 'markdown-block-0004'
                && ($targetBlock['is_derived_from_markdown'] ?? false) === true
                && ($targetBlock['content_origin'] ?? null) === null
                && str_contains((string) ($targetBlock['raw_markdown'] ?? ''), $targetParagraph);
        });
    }

    public function test_non_editor_still_gets_highlighted_legacy_excerpt_block_without_edit_context(): void
    {
        $customer = $this->createCustomer();
        $user = User::query()->create([
            'name' => 'Read Only',
            'email' => Str::lower(Str::random(8)).'@navigasjon-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $page = $this->createPage($customer, 'Lesetilgang med funn');
        $targetParagraph = 'Den operative leveransen organiseres i tre tverrfaglige team med tydelig ansvarsdeling.';
        $version = $this->createLegacyVersionWithMarkdown($page, implode("\n\n", [
            '# Lesetilgang med funn',
            'Innledning.',
            '## Team',
            $targetParagraph,
        ]));
        $claim = $this->createClaim($page, $version, $targetParagraph, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => null,
            'page_excerpt' => $targetParagraph,
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'ready'
            && data_get($inertia, 'props.review_reference.block_key') === 'markdown-block-0004'
            && data_get($inertia, 'props.can_edit_wiki_claims') === false
            && data_get($inertia, 'props.manual_block_edit') === null);
    }

    public function test_missing_block_key_without_unique_excerpt_keeps_no_confident_block_fallback(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Duplisert tekst');
        $repeatedParagraph = 'Samme avsnitt kan finnes mer enn ett sted og må ikke markeres vilkårlig.';
        $version = $this->createLegacyVersionWithMarkdown($page, implode("\n\n", [
            '# Duplisert tekst',
            $repeatedParagraph,
            '## Gjentakelse',
            $repeatedParagraph,
        ]));
        $claim = $this->createClaim($page, $version, $repeatedParagraph, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => null,
            'page_excerpt' => $repeatedParagraph,
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'ready'
            && data_get($inertia, 'props.review_reference.claim_id') === $claim->id
            && data_get($inertia, 'props.review_reference.block_key') === null);
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

    /**
     * A block whose content_block_key still exists in content_blocks_json but whose markdown was
     * blanked (e.g. the "Fjern"/remove-text action on a best-practice suggestion — see
     * WikiClaimController and EnterpriseWikiUnsupportedAdditionAcceptanceTest) is never actually
     * rendered as a #wiki-block-{key} element (renderedContentBlocks() filters empty-markdown
     * blocks out). buildReviewReference() must apply the exact same filter, or it falsely reports
     * 'ready' for a block the frontend cannot actually scroll to or highlight — silently doing
     * nothing with no error shown to the user.
     */
    public function test_a_blanked_removed_block_reports_block_missing_not_a_false_ready(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Fjernet tekst');
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Fjernet tekst'],
            ['block_key' => 'block-0002', 'markdown' => ''],
        ]);
        $claim = $this->createClaim($page, $version, 'Forslag som ble fjernet.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0002',
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'block_missing');
    }

    public function test_a_claim_id_not_belonging_to_this_page_reports_not_found(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Riktig side');
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Riktig side'],
        ]);
        $otherPage = $this->createPage($customer, 'Annen side');
        $otherVersion = $this->createVersionWithBlocks($otherPage, [
            ['block_key' => 'block-0001', 'markdown' => '# Annen side'],
        ]);
        $foreignClaim = $this->createClaim($otherPage, $otherVersion, 'Påstand på en annen side.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0001',
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$foreignClaim->id}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'not_found');
    }

    public function test_kjoringer_funn_action_url_resolves_to_the_correct_highlighted_block(): void
    {
        // End-to-end: the exact URL EnterpriseWikiRunFindingsService puts on the "Åpne og
        // behandle" action must itself resolve to the claim's own block when followed, and
        // preserve the originating runs tab so the back-link can return to the same run row.
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
        // No deterministic_reason: a plain "not supported, no specific dimension flagged" verdict
        // is still a genuine user-facing case under the v0.7 rule (unlike a claim flagged only by
        // an internal comparison-mechanism signal such as modality_mismatch, which is excluded —
        // see EnterpriseWikiBestPracticeSuggestionTest's dimension-mismatch tests).
        $claim = $this->createClaim($page, $version, 'Påstand navigert fra Kjøringer -> Funn.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0002',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
            ],
        ]);
        $this->createSourceReference($claim, $customer);

        $findings = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, $user, false);
        $finding = collect($findings['findings'])->firstWhere('claim_id', $claim->id);

        $this->assertNotNull($finding, 'Expected a claim-defect finding for the created claim.');
        $this->assertNotNull($finding['url']);
        $this->assertStringContainsString('claim_id='.$claim->id, $finding['url']);

        $parsedUrlQuery = [];
        parse_str((string) parse_url($finding['url'], PHP_URL_QUERY), $parsedUrlQuery);
        $this->assertSame(
            route('app.wiki.index', ['tab' => 'runs', 'run_src' => $document->id]),
            $parsedUrlQuery['back_url'] ?? null,
        );

        $path = parse_url($finding['url'], PHP_URL_PATH).'?'.parse_url($finding['url'], PHP_URL_QUERY);
        $response = $this->actingAs($user)->get($path);

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.review_reference.status') === 'ready'
            && data_get($inertia, 'props.review_reference.block_key') === 'block-0002'
            && data_get($inertia, 'props.review_reference.back_url') === route('app.wiki.index', ['tab' => 'runs', 'run_src' => $document->id]));
    }

    public function test_structural_open_page_finding_sends_stable_context_and_shows_page_panel(): void
    {
        app()->setLocale('no');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $document);
        $page = $this->createPage($customer, 'Begrepsside uten lenker', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $version = $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Begrepsside uten lenker'],
        ]);
        $this->createRunPage($run, $page, $version);
        $lintFinding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => null,
            'enterprise_wiki_claim_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Begrepsside er ikke koblet til andre sider.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $findings = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, $user, false);
        $finding = collect($findings['findings'])->firstWhere('category', EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE);

        $this->assertNotNull($finding);
        $this->assertSame('view_page', $finding['action']);
        $this->assertStringNotContainsString('claim_id=', (string) $finding['url']);
        $this->assertStringContainsString('finding_id='.$lintFinding->id, (string) $finding['url']);

        $path = parse_url($finding['url'], PHP_URL_PATH).'?'.parse_url($finding['url'], PHP_URL_QUERY);
        $response = $this->actingAs($user)->get($path);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($lintFinding, $document, $page): bool {
            $structureFinding = data_get($inertia, 'props.structure_finding', []);

            return data_get($inertia, 'props.review_reference') === null
                && ($structureFinding['id'] ?? null) === $lintFinding->id
                && ($structureFinding['code'] ?? null) === EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE
                && ($structureFinding['category_label'] ?? null) === 'Begrepsside er ikke koblet til andre sider'
                && ($structureFinding['description'] ?? null) === 'Begrepssiden har ingen lenker til artikkel- eller sammendragssider.'
                && ($structureFinding['message'] ?? null) === 'Begrepsside er ikke koblet til andre sider.'
                && ($structureFinding['page_id'] ?? null) === $page->id
                && ($structureFinding['page_type'] ?? null) === EnterpriseWikiPage::PAGE_TYPE_CONCEPT
                && ($structureFinding['back_url'] ?? null) === route('app.wiki.index', ['tab' => 'runs', 'run_src' => $document->id])
                && ! array_key_exists('block_key', $structureFinding);
        });
    }

    public function test_direct_page_open_does_not_show_structure_finding_panel(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Vanlig side');
        $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Vanlig side'],
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.structure_finding') === null
            && data_get($inertia, 'props.review_reference') === null);
    }

    public function test_structure_finding_context_is_scoped_to_customer_and_page(): void
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer('Annen navigasjonskunde');
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'Riktig side');
        $otherPage = $this->createPage($other, 'Annen side');
        $wrongPage = $this->createPage($customer, 'Feil side');
        $this->createVersionWithBlocks($page, [
            ['block_key' => 'block-0001', 'markdown' => '# Riktig side'],
        ]);
        $foreignFinding = $this->createStructureFinding($other, $otherPage);
        $wrongPageFinding = $this->createStructureFinding($customer, $wrongPage);

        $foreignResponse = $this->actingAs($user)->get("/app/wiki/{$page->slug}?finding_id={$foreignFinding->id}");
        $wrongPageResponse = $this->actingAs($user)->get("/app/wiki/{$page->slug}?finding_id={$wrongPageFinding->id}");
        $invalidResponse = $this->actingAs($user)->get("/app/wiki/{$page->slug}?finding_id=not-a-number");

        $foreignResponse->assertOk();
        $wrongPageResponse->assertOk();
        $invalidResponse->assertOk();

        foreach ([$foreignResponse, $wrongPageResponse, $invalidResponse] as $response) {
            $response->assertViewHas('page', fn (array $inertia): bool => data_get($inertia, 'props.structure_finding') === null
                && data_get($inertia, 'props.review_reference') === null);
        }
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

    private function createPage(
        Customer $customer,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
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

    private function createLegacyVersionWithMarkdown(EnterpriseWikiPage $page, string $markdown): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => [],
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

    private function createStructureFinding(Customer $customer, EnterpriseWikiPage $page): EnterpriseWikiLintFinding
    {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_claim_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Begrepsside er ikke koblet til andre sider.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
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
