/**
 * The views in Kvalitet that carry PageHelp, each with its own text under
 * translations.quality.help.<page>: the four tabs on the Kvalitet page, and one document's page by
 * kind — a process, a control, or any of the four governing document types, which share one text.
 */
export const QUALITY_HELP_PAGES = ['overview', 'processes', 'controls', 'tools', 'process', 'control', 'document'];

/** The help page for a tab on the Kvalitet page. */
export function qualityTabHelpPage(tab) {
    return ['overview', 'processes', 'controls', 'tools'].includes(tab) ? tab : 'overview';
}

/** The help page for one quality item's page. */
export function qualityItemHelpPage(type) {
    return type === 'process' || type === 'control' ? type : 'document';
}

/**
 * The PageHelpButton props for one page. The texts are whole sections in the language files, so the
 * Norwegian and English help keep the same structure; without them the panel just has no sections.
 *
 * @param {object} tq    translations.quality
 * @param {string} page  one of QUALITY_HELP_PAGES
 */
export function qualityHelp(tq, page) {
    const help = tq?.help ?? {};
    const content = help[page] ?? {};

    return {
        buttonLabel: help.button ?? 'Hjelp',
        title: content.title ?? 'Om Kvalitet',
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
