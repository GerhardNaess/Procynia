/**
 * «Mine oppgaver», the part that is pure: which groups to show and what a supplier reason says.
 *
 * The backend decides everything that matters — which tasks exist, which group each one is in, and
 * whether the person may act on a reason. This only turns that into words, so the rules stay where
 * the access checks are.
 */

export const MY_TASK_GROUP_FALLBACKS = {
    overdue: 'Forfalt',
    this_week: 'Denne uken',
    later: 'Senere',
    no_due: 'Uten frist',
};

export const MY_TASK_MODULE_FALLBACKS = {
    tender: 'Anbud',
    wiki: 'Wiki',
    supplier: 'Leverandører',
};

/**
 * The groups that have tasks, in the order the backend sent them, each with its label.
 *
 * An empty group is left out rather than shown as an empty heading: four headings over two tasks
 * would make the page look busier than the work is.
 */
export function visibleTaskGroups(myTasks, labels = {}) {
    const groups = Array.isArray(myTasks?.groups) ? myTasks.groups : [];

    return groups
        .filter((group) => Array.isArray(group?.tasks) && group.tasks.length > 0)
        .map((group) => ({
            key: group.key,
            label: labels?.[group.key] ?? MY_TASK_GROUP_FALLBACKS[group.key] ?? group.key,
            tasks: group.tasks,
        }));
}

export function moduleLabel(module, labels = {}) {
    return labels?.[module] ?? MY_TASK_MODULE_FALLBACKS[module] ?? module;
}

/**
 * One supplier reason as a line: the module's own name for the signal, and what it is about — the
 * document, or how many requirements. The signal names are Leverandøroppfølging's own
 * (supplier_management.attention.categories), so «Mine oppgaver» and the supplier register can
 * never call the same thing two different names.
 */
export function supplierReasonText(reason, categories = {}, t = {}) {
    const label = categories?.[reason?.key] ?? reason?.key ?? '';

    if (reason?.document_title) {
        return `${label}: ${reason.document_title}`;
    }

    if (Number.isInteger(reason?.requirement_count) && reason.requirement_count > 0) {
        const count = (t?.requirement_count ?? ':count krav').replace(':count', String(reason.requirement_count));

        return `${label} (${count})`;
    }

    return label;
}

export function taskCountLabel(count, t = {}) {
    return Number(count) === 1 ? (t?.count_one ?? 'oppgave') : (t?.count_many ?? 'oppgaver');
}
