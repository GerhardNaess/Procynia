/**
 * Where focus goes after «Slett» on one business-review date in the deadline editor (WCAG 2.4.3,
 * A11Y-146). The row — and the button that was pressed — is removed, so focus would fall to <body>.
 *
 * The rows are keyed and numbered by position, so after removing row `index` the row that followed it now has
 * the same index. In order of preference: that next date, the previous date, then «Legg til», which
 * is always there. Ids are resolved when focus is placed, after React has rendered the shorter list.
 * Pure, so it is unit-tested.
 */
export function businessReviewFocusCandidates(noticeId, index) {
    return [
        `business_review_${noticeId}_${index}`,
        index > 0 ? `business_review_${noticeId}_${index - 1}` : null,
        `business-review-add-${noticeId}`,
    ].filter(Boolean);
}
