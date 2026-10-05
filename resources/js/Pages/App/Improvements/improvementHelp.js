/**
 * The pages in Avvik og forbedringer that carry PageHelp, each with its own text under
 * translations.improvements.help.<page>: the register and one case.
 */
export const IMPROVEMENT_HELP_PAGES = ['index', 'case'];

/**
 * The PageHelpButton props for one page. The texts are whole sections in the language files, so the
 * Norwegian and English help keep the same structure; without them the panel just has no sections.
 *
 * @param {object} tr    translations.improvements
 * @param {string} page  one of IMPROVEMENT_HELP_PAGES
 */
export function improvementHelp(tr, page) {
    const help = tr?.help ?? {};
    const content = help[page] ?? {};

    return {
        buttonLabel: help.button ?? 'Hjelp',
        title: content.title ?? 'Om Avvik og forbedringer',
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
