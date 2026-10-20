<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFailurePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * A stopped Wiki run is explained in the reader's language from its stored message, which itself
 * stays untouched. The page-generation message below is the exact shape Wiki run 26 stored
 * (FinalizeEnterpriseWikiPageGeneration::markRunFailed() + EnterpriseWikiPlannedSectionEvidenceMissingException).
 */
class EnterpriseWikiRunFailurePresenterTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const TITLE = 'Vurdering av betalingsbetingelser og økonomisk informasjon';

    private const TOPICS = ['Formål og tidspunkt for aktiviteten', 'Ansvar', 'Fremgangsmåte og vurderingskriterier', 'Dokumentasjon og resultat', 'Relatert prosesskontekst'];

    private const TECHNICAL_FRAGMENTS = ['Exception', 'planned_section_no_evidence', 'Run [', 'page [', 'failed to generate', 'Details —', '(article)'];

    public function test_the_run_26_message_reads_as_a_short_norwegian_explanation(): void
    {
        app()->setLocale('no');

        $summary = $this->present($this->stoppedRun(self::stored([[self::TITLE, 'article', self::TOPICS]])));

        $this->assertSame(EnterpriseWikiRunFailurePresenter::KIND_PLANNED_SECTION_EVIDENCE_MISSING, $summary['kind']);
        $this->assertSame('Kunne ikke generere artikkelen', $summary['title']);
        $this->assertSame([
            'Artikkelen «'.self::TITLE.'» mangler kildegrunnlag for fem planlagte avsnitt.',
            'Kjøringen ble stoppet for å unngå å generere innhold uten tilstrekkelig dokumentasjon.',
        ], $summary['lines']);
        $this->assertSame('generating_pages', $summary['failed_phase']);
        $this->assertNoTechnicalText($summary);
    }

    public function test_the_same_failure_reads_in_english(): void
    {
        app()->setLocale('en');

        $summary = $this->present($this->stoppedRun(self::stored([[self::TITLE, 'article', self::TOPICS]])));

        $this->assertSame('Could not generate the article', $summary['title']);
        $this->assertSame([
            'The article “'.self::TITLE.'” has no source basis for five planned sections.',
            'The run was stopped to avoid generating content without sufficient documentation.',
        ], $summary['lines']);
        $this->assertNoTechnicalText($summary);
    }

    public function test_the_page_name_type_and_number_follow_the_stored_data(): void
    {
        app()->setLocale('no');

        $one = $this->present($this->stoppedRun(self::stored([['Leverandøroppfølging – Sammendrag', 'summary', ['Ansvar']]]), ['Ansvar']));
        $this->assertSame('Kunne ikke generere sammendraget', $one['title']);
        $this->assertSame('Sammendraget «Leverandøroppfølging – Sammendrag» mangler kildegrunnlag for ett planlagt avsnitt.', $one['lines'][0]);

        // A planned name that itself contains ", " is still one section.
        $topics = ['Ansvar, roller og myndighet', 'Rapportering'];
        $two = $this->present($this->stoppedRun(self::stored([['Styring', 'article', $topics]]), $topics));
        $this->assertStringContainsString('for to planlagte avsnitt.', $two['lines'][0]);
    }

    public function test_a_number_that_cannot_be_confirmed_is_never_guessed(): void
    {
        app()->setLocale('no');
        $general = 'mangler kildegrunnlag for ett eller flere planlagte avsnitt.';

        // No page plan to confirm the names against.
        $this->assertStringContainsString($general, $this->present($this->stoppedRun(self::stored([[self::TITLE, 'article', self::TOPICS]]), []))['lines'][0]);

        // A listed name the plan does not have.
        $this->assertStringContainsString($general, $this->present($this->stoppedRun(self::stored([[self::TITLE, 'article', ['Ukjent avsnitt']]])))['lines'][0]);

        // The stored message was cut at its length limit, so names may be missing.
        $long = array_map(static fn (int $i): string => 'Planlagt avsnitt nummer '.$i.' med en lang overskrift', range(1, 30));
        $message = mb_substr(self::stored([[self::TITLE, 'article', $long]]), 0, 999).'.';
        $this->assertSame(1000, mb_strlen($message));
        $this->assertStringContainsString($general, $this->present($this->stoppedRun($message, $long))['lines'][0]);
    }

    public function test_several_pages_without_evidence_are_named_together(): void
    {
        app()->setLocale('no');

        $summary = $this->present($this->stoppedRun(self::stored([
            ['Side A', 'article', ['Ansvar']],
            ['Side B', 'concept', ['Ansvar']],
        ], total: 3), ['Ansvar']));

        $this->assertSame('Kunne ikke generere Wiki-sidene', $summary['title']);
        $this->assertSame('Disse sidene mangler kildegrunnlag for planlagte avsnitt: «Side A», «Side B».', $summary['lines'][0]);
    }

    public function test_a_page_failure_for_another_reason_is_not_called_missing_evidence(): void
    {
        app()->setLocale('no');

        $message = '2 of 3 initial page(s) failed to generate: Side A, Side B. Details — '
            .'Side A (article): [EnterpriseWikiPlannedSectionEvidenceMissingException] Run [9] page [1] (article): planned_section_no_evidence before generation: Ansvar.'
            .' | Side B (article): [RuntimeException] OpenAI request failed with status 500';

        $summary = $this->present($this->stoppedRun($message, ['Ansvar']));

        $this->assertSame(EnterpriseWikiRunFailurePresenter::KIND_PAGE_GENERATION_FAILED, $summary['kind']);
        $this->assertSame('Noen Wiki-sider kunne ikke genereres', $summary['title']);
        $this->assertSame('2 av 3 sider kunne ikke genereres: «Side A», «Side B».', $summary['lines'][0]);
        $this->assertSame('generating_pages', $summary['failed_phase']);
        $this->assertNoTechnicalText($summary);
    }

    public function test_an_unknown_failure_gets_a_neutral_translated_sentence_and_no_raw_text(): void
    {
        app()->setLocale('no');
        $summary = $this->present($this->stoppedRun('SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint'));

        $this->assertSame(EnterpriseWikiRunFailurePresenter::KIND_UNKNOWN, $summary['kind']);
        $this->assertSame('Kjøringen stoppet med en feil', $summary['title']);
        $this->assertNull($summary['failed_phase']);
        $this->assertStringNotContainsString('SQLSTATE', implode(' ', [$summary['title'], ...$summary['lines']]));

        app()->setLocale('en');
        $this->assertSame('The run stopped with an error', $this->present($this->stoppedRun('anything'))['title']);
    }

    public function test_runs_that_did_not_stop_or_are_explained_elsewhere_get_no_summary(): void
    {
        $presenter = app(EnterpriseWikiRunFailurePresenter::class);

        $completed = $this->stoppedRun(null);
        $completed->status = EnterpriseWikiIngestRun::STATUS_COMPLETED;
        $this->assertNull($presenter->present($completed));

        $escalatedWithoutMessage = $this->stoppedRun(null);
        $escalatedWithoutMessage->status = EnterpriseWikiIngestRun::STATUS_ESCALATED;
        $this->assertNull($presenter->present($escalatedWithoutMessage));

        // A transient planning failure keeps its own friendly copy in Kjøringer.
        $transient = $this->stoppedRun('cURL error 28: Operation timed out');
        $transient->failed_phase = 'maintainer_decision';
        $transient->transient_failure = true;
        $this->assertNull($presenter->present($transient));

        // An escalated run with a stored message is explained, not shown raw.
        $escalated = $this->stoppedRun(self::stored([[self::TITLE, 'article', self::TOPICS]]));
        $escalated->status = EnterpriseWikiIngestRun::STATUS_ESCALATED;
        $this->assertSame(EnterpriseWikiRunFailurePresenter::KIND_PLANNED_SECTION_EVIDENCE_MISSING, $presenter->present($escalated)['kind']);
    }

    public function test_the_runs_tab_carries_the_summary_and_keeps_the_stored_message_unchanged(): void
    {
        app()->setLocale('no');
        $customer = $this->createWikiCustomer('Feilvisning testkunde');
        $owner = User::query()->create([
            'name' => 'Run Owner',
            'email' => Str::lower(Str::random(10)).'@run-failure-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'betalingsbetingelser.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Tekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $stored = self::stored([[self::TITLE, 'article', self::TOPICS]]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_json' => ['source_article' => ['title' => self::TITLE, 'owned_topics' => array_map(static fn (string $t): array => ['topic' => $t], self::TOPICS)]],
            'error_message' => $stored,
        ]);

        $response = $this->actingAs($owner)->get('/app/wiki?tab=runs');
        $row = collect(data_get($response->viewData('page'), 'props.runs'))->firstWhere('id', $run->id);

        $this->assertSame('Kunne ikke generere artikkelen', $row['failure_summary']['title']);
        $this->assertStringContainsString('fem planlagte avsnitt', $row['failure_summary']['lines'][0]);
        // The original diagnostics stay available for «Vis tekniske detaljer», unchanged.
        $this->assertSame($stored, $row['error_message']);
        $this->assertNull($row['failed_phase']);
        $this->assertSame($stored, $run->fresh()->error_message);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
    }

    // =========================================================================

    /**
     * The message FinalizeEnterpriseWikiPageGeneration::markRunFailed() stores for pages that
     * failed on EnterpriseWikiPlannedSectionEvidenceMissingException.
     *
     * @param  list<array{0: string, 1: string, 2: list<string>}>  $pages
     */
    private static function stored(array $pages, int $total = 2): string
    {
        $details = array_map(
            static fn (array $page, int $i): string => sprintf(
                '%s (%s): [EnterpriseWikiPlannedSectionEvidenceMissingException] Run [26] page [%d] (%s): planned_section_no_evidence before generation: %s.',
                $page[0], $page[1], 110 + $i, $page[1], implode(', ', $page[2]),
            ),
            $pages,
            array_keys($pages),
        );

        return sprintf(
            '%d of %d initial page(s) failed to generate: %s. Details — %s',
            count($pages), $total, implode(', ', array_column($pages, 0)), implode(' | ', $details),
        );
    }

    /** @param list<string>|null $plannedTopics */
    private function stoppedRun(?string $errorMessage, ?array $plannedTopics = self::TOPICS): EnterpriseWikiIngestRun
    {
        return new EnterpriseWikiIngestRun([
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'failed_phase' => null,
            'transient_failure' => null,
            'error_message' => $errorMessage,
            'maintainer_decision_json' => $plannedTopics === []
                ? null
                : ['source_article' => ['owned_topics' => array_map(static fn (string $t): array => ['topic' => $t], $plannedTopics ?? [])]],
        ]);
    }

    /** @return array{kind: string, title: string, lines: list<string>, failed_phase: ?string} */
    private function present(EnterpriseWikiIngestRun $run): array
    {
        $summary = app(EnterpriseWikiRunFailurePresenter::class)->present($run);
        $this->assertNotNull($summary);

        return $summary;
    }

    /** @param array{title: string, lines: list<string>} $summary */
    private function assertNoTechnicalText(array $summary): void
    {
        $text = implode(' ', [$summary['title'], ...$summary['lines']]);

        foreach (self::TECHNICAL_FRAGMENTS as $fragment) {
            $this->assertStringNotContainsString($fragment, $text);
        }
    }
}
