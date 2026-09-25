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
