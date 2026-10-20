/**
 * Turns "N påstander krever behandling" into an actual list of N things.
 *
 * The count and the cards disagreed: openClaims counts individual claims, while the cards below are
 * REVIEW UNITS — every best-practice claim anchored to the same section is one decision, so three
 * pending claims rendered as a single card carrying a "Samlet vurdering · 3 påstander" badge. The
 * reader saw the number 3 and one box, with no way to tell which three sentences it stood for.
 *
 * This builds one entry per open claim (so the list length always equals the number in the header)
 * while keeping each entry pointed at the card that actually decides it. Entries that share a card
 * know it, so the UI can say so instead of pretending there are three separate decisions.
 *
 * Pure data shaping only: no claim is added, dropped, reordered across units, or re-classified —
 * requiresAction is the caller's existing predicate, unchanged.
 */

const TITLE_MAX_LENGTH = 140;

/**
 * A claim's own sentence is the only thing that tells the reader WHICH claim this is. Trimmed to a
 * readable length on a word boundary rather than mid-word, and never padded with an id.
 *
 * @param {object} claim
 * @returns {string}
 */
export function claimWorklistTitle(claim) {
    const raw = String(claim?.claim_text ?? claim?.page_excerpt ?? '').replace(/\s+/g, ' ').trim();

    if (raw === '') {
        return '';
    }

    if (raw.length <= TITLE_MAX_LENGTH) {
        return raw;
    }

    const cut = raw.slice(0, TITLE_MAX_LENGTH);
    const lastSpace = cut.lastIndexOf(' ');

    return `${(lastSpace > 40 ? cut.slice(0, lastSpace) : cut).trimEnd()}…`;
}

/**
 * Why this claim is waiting for a person, as a stable key the UI translates. Deliberately mirrors
 * the order of the existing claimRequiresAction() predicate so the reason shown is the reason the
 * claim was counted.
 *
 * @param {object} claim
 * @returns {'best_practice'|'defect'|'conflict'|'missing_source'|'missing_excerpt'|'other'}
 */
export function claimWorklistReasonKey(claim) {
    if (claim?.content_origin === 'best_practice') return 'best_practice';
    if (claim?.content_origin === 'internal_error' || claim?.content_origin === 'unsupported_generated_content') return 'defect';
    if (claim?.conflict_flag) return 'conflict';
    if (claim?.source_status === 'missing_source') return 'missing_source';
    if (claim?.source_status === 'missing_excerpt') return 'missing_excerpt';

    return 'other';
}

/**
 * @param {Array<{claim: object, claimIds: Array<number>}>} openUnits from partitionBestPracticeReviewUnits().open
 * @param {Array<object>} claims every claim on the page version
 * @param {(claim: object) => boolean} requiresAction
 * @returns {Array<{claimId: number, cardClaimId: number, position: number, total: number,
 *                  title: string, reasonKey: string, sharedDecisionCount: number}>}
 */
export function buildOpenClaimWorklist(openUnits, claims, requiresAction) {
    const claimsById = new Map((claims ?? []).filter(Boolean).map((claim) => [claim.id, claim]));
    const entries = [];

    for (const unit of openUnits ?? []) {
        const actionable = (unit?.claimIds ?? [])
            .map((id) => claimsById.get(id))
            .filter((claim) => claim !== undefined && requiresAction(claim));

        for (const claim of actionable) {
            entries.push({
                claimId: claim.id,
                cardClaimId: unit?.claim?.id ?? claim.id,
                title: claimWorklistTitle(claim),
                reasonKey: claimWorklistReasonKey(claim),
                sharedDecisionCount: actionable.length,
                position: 0,
                total: 0,
            });
        }
    }

    return entries.map((entry, index) => ({
        ...entry,
        position: index + 1,
        total: entries.length,
    }));
}

/**
 * The claim positions one card decides, so a card covering three claims can say "Påstand 1–3 av 3"
 * instead of claiming to be only the first of them. Entries for one unit are always consecutive,
 * because the work list is built unit by unit.
 *
 * @param {Array<{cardClaimId: number, position: number}>} worklist
 * @returns {Map<number, {first: number, last: number}>}
 */
export function worklistRangesByCard(worklist) {
    const ranges = new Map();

    for (const entry of worklist ?? []) {
        const existing = ranges.get(entry.cardClaimId);

        if (existing === undefined) {
            ranges.set(entry.cardClaimId, { first: entry.position, last: entry.position });
            continue;
        }

        existing.last = entry.position;
    }

    return ranges;
}

/**
 * The card shows the claim sentence and, underneath, "Tekst i Wiki-siden: …". For most claims the
 * two differ only by a trailing full stop, so the reader saw the same sentence twice. Returns the
 * excerpt only when it genuinely differs; punctuation and spacing alone do not count.
 *
 * @param {string} claimText
 * @param {string} pageExcerpt
 * @returns {?string}
 */
export function resolveDistinctPageExcerpt(claimText, pageExcerpt) {
    const normalize = (value) => String(value ?? '')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/[.;:,\u2026]+$/, '')
        .toLowerCase();

    const excerpt = String(pageExcerpt ?? '').trim();

    if (excerpt === '' || normalize(excerpt) === normalize(claimText)) {
        return null;
    }

    return pageExcerpt;
}

/**
 * Which heading the claim card's explanation carries, as a stable key the UI translates.
 *
 * An open card asks the reader to decide. A decided best-practice suggestion is always a QA finding,
 * so its explanation is the finding's reason. A decided unsupported claim may be a registered QA
 * finding or only a diagnostic signal (EnterpriseWikiClaimFindingExplainer::isUserFacingAddition()),
 * and the claim props cannot tell the two apart — so it gets a neutral heading rather than a guess.
 *
 * @param {object} claim
 * @param {boolean} isOpenGroup
 * @returns {'open'|'decided_finding'|'neutral'}
 */
export function claimReasonHeadingKey(claim, isOpenGroup) {
    if (isOpenGroup) return 'open';
    if (claim?.content_origin === 'best_practice') return 'decided_finding';

    return 'neutral';
}

/**
 * Whether a claim's QA finding is still open. A claim finding carries `finding_resolution` from the
 * backend (EnterpriseWikiClaimFindingExplainer::findingResolution()) — the same rule the Kjøringer
 * findings list and the QA gate read — so a deviation explicitly accepted on a pending claim is
 * decided here too. Every other claim (a best-practice suggestion, a plain source claim) is open
 * exactly while it waits for a decision.
 *
 * @param {object} claim
 * @returns {boolean}
 */
export function claimFindingIsOpen(claim) {
    if (typeof claim?.finding_resolution === 'string') {
        return claim.finding_resolution === 'open';
    }

    return claim?.approval_status === 'pending';
}

/**
 * The short QA note the article shows on the paragraph a finding link opened. Its state follows the
 * claim's own decision on this page, by the rule the Kjøringer list uses (claimFindingIsOpen): an
 * open finding still needs a decision, a handled one does not, and a handled one is never called
 * open. The note says nothing more — the explanation, sources and decision live in the claim's card
 * in the verification basis.
 *
 * @param {object} claim
 * @returns {{stateKey: 'requires_review'|'decided', actionKey: 'open_and_handle'|'view'}}
 */
export function claimInlineNoteState(claim) {
    const isOpen = claimFindingIsOpen(claim);

    return {
        stateKey: isOpen ? 'requires_review' : 'decided',
        actionKey: isOpen && claim?.can_handle === true ? 'open_and_handle' : 'view',
    };
}
