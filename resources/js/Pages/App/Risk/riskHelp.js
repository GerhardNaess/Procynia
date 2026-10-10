/**
 * The pages in Risiko that carry PageHelp, each with its own text under translations.risk.help.<page>.
 * Today the register (with Trenger oppmerksomhet); the risk page itself has none yet.
 */
export const RISK_HELP_PAGES = ['index'];

/**
 * The PageHelpButton props for one page. The texts are whole sections in the language files, so the
 * Norwegian and English help keep the same structure; without them the panel just has no sections.
 *
 * @param {object} tr    translations.risk
 * @param {string} page  one of RISK_HELP_PAGES
 */
export function riskHelp(tr, page) {
    const help = tr?.help ?? {};
    const content = help[page] ?? {};

    return {
        buttonLabel: help.button ?? 'Hjelp',
        title: content.title ?? 'Om Risiko',
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
