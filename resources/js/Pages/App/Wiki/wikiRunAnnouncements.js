import { useEffect, useRef } from 'react';
import { announce } from '../../../Support/announcer.js';

/**
 * Wiki runs, heard when they change — not on every poll (WCAG 4.1.3; A11Y-112).
 *
 * Kildedokumenter and Kjøringer reload their runs every 5 s while one is working. Each row's activity
 * text used to be an aria-live region of its own, so every reload that moved «Siste fremdrift» or a
 * count could be read out, row by row. Now the rows are quiet and the list says one thing when a run
 * moves to another status: «Kjøring for Rutiner.docx: Genererer sider.» — through the app's permanent
 * regions in announcer.js, never a new one.
 *
 * Not spoken: the state the page opened with, a run that appears or disappears (a new upload, a
 * deletion — the user did that themselves and hears its own feedback), and a reload that changed
 * nothing but numbers.
 */

/**
 * The status changes between two snapshots, in list order. Pure, so it is unit-tested.
 *
 * @param {Map<string, string>|null} previous  run id → status key, from the last snapshot; null before the first.
 * @param {Array<{ id: number|string, statusKey: string, name: string, statusLabel: string }>} runs
 * @returns {Array<{ id: string, message: string, failed: boolean }>}
 */
export function runStatusChanges(previous, runs, template = 'Kjøring for :name: :status.') {
    if (! previous) {
        return [];
    }

    return runs
        .filter((run) => previous.has(String(run.id)) && previous.get(String(run.id)) !== run.statusKey)
        .map((run) => ({
            id: String(run.id),
            message: template.replace(':name', run.name).replace(':status', run.statusLabel),
            failed: run.statusKey === 'failed',
        }));
}

export function runStatusSnapshot(runs) {
    return new Map(runs.map((run) => [String(run.id), run.statusKey]));
}

/**
 * Announces the status changes of `runs` after the first render. A failure is assertive, everything
 * else polite; several at once are said together, in one message.
 */
export function useRunStatusAnnouncements(runs, template) {
    const previousRef = useRef(null);
    const signature = runs.map((run) => `${run.id}:${run.statusKey}`).join('|');

    useEffect(() => {
        const changes = runStatusChanges(previousRef.current, runs, template);
        previousRef.current = runStatusSnapshot(runs);

        if (changes.length === 0) {
            return;
        }

        announce(changes.map((change) => change.message).join(' '), {
            politeness: changes.some((change) => change.failed) ? 'assertive' : 'polite',
        });
        // The signature is what changes; `runs` is a new array on every render.
    }, [signature]);
}
