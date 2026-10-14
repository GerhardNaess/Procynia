import { test } from '@playwright/test';
import { tinker } from './risk.js';

/**
 * The six-character suffix every Mål og KPI spec puts on what it creates.
 */
export function objectiveE2eSuffix() {
    return Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
}

/**
 * The name of anything a Mål og KPI spec creates — fagområde, role, objective or KPI. Always use this:
 * Tests\Support\ObjectiveE2EFixture cleans up by this prefix and nothing else, so a name built any
 * other way is left behind.
 */
export function objectiveE2eName(suffix, label) {
    return `E2E Mål ${suffix} ${label}`;
}

/**
 * Registers the cleanup for a spec file: before the tests, leftovers from earlier runs that never
 * reached teardown are swept; after each test this run's own data is removed, whether the test
 * passed, failed or stopped halfway.
 */
export function cleanUpObjectiveE2eData(suffix) {
    test.beforeAll(async () => {
        await tinker('\\Tests\\Support\\ObjectiveE2EFixture::cleanup();');
    });

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\ObjectiveE2EFixture::cleanup('${suffix}');`);
    });
}

/**
 * Seeds the view-only scenario and returns the ids of the visible and the hidden objective.
 */
export async function seedViewOnlyObjectives(suffix) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::seedViewOnly('${suffix}'));`);
    const match = stdout.match(/\{.*\}/);

    if (! match) {
        throw new Error(`Seeding failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Gives the E2E user a role that reads, edits and deletes objectives in a fresh area, and returns
 * the area's name.
 */
export async function seedObjectiveEditor(suffix) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::seedEditor('${suffix}'));`);
    const match = stdout.match(/\{.*\}/);

    if (! match) {
        throw new Error(`Seeding failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Gives the E2E user a role that reads, edits, measures and deletes objectives in a fresh area, and
 * returns the area's name.
 */
export async function seedObjectiveMeasurer(suffix) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::seedMeasurer('${suffix}'));`);
    const match = stdout.match(/\{.*\}/);

    if (! match) {
        throw new Error(`Seeding failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Seeds the process/activity scenario: a fresh area, a role for the E2E user that edits objectives
 * there and reads Kvalitet, and a fresh reader who sees objectives in the area but not Kvalitet.
 * Returns the area's name and the reader's e-mail.
 */
export async function seedObjectiveContext(suffix, readerPassword) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::seedContext('${suffix}', '${readerPassword}'));`);
    const match = stdout.match(/\{.*\}/);

    if (! match) {
        throw new Error(`Seeding failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * A fresh user of the run with English as their language, who reads, edits, measures and deletes
 * objectives in a fresh area. Returns the area's name, the user's name and email.
 */
export async function seedObjectiveEnglishEditor(suffix, password) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::seedEnglishEditor('${suffix}', '${password}'));`);
    const match = stdout.match(/\{.*\}/);

    if (! match) {
        throw new Error(`Seeding failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Clicks (or taps) a text field in the middle of one character of the text it shows, the way a
 * person puts the cursor inside a number: «1 300 000» with index 5 hits the space between «300» and
 * «000». The character's place is measured with the field's own font.
 */
export async function clickOnCharacter(field, index, { tap = false } = {}) {
    const x = await field.evaluate((input, at) => {
        const style = getComputedStyle(input);
        const context = document.createElement('canvas').getContext('2d');
        context.font = style.font;
        const left = context.measureText(input.value.slice(0, at)).width;
        const width = context.measureText(input.value.charAt(at)).width;

        return parseFloat(style.paddingLeft) + parseFloat(style.borderLeftWidth) + left + width / 2 - input.scrollLeft;
    }, index);
    const box = await field.boundingBox();
    const position = { x, y: box.height / 2 };

    if (tap) {
        await field.tap({ position });
    } else {
        await field.click({ position });
    }
}
