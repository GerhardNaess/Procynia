import './bootstrap';
import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { ensureLiveRegions } from './Support/announcer';
import { installRouteFocus } from './Support/routeFocus';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });

        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        // Before the first render, so the screen-reader regions exist before anything is said into them.
        ensureLiveRegions();
        createRoot(el).render(createElement(App, props));
        // After a real page change, focus goes to the new page's heading; <html lang> follows the locale.
        installRouteFocus(router, { initialPage: props.initialPage });
    },
    progress: {
        color: '#0f172a',
    },
});
