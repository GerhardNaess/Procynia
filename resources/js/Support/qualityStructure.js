/**
 * Client-side reading of the quality relation matrix the backend ships in `relation_types`.
 *
 * The matrix is authority that lives in PHP (QualityRelation::TYPE_MATRIX, enforced in
 * QualityStructureService). These helpers only let the form stop offering pairs the service would
 * refuse — they are a convenience, never the rule, so nothing here may be the only thing standing
 * between a bad pair and the database.
 */

/**
 * The documents that may sit at one end of a relation.
 *
 * @param {Array<{page_id:number,title:string,quality_type:string,quality_code:?string}>} documents
 * @param {Array<{key:string,from_types:string[],to_types:string[]}>} relationTypes
 * @param {string} relationType
 * @param {'from'|'to'} end
 */
export function candidatesForRelationEnd(documents, relationTypes, relationType, end) {
    const matrix = (relationTypes ?? []).find((entry) => entry.key === relationType);

    if (! matrix) {
        return [];
    }

    const allowed = end === 'from' ? (matrix.from_types ?? []) : (matrix.to_types ?? []);

    return (documents ?? []).filter((document) => allowed.includes(document.quality_type));
}

/**
 * Whether a relation type can be used at all with the documents that exist today.
 *
 * Both ends have to be populated — "process uses checklist" is unusable with no checklist
 * classified, and saying so is more honest than offering an empty select.
 */
export function relationTypeIsUsable(documents, relationTypes, relationType) {
    return candidatesForRelationEnd(documents, relationTypes, relationType, 'from').length > 0
        && candidatesForRelationEnd(documents, relationTypes, relationType, 'to').length > 0;
}

/**
 * A document label that leads with the virksomhet's own document number when it has one, because
 * that is what people cite each other by.
 */
export function documentLabel(document) {
    if (! document) {
        return '';
    }

    return document.quality_code
        ? `${document.quality_code} — ${document.title}`
        : (document.title ?? '');
}
