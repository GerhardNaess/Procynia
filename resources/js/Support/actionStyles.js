/**
 * The one place that decides what a button's colour means in Procynia.
 *
 * Colour is the only thing that tells a bid manager whether an action commits work, navigates away,
 * or ends a case for good. The app already shared one geometry — min-h-11, rounded-xl, px-4 py-2.5,
 * text-base font-semibold — but four colour families had drifted across roles: the violet tint alone
 * carried external links, internal navigation, ordinary form submits and a terminal Go/No-Go
 * decision at the same time. Colour therefore said nothing, and the strongest visual weight on the
 * case page landed on "Åpne i Doffin" rather than on the decision beside it.
 *
 * Roles, and the question each answers:
 *
 *   PRIMARY      "This commits my work."          Filled violet. One per form or dialog.
 *   SECONDARY    "This takes me somewhere else."  White/outline. Navigation, external links, cancel.
 *   WARNING      "This is hard to undo."          Amber. Terminal but legitimate: archive, No-Go.
 *   DESTRUCTIVE  "This deletes something."        Rose. Data disappears.
 *   DISCLOSURE   "This just shows or hides."      Neutral. Changes nothing but what is on screen.
 *
 * A status badge is not an action and must never borrow these — see BADGE_* in the components that
 * render them.
 */

const BASE = 'inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition';
const DISABLED = 'disabled:cursor-not-allowed disabled:opacity-60';

/** Commits the work: submits a form, confirms a dialog. At most one per surface. */
export const PRIMARY_ACTION = `${BASE} bg-violet-600 text-white hover:bg-violet-700 ${DISABLED}`;

/** Goes somewhere else, or backs out. Never the action a surface exists to perform. */
export const SECONDARY_ACTION = `${BASE} border border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950 ${DISABLED}`;

/** Terminal but intended — archiving a case, cancelling a run. Not deletion. */
export const WARNING_ACTION = `${BASE} border border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-300 hover:bg-amber-100 ${DISABLED}`;

/** Removes data. Tinted for the trigger, filled for the confirm inside the dialog. */
export const DESTRUCTIVE_ACTION = `${BASE} border border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100 ${DISABLED}`;
export const DESTRUCTIVE_CONFIRM = `${BASE} bg-rose-600 text-white hover:bg-rose-700 ${DISABLED}`;

/**
 * Shows or hides something already on the page. It commits nothing, so it must not compete with the
 * action it reveals — a "Vis filtre" that looks like "Lagre" makes the page's real work harder to
 * find. Neutral, never violet, with a focus ring strong enough to keep it keyboard-usable.
 */
const DISCLOSURE_COLOURS = 'border border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500';

/** Full-size disclosure, for a control that sits on its own row. */
export const DISCLOSURE_ACTION = `${BASE} ${DISCLOSURE_COLOURS} ${DISABLED}`;

/**
 * Compact disclosure, for a toggle that shares a line with a heading or a chip row. Same colours and
 * focus treatment; only the geometry is smaller, because the full height does not fit there.
 */
export const DISCLOSURE_INLINE = `inline-flex items-center justify-center gap-2 rounded-full px-3 py-1.5 text-base font-semibold transition ${DISCLOSURE_COLOURS} ${DISABLED}`;
