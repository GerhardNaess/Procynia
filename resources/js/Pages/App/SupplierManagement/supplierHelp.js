/**
 * The PageHelpButton props for one page in Leverandøroppfølging, from
 * translations.supplier_management.help.<page>. The texts are whole sections in the language files,
 * so the Norwegian and English help keep the same structure; without them the panel just has no
 * sections.
 *
 * @param {object} tr    translations.supplier_management
 * @param {string} page  'index'
 */
export function supplierHelp(tr, page) {
    const help = tr?.help ?? {};
    const content = help[page] ?? {};

    return {
        buttonLabel: help.button ?? 'Hjelp',
        title: content.title ?? 'Om leverandøroppfølging',
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
