/**
 * Focus and error wiring on the Wiki page (Show.jsx), kept pure so it is unit-tested.
 */

/**
 * Where focus goes after a decision on a claim, if the button that was pressed is gone (A11Y-014).
 *
 * A decided claim leaves the «krever behandling» list, so the card holding the button unmounts. The
 * reviewer's next job is the next claim in that list, so that card comes first — wrapping round to
 * the ones above when the decided claim was the last. Then the decided claim's own card (in the
 * verified list, if that is open), and last the section's heading, which always exists.
 *
 * @param {number|string} claimId          The claim that was decided.
 * @param {Array<number|string>} openCardClaimIds The claim ids of the open cards, in page order, as
 *                                         they were when the decision was made.
 * @returns {string[]} Element ids, in order of preference (for refocusIfLost).
 */
export function claimActionFocusCandidates(claimId, openCardClaimIds = []) {
    const ids = openCardClaimIds.map(String);
    const index = ids.indexOf(String(claimId));
    const others = index === -1 ? ids : [...ids.slice(index + 1), ...ids.slice(0, index)];

    return [...others.map((id) => `claim-${id}`), `claim-${claimId}`, 'wiki-verification-toggle'];
}

/**
 * The paragraph a refused article save is about. Laravel names it by its position in the request
 * («blocks.1.markdown»); the editor remembers which block keys it sent, in that order.
 *
 * @param {object} errors            The page's error bag.
 * @param {string[]} submittedKeys   block_key of each block sent, in request order.
 * @returns {?string}
 */
export function articleEditErrorBlockKey(errors, submittedKeys = []) {
    for (const key of Object.keys(errors ?? {})) {
        const match = /^blocks\.(\d+)(\.|$)/.exec(key);

        if (match && errors[key]) {
            return submittedKeys[Number(match[1])] ?? null;
        }
    }

    return null;
}
