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

/**
 * The primary colours on their own, for a button whose geometry is driven by its own state machine
 * — "Lagre" on a Doffin hit also renders as saved and as in-history, each with its own palette, so
 * it composes the colours rather than taking the whole style. Same role, same tokens, one source.
 *
 * The tone deliberately matches the active item in the top navigation (bg-violet-50 / text-violet-700
 * / violet-200 edge): a saturated violet-600 fill read as too dominant next to it. `border` is
 * included so a site that already reserves a border edge picks up the colour without extra classes.
 */
export const PRIMARY_COLOURS = 'border border-violet-200 bg-violet-50 text-violet-700 hover:border-violet-300 hover:bg-violet-100 hover:text-violet-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600';

/** Commits the work: submits a form, confirms a dialog. At most one per surface. */
export const PRIMARY_ACTION = `${BASE} ${PRIMARY_COLOURS} ${DISABLED}`;

/**
 * Each remaining role exposed the same way as PRIMARY_COLOURS, for the rows and toolbars that set
 * their own compact geometry (min-h-10, px-3) and would be resized by taking a whole *_ACTION.
 * The full styles below are built from these, so a role only ever has one definition.
 */
export const SECONDARY_COLOURS = 'border border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950';
export const WARNING_COLOURS = 'border border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-300 hover:bg-amber-100';
export const DESTRUCTIVE_COLOURS = 'border border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100';

/** Goes somewhere else, or backs out. Never the action a surface exists to perform. */
export const SECONDARY_ACTION = `${BASE} ${SECONDARY_COLOURS} ${DISABLED}`;

/** Terminal but intended — archiving a case, deactivating a profile. Not deletion. */
export const WARNING_ACTION = `${BASE} ${WARNING_COLOURS} ${DISABLED}`;

/** Removes data. Tinted for the trigger, filled for the confirm inside the dialog. */
export const DESTRUCTIVE_ACTION = `${BASE} ${DESTRUCTIVE_COLOURS} ${DISABLED}`;
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
