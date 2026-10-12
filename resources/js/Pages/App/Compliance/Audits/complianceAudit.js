import { formatDay } from '../../Improvements/improvementStatus.js';
import { originLine } from '../../../../Support/complianceNavigation.js';

/** The four statuses, in the order an audit passes through them, each with its badge tone. */
export const AUDIT_STATUS_TONES = {
    planned: 'blue',
    in_progress: 'amber',
    completed: 'emerald',
    cancelled: 'slate',
};

const STATUS_FALLBACKS = {
    planned: 'Planlagt',
    in_progress: 'Under arbeid',
    completed: 'Fullført',
    cancelled: 'Avbrutt',
};

const TYPE_FALLBACKS = {
    internal: 'Intern',
    external: 'Ekstern',
};

/**
 * @param {string} status
 * @param {object} ta  translations.compliance.audits
 */
export function auditStatusLabel(status, ta = {}) {
    return ta.statuses?.[status] ?? STATUS_FALLBACKS[status] ?? status;
}

/**
 * @param {string} type  'internal' | 'external'
 * @param {object} ta    translations.compliance.audits
 */
export function auditTypeLabel(type, ta = {}) {
    return ta.types?.[type] ?? TYPE_FALLBACKS[type] ?? type;
}

/**
 * The planned period as one line: «01.11.2026–15.11.2026», or «Til 15.11.2026» without a start.
 *
 * @param {{planned_start_date: ?string, planned_end_date: ?string}} audit
 * @param {object} ta  translations.compliance.audits
 */
export function plannedPeriodLabel(audit, ta = {}) {
    const end = formatDay(audit?.planned_end_date ?? null, '');

    if (! audit?.planned_start_date) {
        return end === '' ? '—' : (ta.period_until ?? 'Til :date').replace(':date', end);
    }

    return `${formatDay(audit.planned_start_date)}–${end}`;
}

const HISTORY_FALLBACKS = {
    planned_in_progress: 'Startet av :name',
    in_progress_completed: 'Fullført av :name',
    planned_cancelled: 'Avbrutt av :name',
    in_progress_cancelled: 'Avbrutt av :name',
    completed_in_progress: 'Gjenåpnet av :name',
};

/**
 * One status history entry as a sentence. Gjenåpnet is told apart from Startet by where it came
 * from, which is why the key is the pair, not the new status alone.
 *
 * @param {{from_status: string, to_status: string, changed_by_name: ?string}} entry
 * @param {object} ta  translations.compliance.audits
 */
export function describeAuditHistoryEntry(entry, ta = {}) {
    const key = `${entry.from_status}_${entry.to_status}`;
    const template = ta.history?.[key] ?? HISTORY_FALLBACKS[key] ?? key;

    return template.replace(':name', entry.changed_by_name ?? ta.unknown_user ?? 'en tidligere bruker');
}

/**
 * What the page says about editing, by status: nothing while it can be changed; that it is locked
 * when completed (and how to change it), or read-only when cancelled.
 *
 * @param {string} status
 * @param {object} ta  translations.compliance.audits
 */
export function lockedNotice(status, ta = {}) {
    if (status === 'completed') {
        return ta.locked_completed ?? 'Revisjonen er fullført og låst. Gjenåpne den for å gjøre endringer.';
    }

    if (status === 'cancelled') {
        return ta.locked_cancelled ?? 'Revisjonen er avbrutt og kan ikke endres.';
    }

    return null;
}

/**
 * The requirements to choose from, narrowed by every word of the query in reference, title or
 * source. The ones already chosen are always kept, so a search never drops a selection out of sight.
 *
 * @param {Array<{id: number, reference: ?string, title: string, source_label: ?string}>} options
 * @param {string} query
 * @param {Array<number>} selected
 */
