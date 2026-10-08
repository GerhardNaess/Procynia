import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { SUPPLIER_HELP_PAGES, supplierHelp } from './supplierHelp.js';
import {
    ASSESSMENT_CRITERIA,
    CRITICALITY_TONES,
    DOCUMENT_STATUS_TONES,
    assessmentNeedsFollowUp,
    caseHandoffPrefill,
    attentionFindingText,
    attentionPanel,
    attentionTotalLabel,
    caseOriginText,
    filterRequirementOptions,
    requirementOptionLabel,
    riskLevelText,
    riskOriginText,
    RESULT_TONES,
    SUPPLIER_STATUS_TONES,
    categoryLabel,
    chooseCriticality,
    countLabel,
    criterionLabel,
    criticalityLabel,
    describeCriticalityChange,
    describeCriticalityRegistration,
    describeHistoryEntry,
    describeRegistration,
    documentFormData,
    documentStatusLabel,
    documentTypeLabel,
    emptyAssessment,
    emptyCriticality,
    intervalLabel,
    intervalRequired,
    locationHref,
    nextReviewText,
    ratingLabel,
    resultLabel,
    statusLabel,
} from './supplierManagement.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const PAGE_FILES = { index: './Index.jsx', control_requirements: './ControlRequirements.jsx', supplier: './Show.jsx' };

