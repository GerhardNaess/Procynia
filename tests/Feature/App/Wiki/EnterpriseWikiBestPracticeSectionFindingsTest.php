<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Best-practice QA findings are grouped by faglig seksjon (EnterpriseWikiBestPracticeSectionService),
 * not by raw content_block_key — several claims spread across a heading's blocks (heading +
 * paragraph(s)/list) are ONE QA finding, matching the real "Incident Management Illustration"
 * pattern that motivated this task: a heading and its following paragraph are separate content
 * blocks (WikiPageContentAiClient/EnterpriseWikiPageContentBlockService split one per heading and
 * one per paragraph), which previously surfaced as unrelated, disconnected findings.
 */
class EnterpriseWikiBestPracticeSectionFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_several_claims_in_the_same_section_produce_one_finding(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration', [
            $this->block('block-0003', 0, '## Om illustrasjonen'),
            $this->block('block-0004', 1, 'Illustrasjoner av samhandling fremstiller roller og grensesnitt.'),
        ]);
        $version = $this->currentVersion($article);

        $claimA = $this->createClaim($article, $version, 'block-0004', 0, 'Illustrasjoner av samhandling fremstiller roller og grensesnitt.');
        $claimB = $this->createClaim($article, $version, 'block-0004', 1, 'Visuelle referanser gir en felles forståelse av ansvar.');

        $result = $this->findingsService()->buildForRun($run, $user, true);
        $bestPractice = collect($result['findings'])->where('category', 'best_practice_suggestion');

        $this->assertCount(1, $bestPractice);
        $finding = $bestPractice->first();
        $this->assertSame('Om illustrasjonen', $finding['title']);
        $this->assertSame(2, $finding['claim_count']);
        $this->assertSame([$claimA->id, $claimB->id], $finding['technical']['claim_ids']);
        $this->assertSame(['block-0004'], $finding['block_keys']);
        $this->assertStringContainsString('Illustrasjoner av samhandling', $finding['section_text']);
        $this->assertStringContainsString('Visuelle referanser', $finding['section_text']);
    }

    public function test_claims_in_different_sections_are_not_merged(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration', [
            $this->block('block-0005', 0, '## Begrepsramme: ITIL og Incident management'),
            $this->block('block-0006', 1, 'ITIL beskriver et rammeverk for tjenestestyring.'),
            $this->block('block-0007', 2, '## Samhandling mellom kunde og leverandør'),
            $this->block('block-0008', 3, 'Roller og grensesnitt er tydelig avtalt.'),
        ]);
        $version = $this->currentVersion($article);

        $this->createClaim($article, $version, 'block-0006', 0, 'ITIL beskriver et rammeverk for tjenestestyring.');
        $this->createClaim($article, $version, 'block-0008', 1, 'Roller og grensesnitt er tydelig avtalt.');

        $result = $this->findingsService()->buildForRun($run, $user, false);
        $bestPractice = collect($result['findings'])->where('category', 'best_practice_suggestion');

        $this->assertCount(2, $bestPractice);
        $titles = $bestPractice->pluck('title')->sort()->values()->all();
        $this->assertSame(['Begrepsramme: ITIL og Incident management', 'Samhandling mellom kunde og leverandør'], $titles);
    }

    public function test_finding_count_reflects_sections_not_underlying_claims(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration', [
            $this->block('block-0009', 0, '## Bruksområde for illustrasjonen'),
            $this->block('block-0010', 1, 'En felles visuell referanse understøtter opplæring.'),
        ]);
        $version = $this->currentVersion($article);

        // Four distinct claims, all in the one section — the section is still exactly one row.
        for ($i = 0; $i < 4; $i++) {
            $this->createClaim($article, $version, 'block-0010', $i, "Setning nummer {$i} i samme seksjon.");
        }

        $result = $this->findingsService()->buildForRun($run, $user, false);
        $bestPractice = collect($result['findings'])->where('category', 'best_practice_suggestion');

        $this->assertCount(1, $bestPractice);
        $this->assertSame(4, $bestPractice->first()['claim_count']);
    }

    public function test_a_genuine_content_deviation_is_never_merged_into_the_best_practice_group(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration', [
            $this->block('block-0005', 0, '## Begrepsramme: ITIL og Incident management'),
            $this->block('block-0006', 1, 'ITIL beskriver et rammeverk for tjenestestyring.'),
        ]);
        $version = $this->currentVersion($article);

        $this->createClaim($article, $version, 'block-0006', 0, 'ITIL beskriver et rammeverk for tjenestestyring.');

        $deviation = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Kunden har fem godkjente eskaleringsnivåer definert i sin styringsmodell.',
            'page_excerpt' => 'Kunden har fem godkjente eskaleringsnivåer definert i sin styringsmodell.',
            'content_block_key' => 'block-0006',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
            'review_metadata' => ['classification_basis' => 'semantic_verification', 'verdict' => 'not_supported'],
        ]);

        $result = $this->findingsService()->buildForRun($run, $user, true);
        $bestPractice = collect($result['findings'])->where('category', 'best_practice_suggestion');
        $claimDefects = collect($result['findings'])->where('category', '!=', 'best_practice_suggestion');

        $this->assertCount(1, $bestPractice);
        $this->assertSame(1, $bestPractice->first()['claim_count']);
        $this->assertFalse(in_array($deviation->id, $bestPractice->first()['technical']['claim_ids'], true));
        $this->assertTrue($claimDefects->contains(fn (array $f): bool => ($f['claim_id'] ?? null) === $deviation->id));
    }

    public function test_works_for_concept_and_entity_pages_too(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);

        foreach ([EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiPage::PAGE_TYPE_ENTITY] as $pageType) {
            $page = $this->createVersionedPage($customer, $run, $pageType, 'Page '.$pageType, [
                $this->block('h', 0, '## En seksjon'),
                $this->block('p', 1, 'Innhold i seksjonen.'),
            ]);
            $version = $this->currentVersion($page);
            $this->createClaim($page, $version, 'p', 0, 'Innhold i seksjonen.');
        }

        $result = $this->findingsService()->buildForRun($run, $user, false);
        $bestPractice = collect($result['findings'])->where('category', 'best_practice_suggestion');

        $this->assertCount(2, $bestPractice);
        $this->assertTrue($bestPractice->every(fn (array $f): bool => $f['title'] === 'En seksjon'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function findingsService(): EnterpriseWikiRunFindingsService
    {
        return app(EnterpriseWikiRunFindingsService::class);
    }

    private function block(string $blockKey, int $position, string $markdown, string $contentOrigin = EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE): array
    {
        return [
            'block_key' => $blockKey,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => $contentOrigin,
        ];
    }

    private function createCustomer(): Customer
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
            'name' => 'Section Findings AS',
            'slug' => 'section-findings-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@section-findings-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source document text for section findings tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
        array $blocks,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $contentBlockKey,
        int $positionOrder,
        string $claimText,
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $claimText,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => $contentBlockKey,
            'position_order' => $positionOrder,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }
}
