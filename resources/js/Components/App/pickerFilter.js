const normalize = (value) => String(value ?? '').toLocaleLowerCase('nb').normalize('NFKD').replace(/[̀-ͯ]/g, '');

/**
 * The options of a SearchableMultiSelect that match the search text: every word must appear in the
 * label, the description or the keywords, in any order and regardless of case and accents.
 */
export function filterPickerOptions(options, query) {
    const words = normalize(query).split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return options;
    }

    return options.filter((option) => {
        const haystack = normalize(`${option.label} ${option.description ?? ''} ${option.keywords ?? ''}`);

        return words.every((word) => haystack.includes(word));
    });
}