describe('Every Leverandører page carries the shared PageHelp', () => {
    test('each help page is rendered by its page through PageHelpButton', () => {
        assert.deepEqual(Object.keys(PAGE_FILES), SUPPLIER_HELP_PAGES);

        for (const [page, file] of Object.entries(PAGE_FILES)) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.supplierHelp\\(\\w+, '${page}'\\)\\} />`), file);
        }
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = supplierHelp({}, 'supplier');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});

describe('No text below 16 px in the module', () => {
    test('no component uses text-xs or text-sm', () => {
        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(source(`./${file}`), /\btext-(xs|sm)\b/, file);
        }
    });
});

describe('Status is never a form field', () => {
    test('the shared form offers the in-use choice only when registering, and no status select', () => {
        const form = source('./SupplierForm.jsx');
        assert.doesNotMatch(form, /setData\('status'/);
        assert.match(form, /\{initialStatuses && \(/);
        assert.doesNotMatch(source('./Show.jsx'), /initialStatuses=/, 'Rediger must not offer a status choice');
    });
});

describe('Criticality is chosen, never computed', () => {
    test('choosing a level fills in its interval and leaves the answers alone', () => {
        const answered = { ...emptyCriticality(), processes_personal_data: true, hard_to_replace: false, review_interval_months: '6' };

        assert.deepEqual(chooseCriticality(answered, 'critical'), { ...answered, criticality: 'critical', review_interval_months: '12' });
        assert.equal(chooseCriticality(answered, 'important').review_interval_months, '24');
        assert.equal(chooseCriticality(answered, 'standard').review_interval_months, '');
        assert.deepEqual([intervalRequired('standard'), intervalRequired('important'), intervalRequired('critical'), intervalRequired('')], [false, true, true, false]);
    });

    test('the interval is asked for only once a level is chosen, and the answers never set one', () => {
        const fields = source('./CriticalityFields.jsx');
        assert.match(fields, /\{level && \(/);
        assert.doesNotMatch(fields, /setData\('criticality'/, 'only chooseCriticality sets the level, on the person\'s click');
        assert.match(source('./Index.jsx'), /reviewIntervals=\{reviewIntervals\}/, 'registering asks for criticality');
        assert.doesNotMatch(source('./Show.jsx'), /<SupplierForm[^>]*reviewIntervals/s, 'Rediger must not change criticality');
    });

    test('levels, intervals and «Ikke vurdert» read in the domain language', () => {
        assert.deepEqual(['standard', 'important', 'critical'].map((level) => criticalityLabel(level)), ['Standard', 'Viktig', 'Kritisk']);
        assert.equal(new Set(Object.values(CRITICALITY_TONES)).size, 3);
        assert.deepEqual([intervalLabel(12), intervalLabel(null)], ['Hver 12. måned', 'Ingen fast vurdering']);
        assert.match(source('./SupplierCriticalityBadge.jsx'), /tr\.not_classified \?\? 'Ikke vurdert'/);
    });

    test('a history entry says how the level moved, and the registration closes the list', () => {
        const entry = (from, to) => describeCriticalityChange({
            from: from ? { criticality: from } : null,
            to: { criticality: to },
            changed_by_name: 'Kari',
        });

        assert.equal(entry(null, 'standard'), 'Vurdert som Standard av Kari');
        assert.equal(entry('standard', 'important'), 'Endret fra Standard til Viktig av Kari');
        assert.equal(entry('critical', 'critical'), 'Fortsatt Kritisk – endret av Kari');
        assert.equal(
            describeCriticalityRegistration({ classification: { criticality: 'important' }, by_name: null }),
            'Vurdert som Viktig ved registrering av en tidligere bruker',
        );
    });
});

describe('Supplier assessment', () => {
    test('the four criteria, the ratings and the results read as in the plan', () => {
        assert.deepEqual(ASSESSMENT_CRITERIA.map((criterion) => criterionLabel(criterion)), [
            'Kvalitet på leveransen', 'Leveringspresisjon og respons', 'Informasjonssikkerhet og personvern', 'Etterlevelse av avtale og krav',
        ]);
        assert.deepEqual(['good', 'acceptable', 'poor', 'not_relevant'].map((rating) => ratingLabel(rating)), ['Bra', 'Akseptabelt', 'Svakt', 'Ikke relevant']);
        assert.deepEqual(Object.keys(RESULT_TONES).map((result) => resultLabel(result)), ['Tilfredsstillende', 'Delvis tilfredsstillende', 'Ikke tilfredsstillende']);
        assert.equal(new Set(Object.values(RESULT_TONES)).size, 3);
    });

    test('Neste vurdering is a date, or says why there is none', () => {
        assert.equal(nextReviewText({ next_review_on: '2027-10-07', last_assessed_on: '2026-10-07' }, {}, (date) => `«${date}»`), '«2027-10-07»');
        assert.equal(nextReviewText({ next_review_on: null, last_assessed_on: '2026-10-07' }), 'Ingen fast vurdering');
        assert.equal(nextReviewText({ next_review_on: null, last_assessed_on: null }), 'Ikke vurdert');
    });

    test('the form starts with nothing chosen, and the criticality in it is context only', () => {
        assert.deepEqual(emptyAssessment('2026-10-07'), {
            quality_rating: '', delivery_rating: '', security_rating: '', compliance_rating: '', overall_result: '', rationale: '', assessed_on: '2026-10-07',
        });
        const form = source('./SupplierAssessment.jsx');
        assert.doesNotMatch(form, /setData\('(criticality|review_interval_months)'/);
        assert.doesNotMatch(form, /CriticalityFields/);
        assert.match(form, /\[current, \.\.\.earlier\] = assessments/, 'the newest is the current one, the rest the history');
    });
});

describe('Documentation', () => {
    test('the types and statuses read as in the plan, and the four statuses differ', () => {
        assert.deepEqual(
            ['agreement', 'data_processing_agreement', 'confidentiality_agreement', 'certificate', 'insurance_certificate', 'security_documentation', 'other'].map((type) => documentTypeLabel(type)),
            ['Avtale', 'Databehandleravtale', 'Taushetserklæring', 'Sertifikat', 'Forsikringsbevis', 'Sikkerhetsdokumentasjon', 'Annet'],
        );
        assert.deepEqual(Object.keys(DOCUMENT_STATUS_TONES).map((status) => documentStatusLabel(status)), ['Gyldig', 'Utløpt', 'Ingen utløpsdato', 'Erstattet']);
        assert.equal(new Set(Object.values(DOCUMENT_STATUS_TONES)).size, 4);
    });

    test('a location is a link only when it is a web address, and the page never offers a file', () => {
        assert.equal(locationHref('https://contoso.sharepoint.com/sites/innkjop/avtaler'), 'https://contoso.sharepoint.com/sites/innkjop/avtaler');
        for (const text of ['Arkiv sak 2026/114', 'javascript:alert(1)', 'file:///C:/avtaler/dba.pdf', '', null]) {
            assert.equal(locationHref(text), null, String(text));
        }
        const section = source('./SupplierDocuments.jsx');
        assert.doesNotMatch(section, /type="file"|download|upload/i);
    });

    test('a renewal keeps the type and name, and asks for a new location and validity', () => {
        const row = { id: 7, document_type: 'certificate', title: 'ISO 27001-sertifikat', location: 'Arkiv 1', valid_from: '2025-01-01', valid_until: '2026-01-01', comment: 'Gammel' };
        assert.deepEqual(documentFormData('renew', row), { document_type: 'certificate', title: 'ISO 27001-sertifikat', location: '', valid_from: '', valid_until: '', comment: '' });
        assert.deepEqual(documentFormData('edit', row), { document_type: 'certificate', title: 'ISO 27001-sertifikat', location: 'Arkiv 1', valid_from: '2025-01-01', valid_until: '2026-01-01', comment: 'Gammel' });
        assert.equal(documentFormData('create').document_type, '');
    });
});

describe('Avvik og forbedringer hos leverandøren', () => {
    test('the hand-off suggests a title and description, never a type, and follows up only a weak assessment', () => {
        assert.deepEqual(caseHandoffPrefill({ name: 'Acme AS' }, null), {
            title: 'Leverandør: Acme AS',
            description: 'Sak opprettet fra Leverandøroppfølging for Acme AS.',
        });
        const weak = { id: 3, assessed_on: '2026-10-01', overall_result: 'unsatisfactory', rationale: 'Svar tar for lang tid.' };
        const fromAssessment = caseHandoffPrefill({ name: 'Acme AS' }, weak, {}, (date) => `«${date}»`);
        assert.match(fromAssessment.description, /«2026-10-01».*Ikke tilfredsstillende/);
        assert.match(fromAssessment.description, /Svar tar for lang tid\./);
        assert.equal(assessmentNeedsFollowUp(weak), true);
        assert.equal(assessmentNeedsFollowUp({ overall_result: 'satisfactory' }), false);

        const form = source('./SupplierImprovementCases.jsx');
        assert.match(form, /type: '',/, 'the type starts unchosen');
    });

    test('the section says nothing without access to Avvik og forbedringer, and shows only what the server sent', () => {
        const section = source('./SupplierImprovementCases.jsx');
        assert.match(section, /if \(cases === null \|\| cases === undefined\) \{\s*return null;/);
        assert.match(section, /canHandOff = Boolean\(handoff\) && \(handoff\.area_options \?\? \[\]\)\.length > 0/, 'the follow-up button needs an area to create in');
        assert.equal(caseOriginText({ origin: 'handoff', assessed_on: null }), 'Opprettet fra leverandøren');
        assert.equal(caseOriginText({ origin: 'handoff', assessed_on: '2026-10-01' }, {}, (date) => `«${date}»`), 'Opprettet fra vurderingen «2026-10-01»');
        assert.equal(caseOriginText({ origin: 'linked', assessed_on: null }), 'Koblet til senere');
    });
});

describe('Risikoer som gjelder leverandøren', () => {
    test('the level is Risiko\'s — residual, else inherent, else not assessed — and the origin is named in domain words', () => {
        assert.equal(riskLevelText({ kind: 'residual', level: 'high' }), 'Restrisiko: Høy');
        assert.equal(riskLevelText({ kind: 'inherent', level: 'very_high' }), 'Iboende risiko: Svært høy');
        assert.equal(riskLevelText(null), 'Restrisiko: Ikke vurdert');
        assert.equal(riskOriginText({ origin: 'created_from_supplier' }), 'Opprettet fra leverandøren');
        assert.equal(riskOriginText({ origin: 'linked' }), 'Koblet til senere');
    });

    test('the section says nothing without access to Risiko, prefills only the title and unlinks only where allowed', () => {
        const section = source('./SupplierRisks.jsx');
        assert.match(section, /if \(risks === null \|\| risks === undefined\) \{\s*return null;/);
        assert.match(section, /canCreate = Boolean\(handoff\) && \(handoff\.area_options \?\? \[\]\)\.length > 0/, 'Opprett risiko needs an area to create in');
        assert.match(section, /cause: '',\s*event: '',\s*consequence: '',\s*business_area_id: '',/, 'årsak, hendelse, konsekvens and fagområde start empty');
        assert.match(section, /entry\.can_unlink/, 'Fjern koblingen only where the server allows it');
    });
});

describe('Trenger oppmerksomhet', () => {
    test('each finding reads as a sentence in domain words, and the panel shows five before «Vis alle»', () => {
        assert.equal(attentionFindingText({ key: 'not_assessed', criticality: 'critical' }), 'Leverandøren er Kritisk og mangler leverandørvurdering.');
        assert.equal(attentionFindingText({ key: 'review_overdue', next_review_on: '2026-10-06' }), 'Neste leverandørvurdering var 2026-10-06 og er forfalt.');
        assert.equal(attentionFindingText({ key: 'missing_owner' }), 'Leverandøren mangler intern ansvarlig.');
        const tr = { attention: { documents: { insurance_certificate: 'Forsikringsbeviset', certificate: 'Sertifikatet' } } };
        assert.equal(attentionFindingText({ key: 'document_expired', document_type: 'insurance_certificate', title: 'Ansvar 2025', valid_until: '2026-10-06', days: -1 }, tr), 'Forsikringsbeviset «Ansvar 2025» er utløpt (gyldig til 2026-10-06).');
        assert.equal(attentionFindingText({ key: 'document_expiring', document_type: 'certificate', title: 'ISO 27001', valid_until: '2026-10-25', days: 18 }, tr), 'Sertifikatet «ISO 27001» utløper om 18 dager (2026-10-25).');
        assert.equal(attentionFindingText({ key: 'document_expiring', document_type: 'certificate', title: 'ISO 27001', valid_until: '2026-10-07', days: 0 }, tr), 'Sertifikatet «ISO 27001» utløper i dag.');

        assert.equal(attentionPanel(null).visible, false);
        const suppliers = Array.from({ length: 7 }, (_, id) => ({ id, name: `L${id}`, findings: [] }));
        assert.deepEqual([attentionPanel({ total: 7, suppliers }).items.length, attentionPanel({ total: 7, suppliers }, true).items.length, attentionPanel({ total: 7, suppliers }).hasMore], [5, 7, true]);
        assert.equal(attentionTotalLabel(1), '1 leverandør trenger oppmerksomhet');
        assert.equal(attentionTotalLabel(3), '3 leverandører trenger oppmerksomhet');
    });
});

describe('Krav som gjelder leverandøren', () => {
    test('an offered requirement reads as reference, title and kravkilde, and the search matches every word', () => {
        const options = [
            { id: 1, reference: 'A.5.15', title: 'Tilgangsstyring', source_label: 'ISO 27001 (2022)' },
            { id: 2, reference: null, title: 'Sikkerhetskopi hver natt', source_label: 'Driftsavtale Acme' },
        ];
        assert.equal(requirementOptionLabel(options[0]), 'A.5.15 Tilgangsstyring – ISO 27001 (2022)');
        assert.equal(requirementOptionLabel(options[1]), 'Sikkerhetskopi hver natt – Driftsavtale Acme');
        assert.deepEqual(filterRequirementOptions(options, '  ').map((o) => o.id), [1, 2]);
        assert.deepEqual(filterRequirementOptions(options, 'iso tilgang').map((o) => o.id), [1]);
        assert.deepEqual(filterRequirementOptions(options, 'acme natt').map((o) => o.id), [2]);
    });

    test('the section says nothing without access, never shows a compliance status and adds or removes only when allowed', () => {
        const section = source('./SupplierRequirements.jsx');
        assert.match(section, /if \(requirements === null \|\| requirements === undefined\) \{\s*return null;/);
        assert.doesNotMatch(section, /StatusBadge|entry\.status|entry\.result|compliance_status/, 'no compliance status on the supplier page');
        assert.match(section, /\{linking && ! adding && \(\s*<button type="button" onClick=\{\(\) => remove\(entry\)\}/, 'Fjern krav only with linking rights on an open supplier');
    });
});

describe('supplierManagement', () => {
    test('every status has its own badge tone and a Norwegian fallback', () => {
        assert.deepEqual(Object.keys(SUPPLIER_STATUS_TONES), ['onboarding', 'active', 'ended']);
        assert.equal(new Set(Object.values(SUPPLIER_STATUS_TONES)).size, 3);
        assert.deepEqual(['onboarding', 'active', 'ended'].map((status) => statusLabel(status)), ['Under vurdering', 'Aktiv', 'Avsluttet']);
        assert.equal(statusLabel('active', { statuses: { active: 'Active' } }), 'Active');
        assert.equal(categoryLabel('it_cloud'), 'IT og skytjenester');
    });

    test('a history entry names the action, telling an activation from a reopening', () => {
        const entry = (from, to, name = 'Kari') => describeHistoryEntry({ from_status: from, to_status: to, changed_by_name: name });

        assert.equal(entry('onboarding', 'active'), 'Tatt i bruk av Kari');
        assert.equal(entry('ended', 'active'), 'Gjenåpnet av Kari');
        assert.equal(entry('active', 'ended'), 'Avsluttet av Kari');
        assert.equal(entry('onboarding', 'ended', null), 'Avsluttet av en tidligere bruker');
        assert.equal(describeRegistration({ status: 'onboarding', by_name: 'Ola' }), 'Registrert som Under vurdering av Ola');
    });

    test('the count reads in the singular for one', () => {
        assert.equal(countLabel(1), '1 leverandør');
        assert.equal(countLabel(3), '3 leverandører');
    });
});
