<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiInvalidWikilinksException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkIntentMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * The model expresses link INTENT; the backend owns all link SYNTAX.
 *
 * Run 59 failed on page 214 with "wikilink marker syntax was malformed". The anchor and its position
 * used to be written by the model as {{wiki_link:intent-id|visible anchor}} inside free-text
 * markdown — the one part of the response no schema reaches. The page was
 * "Hendelseshåndtering (Incident Management)": an intent id carrying a Norwegian letter, or an
 * anchor carrying a pipe or a brace, produces a token the materializer cannot parse, and an
 * unparseable token failed the page and the whole run.
 *
 * The structural cause was that the same value had to be written TWICE — once into a
 * schema-validated field, once as free text — and only one copy could be validated. anchor_text is
 * now a structured field, the markdown carries no link syntax at all, and the backend inserts the
 * canonical link itself. A structured string cannot be malformed.
 *
 * What these tests hold: identity stays server-authoritative, the slug stays canonical, unknown /
 * self / cross-customer targets still hard-fail, and no internal syntax can reach a persisted page.
 */
class EnterpriseWikiLinkIntentAnchorContractTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // The model no longer authors syntax
    // =========================================================================

    public function test_the_anchor_is_a_structured_field_not_free_text(): void
    {
        $item = (new ReflectionClass(WikiPageContentAiClient::class))
            ->getMethod('blockItemSchema')
            ->invoke(null, [])['properties']['link_intents']['items'];

        $this->assertSame(
            ['intent_id', 'target_page_id', 'anchor_text', 'reason'],
            $item['required'],
        );
        $this->assertSame(1, $item['properties']['anchor_text']['minLength']);
        $this->assertFalse($item['additionalProperties']);
    }

    public function test_no_prompt_asks_the_model_to_write_link_syntax(): void
    {
        $client = app(WikiPageContentAiClient::class);
        $reflection = new ReflectionClass($client);

        foreach ([
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            EnterpriseWikiPage::PAGE_TYPE_ENTITY,
        ] as $pageType) {
            $prompt = $reflection->getMethod('developerPrompt')->invoke($client, $pageType, 'Norwegian', []);

            $this->assertStringNotContainsString('{{wiki_link:', $prompt, $pageType);
            $this->assertStringContainsString('anchor_text', $prompt, $pageType);
            $this->assertStringContainsString('Never write [[...]]', $prompt, $pageType);
        }
    }

    // =========================================================================
    // Materialization
    // =========================================================================

    public function test_a_valid_intent_is_materialized_on_its_anchor_with_the_canonical_slug(): void
    {
        [$run, $source, $target] = $this->scenario();

        $blocks = $this->materialize($run, $source, [
            $this->block('Hendelser følger fastsatt prosess i driften.', [
                $this->intent('l1', $target->id, 'fastsatt prosess'),
            ]),
        ], [$this->catalogEntry($target)]);

        $this->assertSame(
            "Hendelser følger [[{$target->slug}|fastsatt prosess]] i driften.",
            $blocks[0]['markdown'],
        );
        $this->assertCount(1, $blocks[0]['link_intents']);
    }

    public function test_a_norwegian_anchor_with_punctuation_is_placed_verbatim(): void
    {
        // The exact shape that broke run 59: accents, parentheses, and a slash in the visible words.
        [$run, $source, $target] = $this->scenario('Hendelseshåndtering (Incident Management)', 'hendelseshåndtering-incident-management');

        $blocks = $this->materialize($run, $source, [
            $this->block('Prosessen for hendelseshåndtering (P1/P2) er beskrevet i avtalen.', [
                $this->intent('l1', $target->id, 'hendelseshåndtering (P1/P2)'),
            ]),
        ], [$this->catalogEntry($target)]);

        $this->assertSame(
            'Prosessen for [[hendelseshåndtering-incident-management|hendelseshåndtering (P1/P2)]] er beskrevet i avtalen.',
            $blocks[0]['markdown'],
        );
    }

    public function test_several_links_on_the_same_block_are_placed_independently(): void
    {
        [$run, $source, $first] = $this->scenario();
        $second = $this->createPage($run->customer_id, 'Endringsstyring', 'endringsstyring-ab1c2d');

        $blocks = $this->materialize($run, $source, [
            $this->block('Både hendelser og endringer styres av avtalen.', [
                $this->intent('l1', $first->id, 'hendelser'),
                $this->intent('l2', $second->id, 'endringer'),
            ]),
        ], [$this->catalogEntry($first), $this->catalogEntry($second)]);

        $this->assertSame(
            "Både [[{$first->slug}|hendelser]] og [[{$second->slug}|endringer]] styres av avtalen.",
            $blocks[0]['markdown'],
        );
        $this->assertCount(2, $blocks[0]['link_intents']);
    }

    public function test_a_second_intent_never_lands_inside_an_already_materialized_link(): void
    {
        // Both anchors are the same word. The first consumes the first occurrence; the second must
        // take the next one rather than corrupting the link already written.
        [$run, $source, $first] = $this->scenario();
        $second = $this->createPage($run->customer_id, 'Drift', 'drift-ab1c2d');

        $blocks = $this->materialize($run, $source, [
            $this->block('Drift er beskrevet i avtalen, og drift følges opp månedlig.', [
                $this->intent('l1', $first->id, 'drift'),
                $this->intent('l2', $second->id, 'drift'),
            ]),
        ], [$this->catalogEntry($first), $this->catalogEntry($second)]);

        $this->assertSame(
            "Drift er beskrevet i avtalen, og [[{$first->slug}|drift]] følges opp månedlig.",
            $blocks[0]['markdown'],
            'the first intent takes the first unlinked occurrence',
        );
        $this->assertCount(1, $blocks[0]['link_intents'], 'the second had no unlinked occurrence left and was dropped');
    }

    public function test_an_anchor_inside_a_markdown_link_is_never_wrapped_again(): void
    {
        // Observed on the real page 214 regeneration: the model still writes ordinary Markdown links
        // on its own. Wrapping an anchor that sits inside one would produce [[[slug|text]](url) —
        // broken syntax from a valid intent, the exact failure class this change removes.
        [$run, $source, $target] = $this->scenario();

        $blocks = $this->materialize($run, $source, [
            $this->block('Se [Servicedesk](https://intranett.example/sd) og Servicedesk ellers.', [
                $this->intent('l1', $target->id, 'Servicedesk'),
            ]),
        ], [$this->catalogEntry($target)]);

        $this->assertSame(
            "Se [Servicedesk](https://intranett.example/sd) og [[{$target->slug}|Servicedesk]] ellers.",
            $blocks[0]['markdown'],
        );
        $this->assertStringNotContainsString('[[[', $blocks[0]['markdown']);
    }

    public function test_an_anchor_only_present_inside_a_markdown_link_is_dropped(): void
    {
        [$run, $source, $target] = $this->scenario();

        $blocks = $this->materialize($run, $source, [
            $this->block('Se [Servicedesk](https://intranett.example/sd).', [
                $this->intent('l1', $target->id, 'Servicedesk'),
            ]),
        ], [$this->catalogEntry($target)]);

        $this->assertSame('Se [Servicedesk](https://intranett.example/sd).', $blocks[0]['markdown']);
        $this->assertSame([], $blocks[0]['link_intents']);
    }

    public function test_an_anchor_that_is_not_in_the_prose_is_dropped_not_guessed_at(): void
    {
        [$run, $source, $target] = $this->scenario();

        $blocks = $this->materialize($run, $source, [
            $this->block('Hendelser følger fastsatt prosess i driften.', [
                $this->intent('l1', $target->id, 'et uttrykk som ikke står her'),
            ]),
        ], [$this->catalogEntry($target)]);

        $this->assertSame('Hendelser følger fastsatt prosess i driften.', $blocks[0]['markdown']);
        $this->assertSame([], $blocks[0]['link_intents']);
    }

    public function test_the_anchor_match_is_exact_and_never_normalised(): void
    {
        [$run, $source, $target] = $this->scenario();

        $blocks = $this->materialize($run, $source, [
            // Same letters, different diacritics/case — must NOT match.
            $this->block('Hendelseshåndtering er beskrevet i avtalen.', [
                $this->intent('l1', $target->id, 'hendelseshandtering'),
            ]),
        ], [$this->catalogEntry($target)]);

        $this->assertSame('Hendelseshåndtering er beskrevet i avtalen.', $blocks[0]['markdown']);
        $this->assertSame([], $blocks[0]['link_intents']);
    }

    // =========================================================================
    // Fail-closed: identity is never negotiable
    // =========================================================================

    public function test_an_unknown_target_fails_the_page(): void
    {
        [$run, $source, $target] = $this->scenario();

        $this->expectException(EnterpriseWikiInvalidWikilinksException::class);
        $this->expectExceptionMessage('rejected_unknown_target');

        $this->materialize($run, $source, [
            $this->block('Hendelser følger fastsatt prosess.', [$this->intent('l1', $target->id, 'fastsatt prosess')]),
        ], []);
    }

    public function test_a_self_link_fails_the_page(): void
    {
        [$run, $source] = $this->scenario();

        $this->expectException(EnterpriseWikiInvalidWikilinksException::class);
        $this->expectExceptionMessage('rejected_self_link');

        $this->materialize($run, $source, [
            $this->block('Denne siden omtaler seg selv.', [$this->intent('l1', $source->id, 'Denne siden')]),
        ], [$this->catalogEntry($source)]);
    }

    public function test_a_cross_customer_target_fails_the_page(): void
    {
        [$run, $source] = $this->scenario();
        $foreign = $this->createPage($this->createCustomer('Annen AS')->id, 'Fremmed', 'fremmed-ab1c2d');

        $this->expectException(EnterpriseWikiInvalidWikilinksException::class);
        $this->expectExceptionMessage('rejected_cross_customer');

        $this->materialize($run, $source, [
            $this->block('Se fremmed side.', [$this->intent('l1', $foreign->id, 'fremmed side')]),
        ], [$this->catalogEntry($foreign)]);
    }

    public function test_a_duplicate_intent_id_fails_the_page(): void
    {
        [$run, $source, $target] = $this->scenario();

        $this->expectException(EnterpriseWikiInvalidWikilinksException::class);
        $this->expectExceptionMessage('intent_id was missing, malformed, or duplicated');

        $this->materialize($run, $source, [
            $this->block('Hendelser og drift.', [
                $this->intent('l1', $target->id, 'Hendelser'),
                $this->intent('l1', $target->id, 'drift'),
            ]),
        ], [$this->catalogEntry($target)]);
    }

    public function test_an_intent_without_an_anchor_fails_the_page(): void
    {
        [$run, $source, $target] = $this->scenario();

        $this->expectException(EnterpriseWikiInvalidWikilinksException::class);
        $this->expectExceptionMessage('link intent had no anchor_text');

        $this->materialize($run, $source, [
            $this->block('Hendelser følger fastsatt prosess.', [$this->intent('l1', $target->id, '   ')]),
        ], [$this->catalogEntry($target)]);
    }

    // =========================================================================
    // Internal syntax can never reach a page
    // =========================================================================

    public function test_the_retired_marker_syntax_is_rejected_outright(): void
    {
        // The run-59 shape: whatever a model writes, the retired marker never reaches a page —
        // not parsed, not repaired, not tolerated.
        [$run, $source, $target] = $this->scenario();

        $this->expectException(EnterpriseWikiInvalidWikilinksException::class);
        $this->expectExceptionMessage('retired internal wikilink marker syntax');

        $this->materialize($run, $source, [
            $this->block('Se {{wiki_link:hendelseshåndtering|Hendelseshåndtering (Incident Management)}} her.', [
                $this->intent('l1', $target->id, 'Hendelseshåndtering'),
            ]),
        ], [$this->catalogEntry($target)]);
    }

    public function test_a_marker_without_any_intent_is_also_rejected(): void
    {
        [$run, $source] = $this->scenario();

        $this->expectException(EnterpriseWikiInvalidWikilinksException::class);

        $this->materialize($run, $source, [$this->block('Se {{wiki_link:x|y}} her.', [])], []);
    }

    public function test_a_model_authored_slug_never_survives_as_a_link(): void
    {
        // Model-authored [[...]] markup is stripped to its visible words; only this class chooses a
        // slug, so a guessed target can never reach the page.
        [$run, $source, $target] = $this->scenario();

        $blocks = $this->materialize($run, $source, [
            $this->block('Se [[gjettet-slug|fastsatt prosess]] her.', [
                $this->intent('l1', $target->id, 'fastsatt prosess'),
            ]),
        ], [$this->catalogEntry($target)]);

        $this->assertStringNotContainsString('gjettet-slug', $blocks[0]['markdown']);
        $this->assertSame("Se [[{$target->slug}|fastsatt prosess]] her.", $blocks[0]['markdown']);
    }

    public function test_a_block_without_intents_is_untouched(): void
    {
        [$run, $source] = $this->scenario();

        $blocks = $this->materialize($run, $source, [$this->block('Ren tekst uten lenker.', [])], []);

        $this->assertSame('Ren tekst uten lenker.', $blocks[0]['markdown']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $catalog
     * @return list<array<string, mixed>>
     */
    private function materialize(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $source, array $blocks, array $catalog): array
    {
        return app(EnterpriseWikiLinkIntentMaterializer::class)->materializeBlocks($run, $source, $blocks, $catalog);
    }

    /** @return array<string, mixed> */
    private function block(string $markdown, array $intents): array
    {
        return [
            'markdown' => $markdown,
            'content_origin' => 'source_based',
            'source_element_keys' => ['paragraph-0'],
            'source_element_types' => ['paragraph'],
            'best_practice_reason' => null,
            'link_intents' => $intents,
        ];
    }

    /** @return array<string, mixed> */
    private function intent(string $id, int $targetPageId, string $anchorText): array
    {
        return [
            'intent_id' => $id,
            'target_page_id' => $targetPageId,
            'anchor_text' => $anchorText,
            'reason' => 'Peker til siden som eier temaet.',
        ];
    }

    /** @return array{page_id: int, slug: string, title: string, page_type: string} */
    private function catalogEntry(EnterpriseWikiPage $page): array
    {
        return [
            'page_id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type,
        ];
    }

    /** @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage} */
    private function scenario(string $targetTitle = 'Driftsprosess', ?string $targetSlug = null): array
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document);
        $source = $this->createPage($customer->id, 'Kildesiden', 'kildesiden-ab1c2d');
        $target = $this->createPage($customer->id, $targetTitle, $targetSlug ?? 'driftsprosess-ab1c2d');

        return [$run, $source, $target];
    }

    private function createPage(int $customerId, string $title, string $slug): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => $slug,
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createCustomer(string $name = 'Lenke AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
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
}
