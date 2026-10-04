/**
 * Fills the required risk description (årsak → hendelse → konsekvens) in an open risk form.
 */
export async function fillRiskDescription(page, {
    cause = 'manglende rutiner',
    event = 'en hendelse inntreffer',
    consequence = 'virksomheten rammes',
} = {}) {
    await page.locator('#risk-cause').fill(cause);
    await page.locator('#risk-event').fill(event);
    await page.locator('#risk-consequence').fill(consequence);
}
