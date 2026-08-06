<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiBestPracticeSectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnterpriseWikiBestPracticeSectionService::mapBlocksToSections() — groups a page version's
 * best_practice content blocks into faglige seksjoner (heading block + its immediately-following
 * best_practice blocks) instead of leaving every heading and every paragraph as its own group.
 * Reused unchanged by EnterpriseWikiRunFindingsService (QA finding grouping) and WikiController
 * (Wiki page rendering) — this file only tests the shared boundary-detection rules themselves.
 */
class EnterpriseWikiBestPracticeSectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_heading_plus_two_paragraphs_is_one_section(): void
    {
        $version = $this->versionWithBlocks([
            $this->block('h1', 0, '## Begrepsramme: ITIL og Incident management'),
            $this->block('p1', 1, 'ITIL beskriver et rammeverk for tjenestestyring.'),
            $this->block('p2', 2, 'Incident management fokuserer på rask gjenoppretting.'),
        ]);

        $map = $this->service()->mapBlocksToSections($version);

        $this->assertSame($map['h1']['section_key'], $map['p1']['section_key']);
        $this->assertSame($map['h1']['section_key'], $map['p2']['section_key']);
        $this->assertSame('Begrepsramme: ITIL og Incident management', $map['p2']['heading_text']);
    }

    public function test_heading_plus_paragraph_plus_list_is_one_section(): void
    {
        $version = $this->versionWithBlocks([
            $this->block('h1', 0, '## Samhandling mellom kunde og leverandør'),
            $this->block('p1', 1, 'Roller og grensesnitt er tydelig avtalt mellom partene.'),
            $this->block('l1', 2, "- Eskalering\n- Prioritering\n- Sporbarhet"),
        ]);

        $map = $this->service()->mapBlocksToSections($version);

        $this->assertSame($map['h1']['section_key'], $map['p1']['section_key']);
        $this->assertSame($map['h1']['section_key'], $map['l1']['section_key']);
    }

    public function test_next_heading_starts_a_new_section(): void
    {
        $version = $this->versionWithBlocks([
            $this->block('h1', 0, '## Begrepsramme: ITIL og Incident management'),
            $this->block('p1', 1, 'Definisjon og formål.'),
            $this->block('h2', 2, '## Samhandling mellom kunde og leverandør'),
            $this->block('p2', 3, 'Roller og grensesnitt.'),
        ]);

        $map = $this->service()->mapBlocksToSections($version);

        $this->assertSame($map['h1']['section_key'], $map['p1']['section_key']);
        $this->assertSame($map['h2']['section_key'], $map['p2']['section_key']);
        $this->assertNotSame($map['h1']['section_key'], $map['h2']['section_key']);
        $this->assertSame('Samhandling mellom kunde og leverandør', $map['p2']['heading_text']);
    }

    public function test_higher_level_heading_ends_the_previous_section_correctly(): void
    {
        $version = $this->versionWithBlocks([
            $this->block('h1', 0, '# Om illustrasjonen'),
            $this->block('h2', 1, '## Underpunkt'),
            $this->block('p1', 2, 'Innhold under underpunktet.'),
            $this->block('h3', 3, '# Neste hovedseksjon'),
            $this->block('p2', 4, 'Innhold i neste hovedseksjon.'),
        ]);

        $map = $this->service()->mapBlocksToSections($version);

        // The H2 subheading is a deeper level than the open H1 section — it nests INTO it rather
        // than starting a new one, and its own following paragraph belongs to the same section.
        $this->assertSame($map['h1']['section_key'], $map['h2']['section_key']);
        $this->assertSame($map['h1']['section_key'], $map['p1']['section_key']);

        // The next H1 (same level as the original) ends the previous section correctly.
        $this->assertSame($map['h3']['section_key'], $map['p2']['section_key']);
        $this->assertNotSame($map['h1']['section_key'], $map['h3']['section_key']);
    }

    public function test_source_based_block_breaks_the_section(): void
    {
        $version = $this->versionWithBlocks([
            $this->block('h1', 0, '## Om illustrasjonen', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE),
            $this->block('s1', 1, 'Figuren under illustrerer samhandlingsprosessen.', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED),
            $this->block('p1', 2, 'En videre forklaring uten egen overskrift.', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE),
        ]);

        $map = $this->service()->mapBlocksToSections($version);

        // The source-based block is never itself part of any section.
        $this->assertArrayNotHasKey('s1', $map);
        // The best-practice paragraph AFTER the source-based block starts its own new section —
        // it must never be silently merged with the heading before the source-based break.
        $this->assertNotSame($map['h1']['section_key'], $map['p1']['section_key']);
    }

    public function test_different_page_versions_never_share_a_section_key(): void
    {
        $blocks = [$this->block('h1', 0, '## Samme overskrift'), $this->block('p1', 1, 'Samme tekst.')];
        $versionA = $this->versionWithBlocks($blocks);
        $versionB = $this->versionWithBlocks($blocks);

        $mapA = $this->service()->mapBlocksToSections($versionA);
        $mapB = $this->service()->mapBlocksToSections($versionB);

        $this->assertNotSame($mapA['h1']['section_key'], $mapB['h1']['section_key']);
    }

    public function test_unheaded_best_practice_block_still_gets_a_stable_deterministic_section(): void
    {
        $version = $this->versionWithBlocks([
            $this->block('p1', 0, 'En enkelt beste-praksis-setning uten overskrift.'),
        ]);

        $map = $this->service()->mapBlocksToSections($version);

        $this->assertSame($version->id.'|p1', $map['p1']['section_key']);
        $this->assertNull($map['p1']['heading_text']);
    }

    public function test_best_practice_block_without_reason_is_not_rendered_as_a_best_practice_section(): void
    {
        $version = $this->versionWithBlocks([
            array_merge($this->block('h1', 0, '## Mangler begrunnelse'), ['best_practice_reason' => null]),
            $this->block('p1', 1, 'Gyldig beste-praksis-tekst etter ugyldig blokk.'),
        ]);

        $map = $this->service()->mapBlocksToSections($version);

        $this->assertArrayNotHasKey('h1', $map);
        $this->assertSame($version->id.'|p1', $map['p1']['section_key']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiBestPracticeSectionService
    {
        return app(EnterpriseWikiBestPracticeSectionService::class);
    }

    private function block(string $blockKey, int $position, string $markdown, string $contentOrigin = EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE): array
    {
        return [
            'block_key' => $blockKey,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => $contentOrigin,
            'best_practice_reason' => $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                ? 'Procynia-generert beste praksis.'
                : null,
        ];
    }

    private function versionWithBlocks(array $blocks): EnterpriseWikiPageVersion
    {
        $customer = $this->createCustomer();
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'section-test-'.Str::lower(Str::random(8)),
            'title' => 'Section Test Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
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
            'name' => 'Section Service AS',
            'slug' => 'section-service-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
