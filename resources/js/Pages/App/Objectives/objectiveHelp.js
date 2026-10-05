/**
 * The pages in Mål og KPI that carry PageHelp, each with its own text under
 * translations.objectives.help.<page>: the overview with Trenger oppmerksomhet, one objective and
 * one KPI with its measurements.
 */
export const OBJECTIVE_HELP_PAGES = ['index', 'objective', 'kpi'];

/**
 * The PageHelpButton props for one page. The texts are whole sections in the language files, so the
 * Norwegian and English help keep the same structure; without them the panel just has no sections.
 *
 * @param {object} tr    translations.objectives
 * @param {string} page  one of OBJECTIVE_HELP_PAGES
 */
export function objectiveHelp(tr, page) {
    const help = tr?.help ?? {};
    const content = help[page] ?? {};

    return {
        buttonLabel: help.button ?? 'Hjelp',
        title: content.title ?? 'Om Mål og KPI',
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
