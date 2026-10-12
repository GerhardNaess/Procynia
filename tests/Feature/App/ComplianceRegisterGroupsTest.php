<?php

namespace Tests\Feature\App;

use App\Models\ComplianceFramework;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\Library\ComplianceFrameworkActivationService;
use App\Services\Compliance\Library\ComplianceFrameworkLibraryImporter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Kravregister, Gruppert and Liste: a presentation of the same register, nothing more.
 *
 * What these tests defend:
 *
 *  - Gruppert is the default: kravkilde → kapittel with counts, nothing open and no requirement
 *    rows sent until a source and a chapter are opened.
 *  - The chapters come from the activated framework's own structure — ISO 9001, ISO 14001 and
 *    ISO/IEC 27001 chapters 4–10 as chapters, Vedlegg A by its four themes — and a source registered
 *    by hand has no chapters: its requirements sit directly under it.
 *  - The counts are the filtered register's and agree with Liste over the same filters.
 *  - A search is always answered as a list across every source; the chosen view comes back without it.
 *  - Nothing of another customer is counted or loaded, whatever the URL asks to open.
 */
class ComplianceRegisterGroupsTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    private const FILES = [
        'iso9001:2015' => 'iso9001_2015.php',
        'iso27001:2022' => 'iso27001_2022.php',
        'iso14001:2026' => 'iso14001_2026.php',
    ];

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

    public function test_grouped_is_the_default_with_chapters_and_annex_a_themes_and_no_rows(): void
    {
        ['editor' => $editor] = $this->threeStandards();

        $props = $this->register($editor, '');
        $this->assertSame('grouped', $props['view']);
        $this->assertSame('grouped', $props['shown_as']);
        $this->assertSame([], $props['requirements']);
        $this->assertSame(65 + 123 + 35, $props['matching_count']);

        $sources = $this->sources($props);
        $this->assertSame(['ISO 14001:2026', 'ISO 9001:2015', 'ISO/IEC 27001:2022'], collect($sources)->keys()->sort()->values()->all());
        $this->assertSame(35, $sources['ISO 14001:2026']['count']);
        $this->assertSame(65, $sources['ISO 9001:2015']['count']);
        $this->assertSame(123, $sources['ISO/IEC 27001:2022']['count']);

        // Seven chapters each in ISO 9001 and ISO 14001 — chapter 9, sub-clauses only, stays one chapter.
        foreach (['ISO 9001:2015', 'ISO 14001:2026'] as $label) {
            $this->assertSame(['4', '5', '6', '7', '8', '9', '10'], array_column($sources[$label]['groups'], 'reference'), $label);
            $this->assertSame($sources[$label]['count'], array_sum(array_column($sources[$label]['groups'], 'count')), $label);
            $this->assertSame(0, $sources[$label]['ungrouped_count']);
        }
        $chapter4 = $sources['ISO 9001:2015']['groups'][0];
        $this->assertSame('Organisasjonens kontekst', $chapter4['title']);
        $this->assertNull($chapter4['context']);

        // ISO/IEC 27001: chapters 4–10, then Vedlegg A as its four themes with 37/8/14/34 controls.
        $groups27001 = collect($sources['ISO/IEC 27001:2022']['groups']);
        $this->assertSame(['4', '5', '6', '7', '8', '9', '10', 'A.5', 'A.6', 'A.7', 'A.8'], $groups27001->pluck('reference')->all());
        $this->assertSame([37, 8, 14, 34], $groups27001->slice(7)->pluck('count')->values()->all());
        $this->assertSame(30, $groups27001->take(7)->sum('count'));
        $this->assertSame('A', $groups27001->firstWhere('reference', 'A.5')['context']['reference']);
        $this->assertSame('Organisatoriske kontroller', $groups27001->firstWhere('reference', 'A.5')['title']);

        // Nothing is open and nothing is loaded.
        foreach ($sources as $source) {
            $this->assertFalse($source['open']);
            $this->assertNull($source['requirements']);
            foreach ($source['groups'] as $group) {
                $this->assertFalse($group['open']);
                $this->assertNull($group['requirements']);
            }
        }
    }

    public function test_opening_a_source_and_a_chapter_loads_only_that_chapters_requirements(): void
    {
        ['editor' => $editor] = $this->threeStandards();
        $sources = $this->sources($this->register($editor, ''));
        $iso27001 = $sources['ISO/IEC 27001:2022'];
        $theme = collect($iso27001['groups'])->firstWhere('reference', 'A.6');

        $opened = $this->sources($this->register($editor, 'open='.$iso27001['key'].','.$theme['key']));
        $groups = collect($opened['ISO/IEC 27001:2022']['groups'])->keyBy('reference');
        $this->assertTrue($opened['ISO/IEC 27001:2022']['open']);
        $this->assertTrue($groups['A.6']['open']);
        $this->assertSame(['A.6.1', 'A.6.2', 'A.6.3', 'A.6.4', 'A.6.5', 'A.6.6', 'A.6.7', 'A.6.8'], array_column($groups['A.6']['requirements'], 'reference'));
        $row = $groups['A.6']['requirements'][0];
        $this->assertSame('ISO/IEC 27001:2022', $row['origin']['source']);
        $this->assertSame('control', $row['applicability_rule']);
        $this->assertSame(route('app.compliance.requirements.show', ['requirementId' => $row['id']]), $row['url']);
        $this->assertArrayNotHasKey('requirement_text', $row);
        $this->assertNull($groups['A.5']['requirements']);
        $this->assertNull($opened['ISO 9001:2015']['groups'][0]['requirements']);

        // A chapter whose source is closed is not loaded.
        $closed = $this->sources($this->register($editor, 'open='.$theme['key']));
        $this->assertNull(collect($closed['ISO/IEC 27001:2022']['groups'])->firstWhere('reference', 'A.6')['requirements']);
    }

    public function test_a_source_without_chapters_lists_its_requirements_directly(): void
    {
        ['customer' => $customer, 'editor' => $editor] = $this->threeStandards();
        $law = $this->complianceSource($customer, 'Arbeidsmiljøloven', null, ComplianceSource::KIND_LAW);
        $this->complianceRequirement($law, 'Arbeidstid', null, '§ 10-4');
        $this->complianceRequirement($law, 'Verneombud', null, '§ 6-1');
        $internal = $this->complianceSource($customer, 'Interne leverandørkrav', null, ComplianceSource::KIND_INTERNAL);
        $this->complianceRequirement($internal, 'Leverandørens egenerklæring');

        $sources = $this->sources($this->register($editor, ''));
        $this->assertSame([], $sources['Arbeidsmiljøloven']['groups']);
        $this->assertSame(2, $sources['Arbeidsmiljøloven']['count']);
        $this->assertSame(2, $sources['Arbeidsmiljøloven']['ungrouped_count']);
        $this->assertNull($sources['Arbeidsmiljøloven']['requirements']);

        $opened = $this->sources($this->register($editor, 'open='.$law->id));
        // The register's own order within a source: by reference, as Liste has it.
        $this->assertSame(['§ 10-4', '§ 6-1'], array_column($opened['Arbeidsmiljøloven']['requirements'], 'reference'));
        $this->assertNull($opened['Interne leverandørkrav']['requirements']);
    }

    public function test_filters_narrow_the_groups_and_their_counts_agree_with_the_list(): void
    {
        ['customer' => $customer, 'editor' => $editor] = $this->threeStandards();
        $control = ComplianceRequirement::query()->where('customer_id', $customer->id)->where('reference', 'A.8.13')->sole();
        $this->actingAs($editor)->post("/app/compliance/requirements/{$control->id}/applicability", ['applicability' => 'applies', 'rationale' => 'Tap av data er høy risiko.'])->assertSessionHasNoErrors();

        // Ikke avklart / ikke valgt: ISO 14001 has nothing waiting and is not shown; in ISO/IEC 27001
        // only the Annex A themes are left, one control fewer in A.8; chapters without matches are gone.
        $grouped = $this->register($editor, 'applicability=not_assessed');
        $sources = $this->sources($grouped);
        $this->assertArrayNotHasKey('ISO 14001:2026', $sources);
        $this->assertSame(['A.5', 'A.6', 'A.7', 'A.8'], array_column($sources['ISO/IEC 27001:2022']['groups'], 'reference'));
        $this->assertSame([37, 8, 14, 33], array_column($sources['ISO/IEC 27001:2022']['groups'], 'count'));
        foreach ($sources as $label => $source) {
            $this->assertNotContains(0, array_column($source['groups'], 'count'), $label);
        }

        // Liste over the same filter: the same requirements, the same count per source.
        $list = $this->register($editor, 'view=list&applicability=not_assessed');
        $this->assertSame('list', $list['shown_as']);
        $this->assertNull($list['register']);
        $this->assertSame($grouped['matching_count'], $list['matching_count']);
        $this->assertCount($list['matching_count'], $list['requirements']);
        $perSource = array_count_values(array_column($list['requirements'], 'source_label'));
        ksort($perSource);
        $groupedPerSource = array_map(fn (array $source): int => $source['count'], $sources);
        ksort($groupedPerSource);
        $this->assertSame($perSource, $groupedPerSource);

        // The source filter and the status filter work in Gruppert too.
        $only9001 = $this->register($editor, 'source='.ComplianceSource::query()->where('customer_id', $customer->id)->where('name', 'ISO 9001')->value('id'));
        $this->assertSame(['ISO 9001:2015'], array_keys($this->sources($only9001)));
        $this->assertSame([], $this->register($editor, 'status=retired')['register']['sources']);
        $this->assertSame(65 + 123 + 35, $this->register($editor, 'status=active')['matching_count']);
    }

    public function test_a_search_is_a_list_across_sources_and_the_chosen_view_comes_back_without_it(): void
    {
        ['customer' => $customer, 'editor' => $editor] = $this->threeStandards();
        $internal = $this->complianceSource($customer, 'Interne leverandørkrav', null, ComplianceSource::KIND_INTERNAL);
        $this->complianceRequirement($internal, 'Godkjenning av ny leverandør', null, 'L-1');

        $found = $this->register($editor, 'search=leverandør');
        $this->assertSame('grouped', $found['view']);
        $this->assertSame('list', $found['shown_as']);
        $this->assertNull($found['register']);
        $labels = array_unique(array_column($found['requirements'], 'source_label'));
        foreach (['ISO/IEC 27001:2022', 'Interne leverandørkrav'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertContains('A.5.19', array_column($found['requirements'], 'reference'));
        $this->assertSame(count($found['requirements']), $found['matching_count']);

        // Cleared: back to the view in the URL — Gruppert by default, Liste when chosen.
        $this->assertSame('grouped', $this->register($editor, '')['shown_as']);
        $this->assertSame('list', $this->register($editor, 'view=list&search=leverandør')['shown_as']);
        $this->assertSame('list', $this->register($editor, 'view=list')['shown_as']);
    }

    public function test_another_customers_sources_are_never_counted_or_loaded_whatever_is_asked_to_open(): void
    {
        ['customer' => $first, 'editor' => $firstEditor] = $this->threeStandards();
        ['customer' => $second] = $this->complianceContext();
        $secondEditor = $this->complianceEditor($second);
        $own = $this->complianceSource($second, 'Egen kilde', null, ComplianceSource::KIND_INTERNAL);
        $this->complianceRequirement($own, 'Eget krav');

        $foreign = $this->sources($this->register($firstEditor, ''))['ISO/IEC 27001:2022'];
        $openForeign = implode(',', [$foreign['key'], $foreign['groups'][0]['key'], 'x', '1:2:3', "{$own->id}"]);

        $props = $this->register($secondEditor, 'open='.urlencode($openForeign));
        $sources = $this->sources($props);
        $this->assertSame(['Egen kilde'], array_keys($sources));
        $this->assertSame(['Eget krav'], array_column($sources['Egen kilde']['requirements'], 'title'));
        $this->assertSame(1, $props['matching_count']);
        $this->assertNotContains('x', $props['filters']['open']);
        $this->assertNotContains('1:2:3', $props['filters']['open']);

        // The first customer's register is untouched by the second's.
        $this->assertArrayNotHasKey('Egen kilde', $this->sources($this->register($firstEditor, '')));
        $this->assertSame(65 + 123 + 35, ComplianceRequirement::query()->where('customer_id', $first->id)->count());
    }

    public function test_grouped_reads_a_fixed_number_of_queries_however_much_is_open(): void
    {
        ['editor' => $editor] = $this->threeStandards();
        $sources = $this->sources($this->register($editor, ''));
        $one = [$sources['ISO 9001:2015']['key'], $sources['ISO 9001:2015']['groups'][0]['key']];
        $all = [];
        foreach ($sources as $source) {
            $all[] = $source['key'];
            foreach ($source['groups'] as $group) {
                $all[] = $group['key'];
            }
        }

        $countQueries = function (string $query) use ($editor): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->register($editor, $query);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $countQueries('open='.implode(',', $one));
        $this->assertSame($countQueries('open='.implode(',', $one)), $countQueries('open='.implode(',', $all)), 'Opening more chapters may not add queries.');
        $everything = $this->sources($this->register($editor, 'open='.implode(',', $all)));
        $this->assertCount(123, collect($everything['ISO/IEC 27001:2022']['groups'])->flatMap(fn (array $group): array => $group['requirements'])->all());
    }

    // ---------------------------------------------------------------------

    /** @return array{customer: Customer, editor: User} */
    private function threeStandards(): array
    {
        $importer = app(ComplianceFrameworkLibraryImporter::class);
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);

        foreach (self::FILES as $file) {
            $framework = $importer->import(ComplianceFrameworkLibraryImporter::definitionFromFile(database_path('data/compliance_frameworks/'.$file)))['framework'];
            $this->assertInstanceOf(ComplianceFramework::class, $framework);
            app(ComplianceFrameworkActivationService::class)->activate($framework, $editor);
        }

        return ['customer' => $customer, 'editor' => $editor];
    }

    /** @return array<string, mixed> */
    private function register(User $user, string $query): array
    {
        return $this->actingAs($user)->get('/app/compliance/requirements'.($query !== '' ? '?'.$query : ''))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, array<string, mixed>> the grouped sources keyed by label */
    private function sources(array $props): array
    {
        return collect($props['register']['sources'] ?? [])->keyBy('label')->all();
    }
}
