<?php

namespace Tests\Unit;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Services\Compliance\ComplianceAttentionService;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: Etterlevelse og revisjon must not ship without PageHelp. Kravbibliotek, Kravregister,
 * Etterlevelsesvurdering, Revisjon and the pages under them each have help with sections in both
 * languages, built the same way and opening with numbered steps, and the
 * help names what the pages actually show — the statuses, the source, the owner, the review
 * interval, the status history and compliance assessments — under the labels the pages use. The
 * Revisjoner register and the audit page likewise explain types, statuses, scope, lifecycle and
 * findings with their follow-up in Avvik og forbedringer. A label renamed without the
 * help following it fails here. Both languages also carry exactly the same keys.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class CompliancePageHelpTranslationsTest extends TestCase
{
    private const PAGES = ['index', 'requirement', 'assessments', 'audit_index', 'audit', 'library', 'framework'];

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

            foreach (['field_owner', 'field_review', 'reopen'] as $key) {
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

            // Etterlevelsesvurdering explains every figure on the page by its label — the results,
            // «Ikke vurdert», «Revurdering forfalt» and what is kept outside the figures.
            $assessmentTitles = $this->itemTitles($strings['help']['assessments']);
            foreach ($strings['assessments_page']['stats'] as $label) {
                $this->assertContains($label, $assessmentTitles, "The assessment help in lang/{$locale} does not explain «{$label}».");
            }
            foreach (['compliant', 'partially_compliant', 'non_compliant', 'not_assessed'] as $result) {
                $this->assertContains($labels[$result], $assessmentTitles, "The assessment help in lang/{$locale} does not explain «{$labels[$result]}».");
            }
            $this->assertContains($strings['assessment']['overdue'], $assessmentTitles);
            // «Ikke relevant» is relevance, explained where it is decided: the register.
            $this->assertContains($labels['not_applicable'], $this->itemTitles($strings['help']['index']));

            // The requirement page explains what an assessment is, why «Ikke vurdert» is not stored,
            // that assessments cannot be changed, the interval, and a changed requirement.
            $requirement = $this->allText($strings['help']['requirement']);
            $this->assertContains($strings['assessment']['results']['not_assessed'], $this->itemTitles($strings['help']['requirement']));
            $this->assertContains($strings['field_review'], $this->itemTitles($strings['help']['requirement']));
            $this->assertContains($strings['assessment']['documentation'], $this->itemTitles($strings['help']['requirement']));
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

        // Self-assessment and audit are kept apart, and an audit never overwrites an assessment.
        $assessments = $this->allText($this->compliance('no')['help']['assessments']);
        foreach (['egen vurdering', 'systematisk kontroll', 'endrer aldri en etterlevelsesvurdering', 'telles bare der', 'ikke mangler'] as $phrase) {
            $this->assertStringContainsString($phrase, $assessments);
        }
    }

    public function test_the_requirement_help_explains_how_the_requirement_is_met(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $sections = $strings['help']['requirement']['sections'];
            $titles = array_column($sections, 'title');

            // The optional Kvalitet links, named like the page section and after Etterlevelse — the
            // page's order now that a process is never asked for before an assessment.
            $index = array_search($strings['quality']['heading'], $titles, true);
            $this->assertIsInt($index, "The requirement help in lang/{$locale} has no «{$strings['quality']['heading']}» section.");
            $this->assertGreaterThan(array_search($strings['assessment']['heading'], $titles, true), $index);
            $this->assertCount(6, $sections[$index]['items']);
        }

        // Optional, a process is not evidence, Kvalitet owns it, evidence read-only and no verdict,
        // retired controls, access.
        $no = $this->allText(['sections' => [$this->section('no')]]);
        foreach (['Valgfrie henvisninger', 'ikke i seg selv dokumentasjon', 'ber aldri om en kobling', 'Her lagres bare koblingen', 'kan ikke legges til, endres eller fjernes', 'betyr ikke automatisk at kravet er oppfylt', 'vurderes eksplisitt', 'satt som utgått i Kvalitet', 'gir ikke innsyn i Kvalitet'] as $phrase) {
            $this->assertStringContainsString($phrase, $no);
        }

        // The register's subtitle points to where etterlevelse is assessed.
        $this->assertStringContainsString('etterlevelsesvurdering', $this->compliance('no')['index_subtitle']);
        $this->assertStringContainsString('compliance assessment', $this->compliance('en')['index_subtitle']);
    }

    public function test_the_iso_pages_open_with_their_purpose_and_numbered_steps(): void
    {
        foreach (['no', 'en'] as $locale) {
            $help = $this->compliance($locale)['help'];
            $this->assertNotSame('', trim((string) ($help['purpose_heading'] ?? '')));

            foreach (['library', 'framework', 'index', 'requirement', 'assessments', 'audit_index', 'audit'] as $page) {
                $steps = $help[$page]['sections'][0];
                $this->assertSame($locale === 'no' ? 'Slik gjør du det' : 'How to do it', $steps['title'], "lang/{$locale}: {$page} does not start with the steps.");

                foreach ($steps['items'] as $number => $item) {
                    $this->assertStringStartsWith(($number + 1).'. ', $item['title'], "lang/{$locale}: step ".($number + 1)." on {$page} is not numbered.");
                }
            }
        }
    }

    public function test_the_steps_name_the_buttons_and_fields_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $library = $strings['library'];
            $steps = fn (string $page): string => $this->allText(['sections' => [$strings['help'][$page]['sections'][0]]])
                .' '.implode(' ', array_column($strings['help'][$page]['sections'][0]['items'], 'title'));

            // Every quoted «label» in the steps is a label the page shows: a renamed button fails here.
            $page = $strings['assessments_page'];
            $audits = $strings['audits'];
            $labels = [
                'library' => [$library['open_framework'], $library['activate'], $library['open_register'], $library['open_assessments'], $library['activated'], $library['not_activated']],
                'framework' => [$library['show_details'], $library['expand_all'], $library['collapse_all'], $library['search_label'], $library['filter_label'], $library['activate'], $library['open_requirement'], $library['open_register'], $library['open_assessments']],
                'index' => [$strings['source_filter'], $strings['search'], $strings['applicability_filter'], $library['applicability']['register_filter']['not_assessed'], $strings['to_assessments'], $strings['create'], $strings['sources']['manage'], $library['from_library_cta'],
                    $strings['register_view']['grouped'], $strings['register_view']['list'], $strings['search_label'], $strings['status_filter'], $strings['reset'], $strings['back']],
                'requirement' => [
                    $strings['text_heading'], $library['from_library_heading'], $library['modules_heading'],
                    $library['applicability']['heading'], $library['applicability']['change'], $library['applicability']['submit'], $library['applicability']['field_rationale'],
                    $library['applicability']['values']['applies'], $library['applicability']['values']['not_applicable'],
                    $strings['edit'], $strings['field_owner_optional'], $strings['field_review'], $strings['save'],
                    $strings['quality']['heading'], $strings['quality']['link_process'], $strings['quality']['link_control'],
                    $strings['assessment']['assess'], $strings['assessment']['field_result'], $strings['assessment']['field_rationale'], $strings['assessment']['field_documentation'], $strings['assessment']['submit'],
                    $strings['assessment']['results']['compliant'], $strings['assessment']['results']['partially_compliant'], $strings['assessment']['results']['non_compliant'], $strings['assessment']['results']['not_assessed'],
                    $strings['back_to_assessments'], $strings['back'], $page['assess'],
                    $library['applicability']['control']['heading'], $library['applicability']['control']['change'], $library['applicability']['control']['submit'],
                    $library['applicability']['control_values']['applies'], $library['applicability']['control_values']['not_applicable'],
                ],
                'assessments' => [
                    $page['filter_source'], $page['apply'], $page['reset'], $page['filter_status'], $page['filters']['not_assessed'], $page['filters']['gaps'],
                    $page['stats']['assessable'], $page['stats']['assessed'], $page['stats']['compliant'], $page['stats']['partially_compliant'], $page['stats']['non_compliant'],
                    $page['assess'], $strings['assessment']['field_result'], $strings['assessment']['field_rationale'], $strings['assessment']['submit'], $strings['back_to_assessments'],
                ],
                'audit_index' => $auditSteps = [
                    $audits['create'], $audits['field_title'], $audits['field_type'], $audits['field_responsible'], $audits['field_auditor'], $audits['field_start'], $audits['field_end'],
                    $audits['field_purpose'], $audits['field_scope'],
                    $audits['create_sources_legend'], $audits['requirements_from_source'], $audits['create_pick_legend'], $audits['save'], $audits['requirements_add'], $audits['phases']['planning'],
                    $audits['start'],
                    $audits['phases']['execution'], $audits['checks']['create'], $audits['checks']['field_title'], $audits['checks']['field_observation'], $audits['checks']['field_evidence'], $audits['checks']['field_requirements'], $audits['checks']['save'],
                    $audits['checks']['new_finding'], $audits['findings']['create'],
                    $audits['complete'], $audits['phases']['report'], $audits['field_conclusion'],
                    $audits['findings']['hand_off'],
                ],
                // Revisjon: the same eight steps, plan to follow-up, on the audit's own page.
                'audit' => $auditSteps,
            ];

            foreach ($labels as $page => $expected) {
                $text = $steps($page);
                foreach ($expected as $label) {
                    $this->assertStringContainsString($label, $text, "lang/{$locale}: the {$page} steps do not name «{$label}».");
                }
                preg_match_all('/«([^»]+)»/u', $text, $quoted);
                foreach ($quoted[1] as $label) {
                    $this->assertContains($label, $expected, "lang/{$locale}: the {$page} steps quote «{$label}», which is not a label the page shows.");
                }
            }

            // Activation happens on the library's cards, not on the framework page.
            $this->assertStringContainsString($library['activate'], $steps('library'));
            $this->assertStringContainsString($locale === 'no' ? 'på kortet' : 'card', $steps('library'));
            $this->assertStringContainsString($locale === 'no' ? 'Kravbibliotek' : 'Requirement library', implode(' ', array_column(array_filter($strings['help']['framework']['sections'][0]['items'], fn (array $item): bool => str_contains($item['text'], $library['activate'])), 'text')));

            // The library is for choosing standards; its help lays out the four work areas by the
            // names the header shows, and the figures are explained where they are: Etterlevelsesvurdering.
            $workflow = collect($strings['help']['library']['sections'])->firstWhere('title', $locale === 'no' ? 'Arbeidsflyten' : 'The workflow');
            $this->assertIsArray($workflow, "lang/{$locale}: the library help does not explain the workflow.");
            $this->assertSame(array_values($strings['nav']), array_slice(array_column($workflow['items'], 'title'), 0, 4));
            $this->assertStringNotContainsString($locale === 'no' ? 'Nøkkeltallene' : 'The key figures', implode(' ', array_column($strings['help']['library']['sections'], 'title')));

            // The requirement help has the worked examples: 9.3, an Annex A control and an environmental aspect.
            $requirementSections = array_column($strings['help']['requirement']['sections'], 'title');
            foreach ($locale === 'no'
                ? ['Eksempel: 9.3 Ledelsens gjennomgang', 'Eksempel: ISO/IEC 27001 A.8.13 Sikkerhetskopiering', 'Eksempel: ISO 14001 6.1.2 Miljøaspekter']
                : ['Example: 9.3 Management review', 'Example: ISO/IEC 27001 A.8.13 Backup', 'Example: ISO 14001 6.1.2 Environmental aspects'] as $example) {
                $this->assertContains($example, $requirementSections, "lang/{$locale}: the requirement help lacks «{$example}».");
            }

            // The framework help explains Annex A and the SoA, naming the control choices and the split overview.
            $annex = collect($strings['help']['framework']['sections'])->firstWhere('title', $locale === 'no' ? 'Vedlegg A og SoA (ISO/IEC 27001)' : 'Annex A and the SoA (ISO/IEC 27001)');
            $this->assertIsArray($annex, "lang/{$locale}: the framework help has no Annex A section.");
            $annexText = $this->allText(['sections' => [$annex]]);
            foreach ([$library['applicability']['control_values']['applies'], $library['applicability']['control_values']['not_applicable'], $library['applicability']['control_values']['not_assessed'], 'SoA', '93'] as $label) {
                $this->assertStringContainsString($label, $annexText, "lang/{$locale}: the Annex A help does not name «{$label}».");
            }
            $this->assertContains($library['applicability']['control']['heading'], array_column($annex['items'], 'title'));

            // The library help names all three standards.
            foreach (['ISO 9001', 'ISO/IEC 27001', 'ISO 14001'] as $standard) {
                $this->assertStringContainsString($standard, $strings['help']['library']['intro']);
            }
        }

        $no = $this->allText($this->compliance('no')['help']['requirement']);
        foreach (['kan ikke settes til Ikke relevant', 'Ikke vurdert er ikke et valg', 'aldri et krav som oppfylt automatisk', 'Delvis oppfylt', 'både Inkludert og Utelatt begrunnes', 'kontroll i vedlegg A må være Inkludert'] as $phrase) {
            $this->assertStringContainsString($phrase, $no);
        }
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

    public function test_the_attention_reasons_are_the_services_and_none_is_about_a_missing_owner(): void
    {
        foreach (['no', 'en'] as $locale) {
            $attention = $this->compliance($locale)['attention'];
            $this->assertSame(ComplianceAttentionService::REASONS, array_keys($attention['reasons']), $locale);
            $this->assertArrayNotHasKey('missing_owner', $attention['reasons'], $locale);
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
        $this->assertSame('Kravregister', $no['index_heading']);
        $this->assertSame(
            ['library' => 'Kravbibliotek', 'requirements' => 'Kravregister', 'assessments' => 'Etterlevelsesvurdering', 'audits' => 'Revisjon'],
            $no['nav'],
        );
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
            ['Sett som utgått', 'Gjenåpne', 'Statushistorikk', 'Ingen valgt', 'Kravkilder'],
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

            // Both pages name findings.
            $this->assertMatchesRegularExpression($locale === 'no' ? '/[Ff]unn/' : '/[Ff]indings/', $this->allText($help['audit_index']));
            $this->assertMatchesRegularExpression($locale === 'no' ? '/[Ff]unn/' : '/[Ff]indings/', $this->allText($help['audit']));
        }
    }

    public function test_the_audit_help_explains_findings_and_their_follow_up_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $audits = $this->compliance($locale)['audits'];
            $findings = $audits['findings'];
            $sections = $this->compliance($locale)['help']['audit']['sections'];

            // A section named like the page section, after scope and before carrying it out — the page's order.
            $titles = array_column($sections, 'title');
            $index = array_search($findings['heading'], $titles, true);
            $this->assertIsInt($index, "lang/{$locale}: the audit help has no «{$findings['heading']}» section.");
            $this->assertGreaterThan(array_search($audits['scope_heading'], $titles, true), $index);
            $itemTitles = array_column($sections[$index]['items'], 'title');

            // Every type, the hand-off and the handed-off state, under the labels the page uses.
            foreach ([...array_values($findings['types']), $findings['hand_off'], $findings['handed_off']] as $label) {
                $this->assertContains($label, $itemTitles, "lang/{$locale}: the findings help does not explain «{$label}».");
            }
        }

        // A finding is not an action; follow-up is in Avvik og forbedringer, explicit, and locks it;
        // hidden cases and Kvalitet context are not shown.
        $no = $this->allText(['sections' => [collect($this->compliance('no')['help']['audit']['sections'])->firstWhere('title', 'Funn')]]);
        foreach (['ingen ansvarlig, frist, status eller tiltak', 'Avvik og forbedringer', 'aldri automatisk', 'låses for godt', 'ikke status, tiltak eller frister', 'tilgang til Kvalitet', 'uten lenke og tittel', 'etter at revisjonen er fullført'] as $phrase) {
            $this->assertStringContainsString($phrase, $no);
        }
    }

    public function test_the_finding_labels_cover_exactly_the_types_that_exist(): void
    {
        foreach (['no', 'en'] as $locale) {
            $findings = $this->compliance($locale)['audits']['findings'];

            $this->assertSame(ComplianceAuditFinding::TYPES, array_keys($findings['types']));
            $this->assertSame(ComplianceAuditFinding::TYPES, array_keys($findings['type_hints']));
            $this->assertSame(['deviation', 'improvement'], array_keys($findings['improvement_types']));
        }

        $no = $this->compliance('no')['audits']['findings'];
        $this->assertSame(['nonconformity' => 'Avvik', 'observation' => 'Observasjon', 'opportunity' => 'Forbedringsmulighet'], $no['types']);
        $this->assertSame(['Funn', 'Nytt funn', 'Følg opp i Avvik og forbedringer', 'Overført'], [$no['heading'], $no['create'], $no['hand_off'], $no['handed_off']]);
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
