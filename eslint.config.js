import jsxA11y from 'eslint-plugin-jsx-a11y';

/**
 * Accessibility lint for the React frontend — and only that. Procynia has no style lint and this
 * file does not introduce one: the only rules are eslint-plugin-jsx-a11y's recommended set.
 *
 * Coverage grows phase by phase (docs/wcag-accessibility-plan.md). A file is added here once its
 * phase has made it clean, and from then on it must stay clean. No rule is switched off to get there.
 *
 * ESLint is pinned to 9.x: eslint-plugin-jsx-a11y 6.10 declares support up to ESLint 9.
 * Runs on Node 22 (CI, Docker); ESLint 9 does not start on the host's Node 18.15.
 */
const A11Y_CHECKED_FILES = [
    // Fase 1 — shared components and the layout's live-region wiring.
    'resources/js/Components/App/ActionDialog.jsx',
    'resources/js/Components/App/FilePickerField.jsx',
    'resources/js/Components/App/FormField.jsx',
    'resources/js/Components/App/PageHelpButton.jsx',
    'resources/js/Components/App/PageHelpPanel.jsx',
];

export default [
    {
        ...jsxA11y.flatConfigs.recommended,
        files: A11Y_CHECKED_FILES,
        languageOptions: {
            ...jsxA11y.flatConfigs.recommended.languageOptions,
            ecmaVersion: 'latest',
            sourceType: 'module',
        },
    },
];
