/**
 * Spoken status messages for screen-reader users (WCAG 4.1.3 Status Messages, AA).
 *
 * A live region only speaks when its *content changes* while the region is already in the
 * accessibility tree. CustomerAppLayout re-mounts on every Inertia visit to another page, so a region
 * rendered there would be brand new exactly when a redirect's flash message arrives — and a polite
 * region inserted together with its text is often not read at all. The regions here are appended to
 * <body> once, when the app boots, and survive every visit.
 *
 *   polite     Ordinary outcomes — «Lagret», «Svar generert». Read when the user pauses; never
 *              interrupts. Use announce(message).
 *   assertive  Failures the user must act on. Interrupts, so only for errors that are not already
 *              rendered as a role="alert" element. Use announce(message, { politeness: 'assertive' }).
 *   toast      A visible container: the layouts portal their success toast into it (FlashMessages), so
 *              the toast the user sees is also the text the screen reader hears — once, with no hidden
 *              copy. It sits in a host (#procynia-toast) beside a silent slot, toastActions, that holds
 *              the toast's close button: inside the live region the button's name would be read out
 *              with every message. resources/css/app.css stacks the two into one toast.
 *
 * The regions carry aria-live but no role. role="status"/"alert" would put three permanent landmarks
 * into the page's role tree, where pages (and their tests) look for their own status messages.
 *
 * Restraint is built in: the same message on the same channel within DUPLICATE_WINDOW_MS is dropped,
 * so a polling refresh or a double render cannot make a screen reader repeat itself. Announce state
 * transitions («Uttrekk ferdig»), never progress ticks. And never announce text that is also rendered
 * visibly at the same moment — it would exist twice; render that text inside a live region instead.
 */

export const LIVE_REGION_IDS = {
    polite: 'procynia-live-polite',
    assertive: 'procynia-live-assertive',
    toast: 'procynia-live-toast',
    // Not live: the toast's close button, next to the region rather than inside it.
    toastActions: 'procynia-toast-actions',
};

export const TOAST_HOST_ID = 'procynia-toast';

export const DUPLICATE_WINDOW_MS = 1500;

// Clearing first and writing on the next tick makes an identical message (a second «Lagret» a minute
// later) a change the screen reader notices.
const WRITE_DELAY_MS = 50;

export const CLEAR_AFTER_MS = 7000;

const REGIONS = [
    { id: LIVE_REGION_IDS.polite, live: 'polite', hidden: true },
    { id: LIVE_REGION_IDS.assertive, live: 'assertive', hidden: true },
    { id: LIVE_REGION_IDS.toast, live: 'polite', hidden: false },
];

const lastAnnounced = { polite: null, assertive: null };

// The write and clear still waiting on each channel. A newer message cancels both, so in a rapid
// sequence («Lagrer…», «Lagret») only the last one is written — an older write landing after the
// newer clear would otherwise flash the stale text into the region, and a screen reader may speak it.
const pending = { polite: { write: null, clear: null }, assertive: { write: null, clear: null } };

/**
 * Whether a message should be spoken, given what this channel said last. Pure, so it is unit-tested.
 *
 * @param {{ message: string, at: number }|null} previous
 * @param {string} message
 * @param {number} now
 */
export function shouldAnnounce(previous, message, now) {
    if (typeof message !== 'string' || message.trim() === '') {
        return false;
    }

    return ! (previous && previous.message === message && now - previous.at < DUPLICATE_WINDOW_MS);
}

/** Creates the regions if they are missing. Idempotent; called from app.js before the first render. */
export function ensureLiveRegions(doc = document) {
    for (const { id, live, hidden } of REGIONS) {
        if (doc.getElementById(id)) {
            continue;
        }

        const region = doc.createElement('div');
        region.id = id;
        region.setAttribute('aria-live', live);
        region.setAttribute('aria-atomic', 'true');

        if (hidden) {
            region.className = 'sr-only';
        }

        if (id === LIVE_REGION_IDS.toast) {
            toastHost(doc).appendChild(region);
        } else {
            doc.body.appendChild(region);
        }
    }

    if (! doc.getElementById(LIVE_REGION_IDS.toastActions)) {
        const actions = doc.createElement('div');
        actions.id = LIVE_REGION_IDS.toastActions;
        toastHost(doc).appendChild(actions);
    }
}

function toastHost(doc) {
    let host = doc.getElementById(TOAST_HOST_ID);

    if (! host) {
        host = doc.createElement('div');
        host.id = TOAST_HOST_ID;
        doc.body.appendChild(host);
    }

    return host;
}

/** The element to portal visible content into (the toast, or its close button), or null outside the browser. */
export function liveRegion(name) {
    if (typeof document === 'undefined') {
        return null;
    }

    ensureLiveRegions(document);

    return document.getElementById(LIVE_REGION_IDS[name]) ?? null;
}

/**
 * Speak a message that is not otherwise on screen.
 *
 * @param {string} message
 * @param {{ politeness?: 'polite'|'assertive' }} [options]
 * @returns {boolean} Whether the message was queued (false when empty or a duplicate).
 */
export function announce(message, { politeness = 'polite' } = {}) {
    const channel = politeness === 'assertive' ? 'assertive' : 'polite';
    const now = Date.now();

    if (typeof document === 'undefined' || ! shouldAnnounce(lastAnnounced[channel], message, now)) {
        return false;
    }

    const region = liveRegion(channel);
    lastAnnounced[channel] = { message, at: now };

    window.clearTimeout(pending[channel].write);
    window.clearTimeout(pending[channel].clear);

    region.textContent = '';
    pending[channel].write = window.setTimeout(() => {
        region.textContent = message;
    }, WRITE_DELAY_MS);

    // Spoken once is enough; left behind, the text would turn up again at the end of the page in a
    // screen reader's browse mode, detached from what it was about.
    pending[channel].clear = window.setTimeout(() => {
        region.textContent = '';
    }, CLEAR_AFTER_MS);

    return true;
}
