/**
 * The pages in Etterlevelse og revisjon that carry PageHelp, each with its own text under
 * translations.compliance.help.<page>: the Krav register, one requirement, the Revisjoner register
 * and one audit.
 */
export const COMPLIANCE_HELP_PAGES = ['index', 'requirement', 'audit_index', 'audit'];

/**
 * The PageHelpButton props for one page. The texts are whole sections in the language files, so the
 * Norwegian and English help keep the same structure; without them the panel just has no sections.
 *
 * @param {object} tr    translations.compliance
 * @param {string} page  one of COMPLIANCE_HELP_PAGES
 */
export function complianceHelp(tr, page) {
    const help = tr?.help ?? {};
    const content = help[page] ?? {};

    return {
        buttonLabel: help.button ?? 'Hjelp',
        title: content.title ?? 'Om krav',
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
