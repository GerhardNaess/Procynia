import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const layout = readFileSync(join(here, 'CustomerAppLayout.jsx'), 'utf8');
const bell = readFileSync(join(here, '..', 'Components', 'App', 'NotificationBell.jsx'), 'utf8');

/**
 * What the bell is for, and what it is not for.
 *
 * It says something new has happened, so it has to notice when something does — a notification that
 * arrives during a long session used to be invisible until the person happened to navigate. And it
 * has to be clearable, because unread is about messages, not about work: the task list is what says
 * the work is still outstanding, and it is unaffected by anything here.
 *
 * Source-level guards; the behaviour is covered by the PHP feature tests.
 */
describe('the bell keeps itself current', () => {
    test('it re-reads on a timer and when the tab comes back', () => {
        assert.match(layout, /const timer = window\.setInterval\(refresh, NOTIFICATION_POLL_MS\);/);
        assert.match(layout, /window\.addEventListener\('focus', refresh\);/);
        assert.match(layout, /document\.addEventListener\('visibilitychange', refresh\);/);
    });

    test('a hidden tab costs nothing', () => {
        assert.match(layout, /if \(document\.visibilityState === 'hidden'\) \{\s*\n\s*return;/);
    });

    test('the poll is moderate, not a heartbeat', () => {
        const interval = Number(/const NOTIFICATION_POLL_MS = (\d+);/.exec(layout)?.[1]);
        assert.ok(interval >= 30000, `polling every ${interval}ms is too eager`);
    });

    test('polling reads and never marks anything read', () => {
        // A GET, and the read endpoints stay on their own explicit PATCH calls.
        assert.match(layout, /await window\.axios\.get\(refreshUrl\)/);
        assert.match(layout, /window\.axios\.patch\(notification\.mark_read_url\)/);
        assert.match(layout, /window\.axios\.patch\(notificationState\.mark_all_read_url\)/);
    });

    test('a failed poll is not an interruption', () => {
        assert.match(layout, /\} catch \{\s*\n\s*\/\/ A failed poll/);
    });

    test('the listeners are removed with the layout', () => {
        assert.match(layout, /window\.clearInterval\(timer\);/);
        assert.match(layout, /window\.removeEventListener\('focus', refresh\);/);
        assert.match(layout, /document\.removeEventListener\('visibilitychange', refresh\);/);
    });
});

describe('unread can be cleared without clearing the work', () => {
    test('the panel offers to mark everything read', () => {
        assert.match(bell, /Marker alle som lest/);
        assert.match(bell, /onClick=\{onMarkAllRead\}/);
        assert.match(bell, /disabled=\{!hasUnreadItems\}/, 'nothing to clear, nothing to press');
    });

    test('the badge counts unread, not outstanding work', () => {
        assert.match(bell, /const unreadCount = Number\(notifications\?\.unread_count \?\? 0\);/);
        assert.match(bell, /\{unreadCount > 0 \? \(/);
    });
});

/**
 * Clearing the bell without clearing the work.
 *
 * The bell holds messages; Infosenter holds work. Every control here removes messages and nothing
 * else — no request touches an assignment, a claim or a case, which is what keeps "I tidied my
 * inbox" from ever meaning "I finished my review".
 */
describe('the bell can be tidied', () => {
    test('each message carries its own dismiss', () => {
        assert.match(bell, /data-testid="notification-delete"/);
        assert.match(bell, /onClick=\{\(\) => onDeleteNotification\(notification\)\}/);
        assert.match(bell, /aria-label=\{`Slett varsel: \$\{notification\.title\}`\}/);
    });

    test('dismiss is its own control, not nested inside the opening action', () => {
        // A <button> inside a <button> is invalid markup and the browser would not deliver the
        // click, so the card is a container with two siblings.
        const cardStart = bell.indexOf('{items.map((notification) => (');
        const card = bell.slice(cardStart, bell.indexOf('))}', cardStart));
        assert.match(card, /<div\n\s+key=\{notification\.id\}/, 'the card itself is a container');
        // The opening button closes before the dismiss button opens.
        assert.ok(
            card.indexOf('</button>') < card.indexOf('data-testid="notification-delete"'),
            'dismiss is a sibling of the opening action, not nested inside it',
        );
    });

    test('both bulk actions are offered, and they are different actions', () => {
        assert.match(bell, /Marker alle som lest/);
        assert.match(bell, /data-testid="notification-delete-unread"/);
        assert.match(bell, /Slett alle uleste/);
        assert.match(bell, /onClick=\{onDeleteAllUnread\}/);
    });

    test('bulk delete is confirmed first, with the house dialog', () => {
        assert.match(layout, /import ActionDialog from '\.\.\/Components\/App\/ActionDialog';/);
        assert.match(layout, /onDeleteAllUnread=\{\(\) => setIsDeleteUnreadOpen\(true\)\}/);
        assert.match(layout, /Slett alle uleste varsler\?/);
        assert.match(layout, /data-testid="notification-delete-unread-confirm"/);
        // The sentence people need: their work is not part of this.
        assert.match(layout, /oppgaver i Infosenter påvirkes ikke/);
    });

    test('a single dismiss is not put behind a dialog', () => {
        assert.match(layout, /const deleteNotification = async \(notification\) => \{/);
        // Its own body goes straight to the request; only the bulk action opens a dialog.
        const start = layout.indexOf('const deleteNotification = async');
        const body = layout.slice(start, layout.indexOf('const deleteAllUnreadNotifications', start));
        assert.ok(!body.includes('setIsDeleteUnreadOpen'), 'no confirmation step for one message');
        assert.match(body, /window\.axios\.delete\(notification\.delete_url\)/);
    });

    test('the panel is re-read from the server after either delete', () => {
        // The card disappearing and the badge dropping are one fact, not two guesses.
        assert.match(layout, /await window\.axios\.delete\(notification\.delete_url\)/);
        assert.match(layout, /await window\.axios\.delete\(notificationState\.delete_unread_url\)/);
        assert.ok((layout.match(/setNotificationState\(response\.data\.notifications\)/g) ?? []).length >= 2);
    });

    test('a delete that finds nothing is not raised at the person', () => {
        assert.match(layout, /\} catch \(error\) \{\s*\n\s*\/\/ A message that is already gone/);
    });
});

/**
 * event_type is an internal identifier. It was reaching the screen as "Wiki.page Published"
 * whenever a type had no entry in the label map — which is every type except one.
 */
describe('internal event names stay internal', () => {
    test('an unnamed event falls back to its area, never to its own identifier', () => {
        assert.match(bell, /const EVENT_DOMAIN_LABELS = \{/);
        assert.match(bell, /return EVENT_DOMAIN_LABELS\[eventType\.split\('\.'\)\[0\]\] \?\? 'Varsel';/);
    });

    test('the old prettifier is gone', () => {
        assert.ok(!bell.includes(".split('_')"), 'splitting the raw type is what produced the leak');
        assert.ok(!bell.includes('part.charAt(0).toUpperCase()'));
    });

    test('the domains that exist are named', () => {
        for (const domain of ['wiki', 'bid', 'watch_profile']) {
            assert.match(bell, new RegExp(`${domain}: '`), `${domain} has a human name`);
        }
    });
});
