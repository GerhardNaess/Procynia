/**
 * Oppfølging in Leverandørkontroll (docs/supplier-assurance-v2-plan.md §14, §15.1): the requirements a
 * «Trenger oppmerksomhet» finding names, «Neste kontroller», and a requirement's next control. Every
 * date, every rule and every reason was decided by the server (SupplierRequirementStatus::followUp(),
 * SupplierFollowUpPlan, SupplierAttentionService); this only words them.
 */
import { levelLabel } from './controlRequirements.js';
import { displayStatusLabel } from './requirementEvaluations.js';

const OVERDUE_FALLBACKS = {
    control_interval: ':title – kontrollfristen var :date',
    document_expired: ':title – «:document» utløp :date',
    document_replaced: ':title – «:document» er erstattet av en ny utgave',
    acceptance_expired: ':title – midlertidig aksept utløp :date',
};

const KIND_FALLBACKS = {
    control: 'Ny kontroll: :title',
    document_renewal: 'Forny «:document» (brukt for :title)',
    acceptance: 'Midlertidig aksept utløper: :title',
    document: 'Dokumentasjon utløper: «:document»',
    assessment: 'Neste leverandørvurdering',
    due_diligence: 'Neste aktsomhetsvurdering',
};

/** How many entries «Neste kontroller» shows before «Vis alle», unless the server says otherwise. */
export const FOLLOW_UP_PREVIEW = 5;

function fill(template, values) {
    return template.replace(/:(title|date|document|titles)/g, (match, name) => values[name] ?? '');
}

/**
 * The requirements a finding names, one line each with why: «DBA (Ikke vurdert)», «Sikkerhetsrapport
 * – kontrollfristen var 7. oktober 2026». Empty for a finding that names none.
 *
 * @returns {{ id: number, text: string }[]}
 */
export function attentionFindingItems(finding, tr = {}, formatDate = (date) => date) {
    const a = tr.attention ?? {};

    return (finding.requirements ?? []).map((row) => {
        let text;

        if (finding.key === 'control_overdue') {
            const template = a.overdue_reasons?.[row.reason] ?? OVERDUE_FALLBACKS[row.reason] ?? ':title';
            text = fill(template, { title: row.title, date: row.date ? formatDate(row.date) : '', document: row.document_title ?? '' });
        } else if (finding.key === 'decision_required') {
            text = `${row.title} (${displayStatusLabel(row.display_status, tr)})`;
        } else {
            text = `${row.title} (${levelLabel(row.level, tr)})`;
        }

        return { id: row.id, text };
    });
}

/** One line of «Neste kontroller»: what falls due, for which requirement or document. */
export function followUpEntryText(entry, tr = {}) {
    const f = tr.follow_up ?? {};
    const template = f.kinds?.[entry.kind] ?? KIND_FALLBACKS[entry.kind] ?? entry.kind;

    return fill(template, { title: entry.requirement?.title ?? '', document: entry.document?.title ?? '' });
}

/** The date column: the date, or «Nå» for a replaced document, which is due without one. */
export function followUpEntryDate(entry, tr = {}, formatDate = (date) => date) {
    return entry.date ? formatDate(entry.date) : (tr.follow_up?.due_now ?? 'Nå');
}

/**
 * What the «Neste kontroller» card draws: nothing when there is no plan or nothing in it; else the
 * first entries — all once expanded — whether there are more, and the requirements controlled on
 * change, apart.
 */
export function followUpView(plan, expanded = false) {
    const entries = plan?.entries ?? [];
    const onChange = plan?.on_change ?? [];
    const preview = plan?.preview ?? FOLLOW_UP_PREVIEW;

    if (! plan || (entries.length === 0 && onChange.length === 0)) {
        return { visible: false, items: [], hasMore: false, count: 0, onChange: [] };
    }

    return {
        visible: true,
        items: expanded ? entries : entries.slice(0, preview),
        hasMore: entries.length > preview,
        count: entries.length,
        onChange,
    };
}

/**
 * Neste kontroll on a requirement row: «Neste kontroll 8. april 2027», «Kontrollfristen var …» once
 * passed, «Ingen fast kontrollfrist» for a controlled requirement without an interval. Null when the
 * requirement has no control yet or its control is not one that runs out (Mangler, Midlertidig
 * akseptert — that one has its own date).
 */
export function nextControlText(row, tr = {}, formatDate = (date) => date) {
    const f = tr.follow_up ?? {};
    const followUp = row.follow_up ?? null;

    if (! row.evaluated || ! followUp || ! ['documented', 'partially_documented'].includes(row.current?.status)) {
        return null;
    }

    if (! followUp.next_control_on) {
        return f.no_interval ?? 'Ingen fast kontrollfrist';
    }

    const template = followUp.control_overdue
        ? (f.next_control_overdue ?? 'Kontrollfristen var :date')
        : (f.next_control ?? 'Neste kontroll :date');

    return fill(template, { date: formatDate(followUp.next_control_on) });
}
