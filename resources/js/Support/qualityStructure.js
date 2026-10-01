/**
 * Client-side reading of the quality relation matrix the backend ships in `relation_types`.
 *
 * The matrix is authority that lives in PHP (QualityItemRelation::TYPE_MATRIX, enforced in
 * QualityItemService). These helpers only stop the form offering pairs the service would refuse —
 * a convenience, never the rule, so nothing here may be the only thing standing between a bad pair
 * and the database.
 *
 * The matrix travels as ordered pairs rather than two independent type lists, because the two ends
 * are not independent: `uses` is legal procedure -> work instruction and process -> checklist, but
 * never process -> work instruction. Narrowing the `to` select by the chosen `from` is the whole
 * reason these helpers take the pairs.
 */

function matrixFor(relationTypes, relationType) {
    return (relationTypes ?? []).find((entry) => entry.key === relationType) ?? null;
}

function pairsFor(relationTypes, relationType) {
    return matrixFor(relationTypes, relationType)?.pairs ?? [];
}

/**
 * The items that may sit at the start of a relation.
 *
 * @param {Array<{id:number,title:string,quality_type:string,code:?string}>} items
 * @param {Array<{key:string,pairs:Array<{from:string,to:string}>}>} relationTypes
 * @param {string} relationType
 */
export function candidatesForRelationStart(items, relationTypes, relationType) {
    const allowed = new Set(pairsFor(relationTypes, relationType).map((pair) => pair.from));

    return (items ?? []).filter((item) => allowed.has(item.quality_type));
}

/**
 * The items that may sit at the end of a relation, narrowed to what the chosen start allows.
 *
 * `fromType` is optional so the select can still render before anything is chosen; passing it is
 * what keeps an illegal pair out of the form.
 */
export function candidatesForRelationEnd(items, relationTypes, relationType, fromType = null) {
    const allowed = new Set(
        pairsFor(relationTypes, relationType)
            .filter((pair) => fromType === null || pair.from === fromType)
            .map((pair) => pair.to),
    );

    return (items ?? []).filter((item) => allowed.has(item.quality_type));
}

/**
 * Whether a relation type can be used at all with the items that exist today.
 *
 * At least one legal pair has to be fillable from both ends — "process uses checklist" is unusable
 * with no checklist registered, and saying so is more honest than offering an empty select.
 */
export function relationTypeIsUsable(items, relationTypes, relationType) {
    const byType = new Set((items ?? []).map((item) => item.quality_type));

    return pairsFor(relationTypes, relationType)
        .some((pair) => byType.has(pair.from) && byType.has(pair.to));
}

/**
 * An item label that leads with the virksomhet's own document number when it has one, because that
 * is what people cite each other by.
 */
export function itemLabel(item) {
    if (! item) {
        return '';
    }

    return item.code ? `${item.code} — ${item.title}` : (item.title ?? '');
}
