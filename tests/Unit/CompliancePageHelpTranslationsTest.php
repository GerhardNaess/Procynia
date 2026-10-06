<?php

namespace Tests\Unit;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Services\Compliance\ComplianceAttentionService;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: Etterlevelse og revisjon must not ship without PageHelp. The Krav register and the
 * requirement page each have help with sections in both languages, built the same way, and the
 * help names what the pages actually show — the statuses, the source, the owner, the review
 * interval, the status history and compliance assessments — under the labels the pages use. The
 * Revisjoner register and the audit page likewise explain types, statuses, scope and lifecycle. A label renamed without the
 * help following it fails here. Both languages also carry exactly the same keys.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class CompliancePageHelpTranslationsTest extends TestCase
{
    private const PAGES = ['index', 'requirement', 'audit_index', 'audit'];

    public function test_every_page_has_help_with_sections_in_both_languages(): void
    {
        foreach (['no', 'en'] as $locale) {
            $help = $this->compliance($locale)['help'] ?? null;
            $this->assertIsArray($help, "Missing compliance.help in lang/{$locale}/procynia.php.");
            $this->assertNotSame('', trim((string) ($help['button'] ?? '')));

            foreach (self::PAGES as $page) {
                $content = $help[$page] ?? null;
                $this->assertIsArray($content, "Missing compliance.help.{$page} in lang/{$locale}.");
                $this->assertNotSame('', trim((string) ($content['title'] ?? '')));
                $this->assertNotSame('', trim((string) ($content['intro'] ?? '')));
                $this->assertNotEmpty($content['sections'] ?? []);

                foreach ($content['sections'] as $section) {
                    $this->assertNotSame('', trim((string) ($section['title'] ?? '')));
                    $this->assertNotEmpty($section['items'] ?? []);

                    foreach ($section['items'] as $item) {
                        $this->assertNotSame('', trim((string) ($item['title'] ?? '')));
                        $this->assertNotSame('', trim((string) ($item['text'] ?? '')));
                    }
                }
            }
        }
    }

    public function test_both_languages_have_the_same_help_structure(): void
    {
        $shape = fn (array $help): array => array_map(
            fn (array $content): array => array_map(fn (array $section): int => count($section['items']), $content['sections']),
            array_intersect_key($help, array_flip(self::PAGES)),
        );

        $this->assertSame($shape($this->compliance('no')['help']), $shape($this->compliance('en')['help']));
    }

    public function test_both_languages_expose_the_same_keys(): void
    {
        $this->assertSame($this->keys($this->compliance('no')), $this->keys($this->compliance('en')));
    }

    public function test_the_register_help_explains_requirement_source_compliance_and_audit(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $titles = $this->itemTitles($strings['help']['index']);
            $text = $this->allText($strings['help']['index']);

            $this->assertContains($strings['field_reference'], $titles, "The register help in lang/{$locale} does not explain «{$strings['field_reference']}».");

            foreach (ComplianceRequirement::STATUSES as $status) {
                $this->assertContains($strings['statuses'][$status], $titles, "The register help in lang/{$locale} does not explain «{$strings['statuses'][$status]}».");
            }

            // What a requirement is, what a source is, that compliance is assessed separately, and
            // how a requirement differs from an audit — one item each.
            $this->assertCount(2, array_filter($titles, fn (string $title): bool => in_array($title, $locale === 'no' ? ['Krav', 'Kravkilde'] : ['Requirement', 'Requirement source'], true)));
            $this->assertCount(2, array_filter($titles, fn (string $title): bool => in_array($title, $locale === 'no' ? ['Krav og etterlevelse', 'Krav og revisjon'] : ['Requirements and compliance', 'Requirements and audits'], true)));
            $this->assertStringContainsString($strings['sources']['heading'], $text, "The register help in lang/{$locale} does not point to «{$strings['sources']['heading']}».");
        }
    }

    public function test_the_requirement_help_explains_owner_interval_status_and_history_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $titles = $this->itemTitles($strings['help']['requirement']);
            $sectionTitles = array_column($strings['help']['requirement']['sections'], 'title');

            foreach (['field_owner', 'field_review', 'no_owner', 'reopen'] as $key) {
                $this->assertContains($strings[$key], $titles, "The requirement help in lang/{$locale} does not explain «{$strings[$key]}».");
            }

            foreach (ComplianceRequirement::STATUSES as $status) {
                $this->assertContains($strings['statuses'][$status], $titles, "The requirement help in lang/{$locale} does not explain «{$strings['statuses'][$status]}».");
            }

            $this->assertContains($strings['history_heading'], $sectionTitles, "The requirement help in lang/{$locale} has no «{$strings['history_heading']}» section.");
        }
    }

    public function test_both_pages_explain_compliance_assessments_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $labels = $strings['assessment']['results'];

            // The register explains every result, «Ikke vurdert» and «Revurdering forfalt» by name.
            $indexTitles = $this->itemTitles($strings['help']['index']);
            foreach ([...array_values($labels), $strings['assessment']['overdue']] as $label) {
                $this->assertContains($label, $indexTitles, "The register help in lang/{$locale} does not explain «{$label}».");
            }

            // The requirement page explains what an assessment is, why «Ikke vurdert» is not stored,
            // that assessments cannot be changed, the interval, and a changed requirement.
            $requirement = $this->allText($strings['help']['requirement']);
            $this->assertContains($strings['assessment']['results']['not_assessed'], $this->itemTitles($strings['help']['requirement']));
            $this->assertContains($strings['field_review'], $this->itemTitles($strings['help']['requirement']));
            $this->assertContains($strings['assessment']['heading'], array_column($strings['help']['requirement']['sections'], 'title'));
            foreach (ComplianceAssessment::RESULTS as $result) {
                $this->assertStringContainsString($labels[$result], $requirement, "The requirement help in lang/{$locale} does not name «{$labels[$result]}».");
            }
            $this->assertStringContainsString($strings['assessment']['overdue'], $requirement);
        }

        $no = $this->allText($this->compliance('no')['help']['requirement']);
        foreach (['kan verken endres eller slettes', 'registrere en ny', 'ikke et lagret resultat', 'Endres kravet etterpå', 'pluss intervallet'] as $phrase) {
            $this->assertStringContainsString($phrase, $no);
        }
    }

    public function test_the_requirement_help_explains_how_the_requirement_is_met(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $sections = $strings['help']['requirement']['sections'];
            $titles = array_column($sections, 'title');

            // A section named like the page section, between ownership and etterlevelse — the page's order.
            $index = array_search($strings['quality']['heading'], $titles, true);
            $this->assertIsInt($index, "The requirement help in lang/{$locale} has no «{$strings['quality']['heading']}» section.");
            $this->assertLessThan(array_search($strings['assessment']['heading'], $titles, true), $index);
            $this->assertCount(6, $sections[$index]['items']);
        }

        // Why link, Kvalitet owns it, evidence read-only, evidence is no verdict, retired controls, access.
        $no = $this->allText(['sections' => [$this->section('no')]]);
        foreach (['hvordan virksomheten har tenkt å oppfylle kravet', 'Kvalitet', 'Her lagres bare koblingen', 'kan ikke legges til, endres eller fjernes', 'betyr ikke automatisk at kravet er oppfylt', 'vurderes eksplisitt', 'satt som utgått i Kvalitet', 'gir ikke innsyn i Kvalitet'] as $phrase) {
            $this->assertStringContainsString($phrase, $no);
        }

        // The index subtitle now names etterlevelse.
        $this->assertStringContainsString('etterlevelsesvurdering', $this->compliance('no')['index_subtitle']);
        $this->assertStringContainsString('compliance assessment', $this->compliance('en')['index_subtitle']);
    }

    /** @return array<string, mixed> */
    private function section(string $locale): array
    {
        $strings = $this->compliance($locale);

        foreach ($strings['help']['requirement']['sections'] as $section) {
            if ($section['title'] === $strings['quality']['heading']) {
                return $section;
            }
        }

        $this->fail("No «{$strings['quality']['heading']}» section in lang/{$locale}.");
    }

    public function test_the_register_help_explains_needs_attention_and_each_reason_by_its_label(): void
    {
        foreach (['no', 'en'] as $locale) {
            $compliance = $this->compliance($locale);
            $attention = $compliance['attention'];
            $this->assertSame(ComplianceAttentionService::REASONS, array_keys($attention['reasons']), $locale);

            $section = collect($compliance['help']['index']['sections'])->firstWhere('title', $attention['heading']);
            $this->assertIsArray($section, "The register help in {$locale} has no section named «{$attention['heading']}».");
            $titles = array_column($section['items'], 'title');

            foreach ($attention['reasons'] as $label) {
                $this->assertContains($label, $titles, "{$locale}: the help does not explain «{$label}».");
            }

            // It says the signals are computed, not stored.
            $text = implode(' ', array_column($section['items'], 'text'));
            $this->assertMatchesRegularExpression($locale === 'no' ? '/automatisk.*ikke egne lagrede statuser/u' : '/automatically.*not statuses of their own/u', $text);
        }
    }

    public function test_the_value_labels_cover_exactly_the_values_that_exist(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);

            $this->assertSame(ComplianceRequirement::STATUSES, array_keys($strings['statuses']));
            $this->assertSame(ComplianceSource::KINDS, array_keys($strings['kinds']));
            $this->assertSame(['none', ...ComplianceRequirement::REVIEW_INTERVALS], array_keys($strings['review_intervals']));
            $this->assertSame(['retired', 'active'], array_keys($strings['history']));
            // The four stored results, and the derived «not assessed» — which is never stored.
            $this->assertSame([...ComplianceAssessment::RESULTS, 'not_assessed'], array_keys($strings['assessment']['results']));
        }
    }

    public function test_the_norwegian_ui_uses_the_agreed_domain_terms(): void
    {
        $no = $this->compliance('no');

        $this->assertSame('Etterlevelse og revisjon', $no['module_name']);
        $this->assertSame('Krav', $no['index_heading']);
        $this->assertSame(['active' => 'Aktiv', 'retired' => 'Utgått'], $no['statuses']);
        $this->assertSame(
            ['standard' => 'Standard', 'law' => 'Lov/forskrift', 'contract' => 'Kontrakt', 'internal' => 'Internt krav', 'other' => 'Annet'],
            $no['kinds'],
        );
        $this->assertSame(
            ['none' => 'Ingen fast intervall', 1 => 'Månedlig', 3 => 'Kvartalsvis', 6 => 'Halvårlig', 12 => 'Årlig'],
            $no['review_intervals'],
        );
        $this->assertSame(
            ['Referanse', 'Krav', 'Kravkilde', 'Ansvarlig', 'Revurdering', 'Status'],
            [$no['col_reference'], $no['col_requirement'], $no['col_source'], $no['col_owner'], $no['col_review'], $no['col_status']],
        );
        $this->assertSame(
            ['Sett som utgått', 'Gjenåpne', 'Statushistorikk', 'Mangler ansvarlig', 'Kravkilder'],
            [$no['retire'], $no['reopen'], $no['history_heading'], $no['no_owner'], $no['sources']['heading']],
        );
        $this->assertSame(
            ['compliant' => 'Oppfylt', 'partially_compliant' => 'Delvis oppfylt', 'non_compliant' => 'Ikke oppfylt', 'not_applicable' => 'Ikke relevant', 'not_assessed' => 'Ikke vurdert'],
            $no['assessment']['results'],
        );
        $this->assertSame(
            ['Etterlevelse', 'Etterlevelse', 'Vurder etterlevelse', 'Revurdering forfalt', 'Kravet er endret siden siste etterlevelsesvurdering.'],
            [$no['col_compliance'], $no['assessment']['heading'], $no['assessment']['assess'], $no['assessment']['overdue'], $no['assessment']['changed_since']],
        );
        $this->assertStringStartsWith('Beskriv hvorfor virksomheten anses å oppfylle, delvis oppfylle eller ikke oppfylle kravet.', $no['assessment']['field_rationale_hint']);
    }

    public function test_the_audit_help_explains_types_statuses_scope_and_lifecycle_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $audits = $this->compliance($locale)['audits'];
            $help = $this->compliance($locale)['help'];
            $register = $this->itemTitles($help['audit_index']);
            $page = $this->itemTitles($help['audit']);

            // What an audit is, both types and every status, in the register's help.
            foreach (ComplianceAudit::TYPES as $type) {
                $this->assertContains($audits['types'][$type], $register, "lang/{$locale}: the register help does not explain «{$audits['types'][$type]}».");
            }
            foreach (ComplianceAudit::STATUSES as $status) {
                $this->assertContains($audits['statuses'][$status], $register, "lang/{$locale}: the register help does not explain «{$audits['statuses'][$status]}».");
            }
            $this->assertContains($audits['field_responsible'], $register);

            // Scope, why requirements and processes are linked, the lifecycle actions, the lock and
            // the history, on the audit's own page — under the labels the page uses.
            foreach ([
                $audits['scope_heading'], $audits['requirements_heading'], $audits['processes_heading'],
                $audits['retired_requirement'], $audits['field_conclusion'],
                $audits['start'], $audits['complete'], $audits['cancel_audit'], $audits['reopen'], $audits['history_heading'],
            ] as $label) {
                $this->assertContains($label, $page, "lang/{$locale}: the audit help does not explain «{$label}».");
            }

            // Findings are announced as a later step on both pages, never described as existing.
            $this->assertMatchesRegularExpression($locale === 'no' ? '/[Ff]unn/' : '/[Ff]indings/', $this->allText($help['audit_index']));
            $this->assertMatchesRegularExpression($locale === 'no' ? '/[Ff]unn/' : '/[Ff]indings/', $this->allText($help['audit']));
        }
    }

    public function test_the_audit_labels_cover_exactly_the_values_and_transitions_that_exist(): void
    {
        foreach (['no', 'en'] as $locale) {
            $audits = $this->compliance($locale)['audits'];

            $this->assertSame(ComplianceAudit::TYPES, array_keys($audits['types']));
            $this->assertSame(ComplianceAudit::STATUSES, array_keys($audits['statuses']));
            $this->assertSame(ComplianceAudit::STATUSES, array_keys($audits['status_text']));
            $this->assertSame(
                ['planned_in_progress', 'in_progress_completed', 'planned_cancelled', 'in_progress_cancelled', 'completed_in_progress'],
                array_keys($audits['history']),
            );
        }

        $no = $this->compliance('no')['audits'];
        $this->assertSame(['internal' => 'Intern', 'external' => 'Ekstern'], $no['types']);
        $this->assertSame(['planned' => 'Planlagt', 'in_progress' => 'Under arbeid', 'completed' => 'Fullført', 'cancelled' => 'Avbrutt'], $no['statuses']);
        $this->assertSame(
            ['Revisjoner', 'Start revisjon', 'Fullfør revisjon', 'Avbryt revisjon', 'Gjenåpne revisjon'],
            [$no['nav'], $no['start'], $no['complete'], $no['cancel_audit'], $no['reopen']],
        );
    }

    /** @return array<string, mixed> */
    private function compliance(string $locale): array
    {
        $strings = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $strings['compliance'];
    }

    /** @return list<string> */
    private function itemTitles(array $content): array
    {
        return array_merge(...array_map(fn (array $section): array => array_column($section['items'], 'title'), $content['sections']));
    }

    private function allText(array $content): string
    {
        return implode(' ', array_merge(...array_map(fn (array $section): array => array_column($section['items'], 'text'), $content['sections'])));
    }

    /** @return list<string> */
    private function keys(array $strings, string $prefix = ''): array
    {
        $keys = [];

        foreach ($strings as $key => $value) {
            $path = $prefix.$key;
            $keys = [...$keys, ...(is_array($value) ? $this->keys($value, $path.'.') : [$path])];
        }

        sort($keys);

        return $keys;
    }
}
