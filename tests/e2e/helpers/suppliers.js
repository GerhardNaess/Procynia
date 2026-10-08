import { expect, test } from '@playwright/test';
import { tinker } from './risk.js';

/** The password every person the Leverandøroppfølging fixture seeds logs in with. */
export const SUPPLIER_E2E_PASSWORD = 'E2eLev123!';

/** The six-character suffix a Leverandøroppfølging spec puts on what it creates. */
export function supplierE2eSuffix() {
    return Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
}

/** Calls a static method on Tests\Support\SupplierE2EFixture and returns its JSON answer. */
export async function supplierFixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\SupplierE2EFixture::${call});`);
    const match = stdout.match(/\{.*\}|null/);

    if (! match) {
        throw new Error(`Fixture call failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Registers the cleanup for a spec file: before the tests, customers left by earlier runs that never
 * reached teardown are swept; after each test this run's customer is removed with everything in it;
 * and after the file, what is left of the run must be nothing.
 */
export function cleanUpSupplierE2eData(suffix) {
    test.beforeAll(async () => {
        await tinker('\\Tests\\Support\\SupplierE2EFixture::cleanup();');
    });

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\SupplierE2EFixture::cleanup('${suffix}');`);
    });

    test.afterAll(async () => {
        expect(await supplierFixture(`remaining('${suffix}')`)).toEqual({
            customers: 0, suppliers: 0, status_changes: 0, criticality_changes: 0, profiles: 0, profile_changes: 0, control_requirements: 0, requirement_overrides: 0, requirement_evaluations: 0, evaluation_documents: 0, assurance_decisions: 0, due_diligence_assessments: 0, assessments: 0, documents: 0, improvement_cases: 0, case_links: 0, risks: 0, risk_links: 0, requirements: 0, requirement_links: 0, business_areas: 0, roles: 0, users: 0,
        });
    });
}

/**
 * Answers the four ja/nei questions and chooses the level in a Kritikalitet form — the registration
 * section or Vurder/Endre kritikalitet. `answers` lists the questions answered «Ja»; the rest get «Nei».
 */
export async function answerCriticality(scope, level, answers = []) {
    for (const question of ['processes_personal_data', 'has_system_access', 'supports_critical_delivery', 'hard_to_replace']) {
        await scope.getByTestId(`criticality-question-${question}`)
            .getByRole('radio', { name: answers.includes(question) ? 'Ja' : 'Nei', exact: true })
            .check();
    }

    await scope.getByTestId('criticality-level').getByRole('radio', { name: new RegExp(`^${level}`) }).check();
}

/**
 * Fills Vurder leverandør: a rating per criterion (Bra, Akseptabelt, Svakt or Ikke relevant, in the
 * plan's order), the overall result, and the begrunnelse. The date stays at today.
 */
export async function fillAssessment(scope, ratings, result, rationale) {
    const criteria = ['quality_rating', 'delivery_rating', 'security_rating', 'compliance_rating'];

    for (const [index, criterion] of criteria.entries()) {
        await scope.getByTestId(`assessment-criterion-${criterion}`).getByRole('radio', { name: ratings[index], exact: true }).check();
    }

    await scope.getByTestId('assessment-result').getByRole('radio', { name: result, exact: true }).check();
    await scope.locator('#supplier-assessment-rationale').fill(rationale);
}

/** Opens one tab of the supplier page (Oversikt, Krav og kvalifikasjoner, Dokumentasjon …) by its name. */
export async function openSupplierTab(page, name) {
    const tab = page.getByTestId('supplier-page-tabs').getByRole('link', { name, exact: true });
    await tab.click();
    await expect(tab).toHaveAttribute('aria-current', 'page');
}