export function filterRequirementOptions(options, query, selected = []) {
    const words = String(query ?? '').toLowerCase().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return options;
    }

    return options.filter((option) => {
        if (selected.includes(option.id)) {
            return true;
        }

        const haystack = [option.reference, option.title, option.source_label].filter(Boolean).join(' ').toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
}

/**
 * A requirement as one line, with its origin: «ISO/IEC 27001:2022 · A.5.15 · Tilgangsstyring» — the
 * same as everywhere else in the module. Without an origin (an older payload), «A.5.15 Tilgangsstyring».
 */
export function requirementLabel(requirement) {
    if (requirement.origin) {
        return originLine(requirement.origin);
    }

    return requirement.reference ? `${requirement.reference} ${requirement.title}` : requirement.title;
}

/** The three kinds of finding, in the order the form offers them, each with its badge tone. */
export const FINDING_TYPE_TONES = {
    nonconformity: 'rose',
    observation: 'blue',
    opportunity: 'violet',
};

const FINDING_TYPE_FALLBACKS = {
    nonconformity: 'Avvik',
    observation: 'Observasjon',
    opportunity: 'Forbedringsmulighet',
};

const IMPROVEMENT_TYPE_FALLBACKS = {
    deviation: 'Avvik',
    improvement: 'Forbedring',
};

/**
 * @param {string} type  'nonconformity' | 'observation' | 'opportunity'
 * @param {object} ta    translations.compliance.audits
 */
export function findingTypeLabel(type, ta = {}) {
    return ta.findings?.types?.[type] ?? FINDING_TYPE_FALLBACKS[type] ?? type;
}

/**
 * The Avvik og forbedringer type a finding is followed up as — decided by the server, shown here.
 *
 * @param {string} type  'deviation' | 'improvement'
 * @param {object} ta    translations.compliance.audits
 */
export function improvementTypeLabel(type, ta = {}) {
    return ta.findings?.improvement_types?.[type] ?? IMPROVEMENT_TYPE_FALLBACKS[type] ?? type;
}

/**
 * What the Funn section says about changing findings, by the audit's status: nothing while they can
 * be recorded; that they are locked but can still be followed up once completed; read-only when
 * cancelled. A planned audit has no findings yet, which the empty text says.
 *
 * @param {string} status
 * @param {object} ta  translations.compliance.audits
 */
export function findingsNotice(status, ta = {}) {
    if (status === 'completed') {
        return ta.findings?.locked_completed ?? 'Revisjonen er fullført, og funnene kan ikke endres. Funn som ikke er overført, kan fortsatt følges opp i Avvik og forbedringer.';
    }

    if (status === 'cancelled') {
        return ta.findings?.locked_cancelled ?? 'Revisjonen er avbrutt. Funnene kan ikke endres eller følges opp herfra.';
    }

    return null;
}

/**
 * Options split into those in the audit's scope and the rest, each keeping the server's order. An
 * option without an in_scope flag counts as not in scope.
 *
 * @param {Array<{in_scope?: boolean}>} options
 */
export function splitByScope(options = []) {
    return {
        inScope: options.filter((option) => option.in_scope === true),
        other: options.filter((option) => option.in_scope !== true),
    };
}

/**
 * A finding's form values: its own fields, and its Kvalitet context only when the server sent it —
 * for someone who cannot read Kvalitet the form carries no such fields, so nothing is changed. A new
 * finding recorded from a kontrollhandling («Registrer funn») starts with that check, and with its
 * requirement when the check names exactly one.
 *
 * @param {object|null} finding  a finding row, or null for a new one
 * @param {{check_id?: number, requirement_id?: number}|null} draft  where a new finding comes from
 */
export function findingFormData(finding = null, draft = null) {
    const data = {
        finding_type: finding?.finding_type ?? '',
        title: finding?.title ?? '',
        description: finding?.description ?? '',
        requirement_id: finding?.requirement ? String(finding.requirement.id) : (draft?.requirement_id ? String(draft.requirement_id) : ''),
        check_id: finding?.check ? String(finding.check.id) : (draft?.check_id ? String(draft.check_id) : ''),
    };

    if (finding === null || 'quality_process' in finding) {
        data.quality_process_id = finding?.quality_process ? String(finding.quality_process.id) : '';
        data.control_item_id = finding?.control ? String(finding.control.id) : '';
    }

    return data;
}

/** The three phases of an audit's page, in the order the work passes through them. */
export const AUDIT_PHASES = ['planning', 'execution', 'report'];

const PHASE_FALLBACKS = {
    planning: 'Planlegging',
    execution: 'Gjennomføring',
    report: 'Rapport og oppfølging',
};

/**
 * The phase the audit is in, read off its status — never a status of its own: Planlagt is
 * planning, Under arbeid execution, Fullført report. A cancelled audit is in no phase.
 *
 * @param {string} status
 * @returns {'planning'|'execution'|'report'|null}
 */
export function auditPhase(status) {
    return { planned: 'planning', in_progress: 'execution', completed: 'report' }[status] ?? null;
}

/**
 * @param {string} phase  one of AUDIT_PHASES
 * @param {object} ta     translations.compliance.audits
 */
export function auditPhaseLabel(phase, ta = {}) {
    return ta.phases?.[phase] ?? PHASE_FALLBACKS[phase] ?? phase;
}

const RELEVANCE_FALLBACKS = {
    not_applicable: 'Ikke relevant',
    relevance_open: 'Relevans ikke avklart',
    control_not_applicable: 'Kontroll utelatt',
    control_relevance_open: 'Kontrollvalg ikke gjort',
};

/**
 * Where a requirement stands on relevance, when it is not simply Gjelder: what automatic selection
 * leaves out, so an explicit choice of it stays visible. An Annex A control says so in its own
 * words (Utelatt, Kontrollvalg ikke gjort). Null for a requirement that applies.
 *
 * @param {{relevance?: string, is_control?: boolean}} requirement
 * @param {object} ta  translations.compliance.audits
 */
export function relevanceLabel(requirement, ta = {}) {
    const relevance = requirement?.relevance;

    if (relevance !== 'not_applicable' && relevance !== 'relevance_open') {
        return null;
    }

    const key = requirement.is_control ? `control_${relevance}` : relevance;

    return ta.relevance?.[key] ?? RELEVANCE_FALLBACKS[key];
}

/**
 * The requirements in scope split by whether a kontrollhandling names them — what the report puts
 * before the auditor ahead of Fullfør. Examined says nothing about being met.
 *
 * @param {Array<{check_count?: number}>} requirements
 */
export function scopeCoverage(requirements = []) {
    return {
        examined: requirements.filter((requirement) => (requirement.check_count ?? 0) > 0),
        unexamined: requirements.filter((requirement) => (requirement.check_count ?? 0) === 0),
    };
}

/**
 * «1 kontrollhandling», «3 kontrollhandlinger»: a count with the one/many texts given.
 *
 * @param {number} count
 * @param {string} one   the text for exactly one
 * @param {string} many  the text for any other count, with :count
 */
export function countText(count, one, many) {
    return count === 1 ? one : many.replace(':count', String(count));
}

/**
 * A check's form values: its own text and the requirements it names, as ids.
 *
 * @param {object|null} check  a check row, or null for a new one
 */
export function checkFormData(check = null) {
    return {
        title: check?.title ?? '',
        observation: check?.observation ?? '',
        evidence: check?.evidence ?? '',
        requirement_ids: (check?.requirements ?? []).map((requirement) => requirement.id),
    };
}
