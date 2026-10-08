/**
 * Pure helpers for Kontrollkrav and Krav og kvalifikasjoner (docs/supplier-assurance-v2-plan.md §5,
 * §22). What applies and why is decided by the server; these only name codes and arrange rows.
 * Never shows a predicate name, a rule structure or a score.
 */

export const LEVELS = ['mandatory', 'important', 'standard'];

/** Obligatorisk is the strongest; Oppfølging the quietest. */
export const LEVEL_TONES = { mandatory: 'rose', important: 'amber', standard: 'slate' };

export function themeLabel(theme, tr) {
    return tr?.control?.themes?.[theme] ?? theme;
}

export function levelLabel(level, tr) {
    return tr?.control?.levels?.[level] ?? level;
}

export function controlPointLabel(point, tr) {
    return tr?.control?.control_points?.[point] ?? point;
}

export function intervalLabel(months, tr) {
    const c = tr?.control ?? {};

    return months ? (c.interval_option ?? 'Hver :months. måned').replace(':months', String(months)) : (c.interval_none ?? 'Ingen fast kontroll');
}

/**
 * Rows in the order the server sent them (theme, then level, then title), grouped by theme.
 *
 * @returns {{ theme: string, label: string, rows: object[] }[]}
 */
export function groupByTheme(rows, tr) {
    const groups = [];

    for (const row of rows ?? []) {
        let group = groups.find((candidate) => candidate.theme === row.theme);

        if (! group) {
            group = { theme: row.theme, label: themeLabel(row.theme, tr), rows: [] };
            groups.push(group);
        }

        group.rows.push(row);
    }

    return groups;
}

/**
 * Which override actions a row on the supplier offers. The server says what is allowed; a mandatory
 * requirement is never offered «Gjelder ikke denne leverandøren», whatever it said — that action
 * would always be refused.
 */
export function rowActions(row) {
    return {
        exclude: Boolean(row?.can_exclude) && row?.level !== 'mandatory' && ! row?.supplier_specific,
        clear: Boolean(row?.can_clear),
    };
}

/** «Gjelder 3 leverandører nå». */
export function appliesToText(count, tr) {
    const c = tr?.control?.catalogue ?? {};

    if (! count) {
        return c.applies_to_none ?? 'Gjelder ingen leverandører nå';
    }

    return count === 1
        ? (c.applies_to_one ?? 'Gjelder 1 leverandør nå')
        : (c.applies_to_many ?? 'Gjelder :count leverandører nå').replace(':count', String(count));
}

/** «Art. 28 Krav til databehandlere», or the title alone. */
export function anchorLabel(anchor) {
    if (! anchor) {
        return '';
    }

    return [anchor.reference, anchor.title].filter(Boolean).join(' ');
}

/**
 * The form's fields: empty for a new requirement, or filled from a catalogue row. A rule the simple
 * form cannot write (none in this phase) falls back to «Alle leverandører».
 */
export function requirementFormData(requirement = null) {
    const rule = requirement?.rule ?? { rule_mode: 'all', conditions: [], criticality_scope: '' };

    return {
        title: requirement?.title ?? '',
        description: requirement?.description ?? '',
        guidance: requirement?.guidance ?? '',
        theme: requirement?.theme ?? '',
        level: requirement?.level ?? '',
        control_point: requirement?.control_point ?? '',
        control_interval_months: requirement?.control_interval_months ? String(requirement.control_interval_months) : '',
        accepted_document_types: requirement?.accepted_document_types ?? [],
        basis_text: requirement?.basis_text ?? '',
        compliance_requirement_id: requirement?.anchor?.id ? String(requirement.anchor.id) : '',
        rule_mode: rule.rule_mode,
        conditions: rule.conditions ?? [],
        criticality_scope: rule.criticality_scope ?? '',
    };
}

/** Adds or removes one code, keeping at most `max`. */
export function toggleCode(list, code, max = Infinity) {
    const current = Array.isArray(list) ? list : [];

    if (current.includes(code)) {
        return current.filter((item) => item !== code);
    }

    return current.length >= max ? current : [...current, code];
}
