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
    // Fase 2 — layouts, navigation, flash messages and the standard views.
    'resources/js/Layouts/CustomerAppLayout.jsx',
    'resources/js/Layouts/PublicLayout.jsx',
    'resources/js/Components/App/FlashMessages.jsx',
    'resources/js/Components/App/SkipLink.jsx',
    'resources/js/Components/App/NotificationBell.jsx',
    'resources/js/Components/App/ModuleSidebar.jsx',
    'resources/js/Components/App/Breadcrumbs.jsx',
    'resources/js/Components/App/DashboardCockpit.jsx',
    'resources/js/Pages/Auth/Login.jsx',
    'resources/js/Pages/App/Home/Index.jsx',
    'resources/js/Pages/App/Governance/Index.jsx',
    'resources/js/Pages/App/Dashboard/Index.jsx',
    // Fase 3 — forms, dialogs, tables and status messages in the shared components and the
    // non-module pages (Brukere, Avdelinger, Kundemiljø, Abonnement, Go/No-go-maler,
    // Watch Profiles, Konkurrenter, Oppfølging).
    'resources/js/Components/App/AiCapacityLevelDialog.jsx',
    'resources/js/Components/App/ControlHint.jsx',
    'resources/js/Components/App/DepartmentCheckboxGroup.jsx',
    'resources/js/Components/App/InfoHint.jsx',
    'resources/js/Components/App/ScrollRegion.jsx',
    'resources/js/Components/App/hintTooltip.jsx',
    'resources/js/Pages/App/Billing/Index.jsx',
    'resources/js/Pages/App/CustomerEnvironment/BusinessAreasPanel.jsx',
    'resources/js/Pages/App/CustomerEnvironment/CustomerEnvironmentHeader.jsx',
    'resources/js/Pages/App/CustomerEnvironment/CustomerRolesPanel.jsx',
    'resources/js/Pages/App/CustomerEnvironment/Index.jsx',
    'resources/js/Pages/App/CustomerEnvironment/PermissionSection.jsx',
    'resources/js/Pages/App/Departments/Create.jsx',
    'resources/js/Pages/App/Departments/Edit.jsx',
    'resources/js/Pages/App/Departments/Index.jsx',
    'resources/js/Pages/App/GoNoGo/Templates/Edit.jsx',
    'resources/js/Pages/App/GoNoGo/Templates/Index.jsx',
    'resources/js/Pages/App/InfoCenter/Index.jsx',
    'resources/js/Pages/App/InfoCenter/MyTasks.jsx',
    'resources/js/Pages/App/Suppliers/Index.jsx',
    'resources/js/Pages/App/Suppliers/Show.jsx',
    'resources/js/Pages/App/Users/Create.jsx',
    'resources/js/Pages/App/Users/Edit.jsx',
    'resources/js/Pages/App/Users/Index.jsx',
    'resources/js/Pages/App/WatchProfiles/Create.jsx',
    'resources/js/Pages/App/WatchProfiles/Edit.jsx',
    'resources/js/Pages/App/WatchProfiles/Index.jsx',
    'resources/js/Pages/App/WatchProfiles/WatchProfileForm.jsx',
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
