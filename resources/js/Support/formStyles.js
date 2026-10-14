/**
 * The form-field look Procynia already uses, in one place.
 *
 * These strings were copied verbatim into some fifty pages (`const INPUT = …`). They are collected
 * here unchanged so new code and FormField share one definition; pages move over as they are touched
 * in the WCAG phases (docs/wcag-accessibility-plan.md), not in one sweep.
 *
 * Focus: `focus:outline-none` stays because the global focus rule in resources/css/app.css draws the
 * keyboard focus indicator for every field that suppresses the browser's own outline. The border
 * change on focus is kept as the familiar mouse-focus cue, but it is not what makes focus visible —
 * slate-400 against slate-200 is under 3:1.
 */

export const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

export const TEXTAREA = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base leading-6 text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

export const LABEL = 'block text-base font-semibold text-slate-700';

export const HINT = 'mt-1 text-base text-slate-600';

export const ERROR = 'mt-1 text-base text-rose-700';
