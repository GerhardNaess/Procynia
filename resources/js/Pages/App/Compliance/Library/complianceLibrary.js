/**
 * Kravbibliotek: the pure parts of the library pages — the tree, the search and filters and the
 * labels. The server decides every relevance; nothing here derives one. Compliance figures are not
 * the library's: they live in Etterlevelsesvurdering.
 */

/** «Vis» on an activated standard: everything, or what relevance and Kontrollvalg leave open or out. */
export const FILTERS = ['all', 'relevance_open', 'not_applicable'];

export const APPLICABILITY_TONES = {
    applies: 'emerald',
    not_applicable: 'slate',
    not_assessed: 'sky',
};

const APPLICABILITY_FALLBACKS = {
    applies: 'Gjelder',
    not_applicable: 'Ikke relevant',
    not_assessed: 'Ikke vurdert',
};

const CONTROL_FALLBACKS = {
    applies: 'Inkludert',
    not_applicable: 'Utelatt',
    not_assessed: 'Ikke valgt',
};

/**
 * «Gjelder», «Ikke relevant», «Ikke vurdert» — or, for a selectable control (rule «control», ISO/IEC
 * 27001 Annex A), its Kontrollvalg: «Inkludert», «Utelatt», «Ikke valgt». Same stored values.
 */
export function applicabilityLabel(value, tr = {}, rule = null) {
    if (rule === 'control') {
        return tr.library?.applicability?.control_values?.[value] ?? CONTROL_FALLBACKS[value] ?? value;
    }

    return tr.library?.applicability?.values?.[value] ?? APPLICABILITY_FALLBACKS[value] ?? value;
}

/** Whether the tree holds selectable controls (ISO/IEC 27001 Annex A). */
export function hasControls(nodes = []) {
    return nodes.some((node) => node.kind === 'requirement' && node.applicability_rule === 'control');
}

/**
 * How a chapter heading reads: «4. Organisasjonens kontekst» for a numbered chapter, the title
 * alone for a lettered part such as Annex A, whose title already names it.
 */
export function chapterHeading(node) {
    return /^\d+$/.test(String(node.clause)) ? `${node.clause}. ${node.title}` : node.title;
}

/**
 * The flat node list from the server as a tree, in the server's order.
 *
 * @param {Array<{id: number, parent_id: number|null}>} nodes
 * @returns {Array} roots, each with `children`
 */
export function buildTree(nodes = []) {
    const byId = new Map(nodes.map((node) => [node.id, { ...node, children: [] }]));
    const roots = [];

    for (const node of byId.values()) {
        const parent = node.parent_id !== null ? byId.get(node.parent_id) : null;

        if (parent) {
            parent.children.push(node);
        } else if (node.parent_id === null) {
            roots.push(node);
        }
    }

    return roots;
}

/** Every requirement under a node (the node itself when it is one). */
export function requirementsUnder(node) {
    if (node.kind === 'requirement') {
        return [node];
    }

    return (node.children ?? []).flatMap(requirementsUnder);
}

/**
 * Whether a requirement passes the filter. Without an activation only «all» means anything — the
 * other filters read the customer's register.
 */
export function matchesFilter(node, filter) {
    const row = node.requirement;

    if (filter === 'all' || ! filter) {
        return true;
    }

    if (! row) {
        return false;
    }

    switch (filter) {
        case 'not_applicable':
            return row.applicability === 'not_applicable';
        case 'relevance_open':
            return row.applicability === 'not_assessed';
        default:
            return true;
    }
}

/** Whether a requirement matches the search in its clause, title or description. */
export function matchesSearch(node, needle) {
    const term = String(needle ?? '').trim().toLowerCase();

    if (term === '') {
        return true;
    }

    return [node.clause, node.title, node.description].some((value) => String(value ?? '').toLowerCase().includes(term));
}

/**
 * The tree with only the requirements that pass both the search and the filter, and only the
 * sections that still hold one. A section whose own clause or title matches keeps all its
 * requirements that pass the filter.
 */
export function filterTree(roots, filter = 'all', needle = '') {
    const prune = (node, sectionMatched) => {
        if (node.kind === 'requirement') {
            return (sectionMatched || matchesSearch(node, needle)) && matchesFilter(node, filter) ? node : null;
        }

        const matched = sectionMatched || (String(needle ?? '').trim() !== '' && matchesSearch(node, needle));
        const children = node.children.map((child) => prune(child, matched)).filter(Boolean);

        return children.length > 0 ? { ...node, children } : null;
    };

    return roots.map((root) => prune(root, false)).filter(Boolean);
}

/** Every section id in the tree, for «Åpne alle». */
export function sectionIds(roots) {
    return roots.flatMap((node) => (node.kind === 'section' ? [node.id, ...sectionIds(node.children)] : []));
}

/**
 * What the activation dialog says will happen: «123 krav og kontroller legges til …» for a standard
 * with Annex A controls, «65 krav legges til …» otherwise. The count is the card's own.
 */
export function activationText(framework, tl = {}) {
    const template = framework.has_controls
        ? (tl.activate_dialog_text_controls ?? ':count krav og kontroller legges til i virksomhetens kravregister. Eksisterende vurderinger og registreringer blir ikke endret.')
        : (tl.activate_dialog_text ?? ':count krav legges til i virksomhetens kravregister. Eksisterende vurderinger og registreringer blir ikke endret.');

    return template.replace(':count', String(framework.requirement_count));
}
