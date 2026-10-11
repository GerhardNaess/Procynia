/**
 * Etterlevelse og revisjon's four work areas — Kravbibliotek, Kravregister, Etterlevelsesvurdering,
 * Revisjon — and which of them a page belongs to. Pure, so the header and the pages agree.
 *
 * A requirement's own page is shared by two areas: it is where the register shows a requirement and
 * where the requirement is assessed. The link that opened it says which (`from=assessments` from the
 * Etterlevelsesvurdering worklist), so the header keeps the area the person came from and «Tilbake»
 * leads back to it. Without that it belongs to Kravregister.
 */
export const COMPLIANCE_AREAS = ['compliance-library', 'compliance-requirements', 'compliance-assessments', 'compliance-audits'];

/**
 * @param {string} pathname
 * @param {URLSearchParams} searchParams
 * @returns {string} one of COMPLIANCE_AREAS
 */
export function complianceAreaKey(pathname, searchParams = new URLSearchParams()) {
    if (pathname.startsWith('/app/compliance/library')) {
        return 'compliance-library';
    }

    if (pathname.startsWith('/app/compliance/audits')) {
        return 'compliance-audits';
    }

    if (pathname.startsWith('/app/compliance/assessments')) {
        return 'compliance-assessments';
    }

    if (pathname.startsWith('/app/compliance/requirements/') && searchParams.get('from') === 'assessments') {
        return 'compliance-assessments';
    }

    return 'compliance-requirements';
}

const WORKLIST_KEY = 'procynia.compliance.worklist';

/**
 * The worklist URL — with its filters — the person last left, so «Tilbake til etterlevelsesvurdering»
 * on a requirement returns to the same view. Per browser tab, a convenience only: without storage the
 * link simply goes to the unfiltered worklist.
 */
export function rememberWorklist(url) {
    try {
        window.sessionStorage.setItem(WORKLIST_KEY, String(url));
    } catch {
        // Storage can be unavailable; the plain link still works.
    }
}

export function worklistUrl() {
    try {
        const stored = window.sessionStorage.getItem(WORKLIST_KEY);

        if (stored && stored.startsWith('/app/compliance/assessments')) {
            return stored;
        }
    } catch {
        // Fall through to the plain worklist.
    }

    return '/app/compliance/assessments';
}

/**
 * Where «Tilbake» on a requirement leads, from the area it was opened in.
 *
 * @param {string|null} from  the `from` query value
 * @returns {{area: string, href: string|null}}  href null means: the caller uses worklistUrl()
 */
export function requirementBackTarget(from) {
    return from === 'assessments'
        ? { area: 'compliance-assessments', href: null }
        : { area: 'compliance-requirements', href: '/app/compliance/requirements' };
}

/**
 * «ISO 14001:2026 · 6.1.2 · Miljøaspekter»: where a requirement comes from, as every page shows it.
 * A part that is missing (a requirement registered by hand without a reference) is left out.
 *
 * @param {{source: ?string, reference: ?string, title: string}|null} origin
 */
export function originLine(origin) {
    if (! origin) {
        return '';
    }

    return [origin.source, origin.reference, origin.title].filter((part) => part !== null && part !== undefined && String(part).trim() !== '').join(' · ');
}

/** Just the identification — «ISO 14001:2026 · 6.1.2» — for where the title is shown on its own. */
export function originPrefix(origin) {
    if (! origin) {
        return '';
    }

    return [origin.source, origin.reference].filter((part) => part !== null && part !== undefined && String(part).trim() !== '').join(' · ');
}
