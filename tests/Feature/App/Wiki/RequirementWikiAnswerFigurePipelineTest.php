<?php

namespace Tests\Feature\App\Wiki;

use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use App\Services\Ai\Wiki\RequirementWikiPageReader;
use App\Services\Ai\Wiki\RequirementWikiTermNormalizer;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The path a figure travels before it can ever be offered to the model: it must be present on the
 * page's current version, and the page must actually have been read — including, on a long page,
 * the specific section the figure sits in.
 */
class RequirementWikiAnswerFigurePipelineTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
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

    private function imageBlock(string $imageKey, int $figureNumber): array
    {
        return [
            'block_type' => 'image',
            'source_id' => 63,
            'markdown' => sprintf("**Figur %d**\n_Kilde: Masterdata.docx → Figur %d_", $figureNumber, $figureNumber),
            'page_reference' => sprintf('Masterdata.docx → Figur %d', $figureNumber),
            'image_data' => [
                'source_image_key' => $imageKey,
                'figure_number' => $figureNumber,
                'caption' => null,
                'alt_text' => '',
                'description' => 'Beskrivelse.',
            ],
        ];
    }

    public function test_the_catalog_carries_a_pages_figures_alongside_its_text(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion(
            $customer,
            'Samhandling',
            "# Samhandling\n\nTekst.",
            [],
            ['content_blocks_json' => [
                ['block_type' => 'paragraph', 'markdown' => 'Tekst.'],
                $this->imageBlock('img3', 4),
            ]],
        );

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertCount(1, $catalog);
        $this->assertCount(1, $catalog[0]['figures']);
        $this->assertSame('fig:63:img3', $catalog[0]['figures'][0]['figure_ref']);
    }

    public function test_a_page_with_no_figures_reports_an_empty_figure_list(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Endringsstyring', "# Endringsstyring\n\nTekst.");

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertSame([], $catalog[0]['figures']);
    }

    public function test_a_fully_read_page_offers_every_figure_it_has(): void
    {
        $entry = [
            'content_markdown' => "# Samhandling\n\nKort side.",
            'figures' => [
                ['figure_ref' => 'fig:63:img3', 'block_markdown' => '**Figur 4**'],
                ['figure_ref' => 'fig:63:img8', 'block_markdown' => '**Figur 9**'],
            ],
        ];

        $read = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('samhandling'));

        $this->assertSame('full', $read['content_mode']);
        $this->assertCount(2, $read['figures']);
    }

    public function test_a_sectioned_read_never_offers_a_figure_from_a_section_it_skipped(): void
    {
        $lead = "# Samhandling\n\nInnledning til samhandlingsmodellen.\n\n";
        $relevant = "## Samhandlingsmodell\n\n".str_repeat('Denne seksjonen handler om samhandlingsmodellen i detalj. ', 20)
            ."\n\n**Figur 4**\n_Kilde: Masterdata.docx → Figur 4_\n\n";
        $irrelevant = "## Historikk\n\n".str_repeat('Helt urelatert innhold om noe annet. ', 20)
            ."\n\n**Figur 9**\n_Kilde: Masterdata.docx → Figur 9_\n\n";
        $padding = "## Fyll\n\n".str_repeat('Fylltekst som gjør siden lang nok til seksjonering. ', 100);

        $entry = [
            'content_markdown' => $lead.$irrelevant.$relevant.$padding,
            'figures' => [
                ['figure_ref' => 'fig:63:img3', 'block_markdown' => "**Figur 4**\n_Kilde: Masterdata.docx → Figur 4_"],
                ['figure_ref' => 'fig:63:img8', 'block_markdown' => "**Figur 9**\n_Kilde: Masterdata.docx → Figur 9_"],
            ],
        ];

        $read = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('samhandlingsmodell'));

        $this->assertSame('sections', $read['content_mode']);
        $this->assertStringContainsString('**Figur 4**', $read['content_markdown']);
        $this->assertStringNotContainsString('**Figur 9**', $read['content_markdown']);
        $this->assertSame(['fig:63:img3'], array_column($read['figures'], 'figure_ref'));
    }
}
