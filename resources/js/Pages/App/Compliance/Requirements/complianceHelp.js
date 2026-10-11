/**
 * The pages in Etterlevelse og revisjon that carry PageHelp, each with its own text under
 * translations.compliance.help.<page>: Kravregister, one requirement, Etterlevelsesvurdering, the Revisjoner register,
 * one audit, Kravbibliotek and one framework in it.
 */
export const COMPLIANCE_HELP_PAGES = ['index', 'requirement', 'assessments', 'audit_index', 'audit', 'library', 'framework'];

/**
 * The PageHelpButton props for one page. The texts are whole sections in the language files, so the
 * Norwegian and English help keep the same structure; without them the panel just has no sections.
 * Every page opens with what it is for (the intro, under purpose_heading) and then «Slik gjør du
 * det»: numbered steps through the work, before the sections that explain terms and statuses.
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
        introTitle: help.purpose_heading,
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
