import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import {
    PRIMARY_ACTION,
    SECONDARY_ACTION,
    WARNING_ACTION,
    DESTRUCTIVE_ACTION,
    DESTRUCTIVE_CONFIRM,
    DISCLOSURE_ACTION,
    DISCLOSURE_INLINE,
} from './actionStyles.js';

const pages = join(dirname(fileURLToPath(import.meta.url)), '..', 'Pages', 'App');
const read = (relative) => readFileSync(join(pages, relative), 'utf8');

/**
 * The className of the actionable element that renders `label`, for every place it is rendered.
 * Anchoring on the nearest className above the label keeps the assertion on the button itself
 * rather than on any nested icon or wrapper.
 */
function classNamesRendering(source, label) {
    const lines = source.split('\n');
    const found = [];

    lines.forEach((line, index) => {
        if (!line.includes(label)) {
            return;
        }

        for (let i = index; i >= 0 && i > index - 14; i -= 1) {
            // Either a literal className="…" or an expression className={…}, since the re-roled
            // buttons now reference the shared constants rather than spelling the classes out.
            const match = lines[i].match(/className="([^"]+)"|className=\{([^\n]+)/);

            if (match) {
                found.push(match[1] ?? match[2]);
                return;
            }
        }
    });

    assert.notEqual(found.length, 0, `"${label}" is no longer rendered`);

    return found;
}

/**
 * Colour is the only signal separating "this commits my work" from "this takes me elsewhere". These
 * guard the roles that were actually corrected — the project has no JSX renderer, so the assertions
 * read the source, the same idiom the rest of the suite uses.
 */
describe('the roles are visually distinct from each other', () => {
    test('each role owns a different fill', () => {
        const fills = [PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION, DESTRUCTIVE_ACTION, DESTRUCTIVE_CONFIRM]
            .map((style) => style.match(/bg-[a-z]+-\d+|bg-white/)[0]);

        assert.equal(new Set(fills).size, fills.length, 'two roles share a fill and stop meaning different things');
    });

    test('primary is still separable from secondary at a glance', () => {
        // Primary is tinted rather than filled, so the only thing left telling it apart from an
        // ordinary support action is its ground and its text — both must actually differ.
        const ground = (style) => style.match(/bg-[a-z]+-\d+|bg-white/)[0];
        const ink = (style) => style.match(/(?<!hover:)text-[a-z]+-\d+/)[0];

        assert.notEqual(ground(PRIMARY_ACTION), ground(SECONDARY_ACTION));
        assert.notEqual(ink(PRIMARY_ACTION), ink(SECONDARY_ACTION));
    });

    test('the destructive confirmation stays solid, because it is the last step', () => {
        assert.match(DESTRUCTIVE_CONFIRM, /bg-rose-600/);
        assert.ok(!DESTRUCTIVE_CONFIRM.includes('border '));
    });

    test('every role reacts to hover and keeps a visible focus ring', () => {
        for (const style of [PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION, DESTRUCTIVE_ACTION, DISCLOSURE_ACTION]) {
            assert.match(style, /hover:/);
        }
        for (const style of [PRIMARY_ACTION, DISCLOSURE_ACTION, DISCLOSURE_INLINE]) {
            assert.match(style, /focus-visible:outline-[a-z]+-\d+/);
        }
    });

    test('every role shares one geometry, so only colour varies', () => {
        for (const style of [PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION, DESTRUCTIVE_ACTION, DESTRUCTIVE_CONFIRM]) {
            assert.match(style, /min-h-11/);
            assert.match(style, /rounded-xl px-4 py-2\.5 text-base font-semibold/);
        }
    });
});

describe('navigation no longer wears the primary colour', () => {
    // "Åpne i Doffin" used to outrank the Go/No-Go decision beside it on the same page.
    test('opening a notice in Doffin is secondary on the work list', () => {
        const source = read('Notices/Index.jsx');

        for (const label of ['noticesText.alertsOpenDoffin', 'noticesText.openInDoffinLabel']) {
            for (const className of classNamesRendering(source, label)) {
                assert.ok(!className.includes('bg-violet-50'), `${label} must not use the violet tint`);
                assert.ok(/bg-white|SECONDARY_ACTION/.test(className), `${label} must be secondary`);
            }
        }
    });
});

describe('saving is primary', () => {
    test('every save on the case page commits in filled violet', () => {
        const source = read('Notices/SavedShow.jsx');

        for (const form of ['phaseCommentForm.processing', 'infoItemForm.processing', 'bidManagerForm.processing', 'opportunityOwnerForm.processing']) {
            for (const className of classNamesRendering(source, `{${form} ?`)) {
                assert.match(className, /PRIMARY_(COLOURS|ACTION)/, `${form} should submit as a primary action`);
            }
        }
    });
});

describe('rose means data disappears', () => {
    test('retrying a failed page is not styled as destructive', () => {
        const source = read('Wiki/Index.jsx');
        const classNames = classNamesRendering(source, 'runs_pages_retry');

        assert.equal(classNames.length, 2, 'both retry buttons are still present');

        for (const className of classNames) {
            assert.ok(!className.includes('rose'), 'retry recovers work, it does not delete any');
        }
    });
});

describe('a disclosure commits nothing, so it must not look like it does', () => {
    test('it is neutral — never violet, amber or rose', () => {
        for (const style of [DISCLOSURE_ACTION, DISCLOSURE_INLINE]) {
            for (const colour of ['violet', 'amber', 'rose']) {
                assert.ok(!style.includes(colour), `a disclosure must not borrow ${colour}`);
            }
        }
    });

    test('it stays keyboard-visible', () => {
        for (const style of [DISCLOSURE_ACTION, DISCLOSURE_INLINE]) {
            assert.match(style, /hover:border-slate-300/);
            assert.match(style, /focus-visible:outline-slate-500/);
        }
    });

    test('the two variants differ only in geometry', () => {
        const colours = (style) => style.match(/(border|bg|text|hover:[a-z-]+|focus-visible:[a-z-]+)-[a-z]+-?\d*/g).join(' ');

        assert.equal(colours(DISCLOSURE_ACTION), colours(DISCLOSURE_INLINE));
    });

    test('the toggles that only show and hide use it', () => {
        for (const [file, label] of [
            ['Notices/Index.jsx', 'noticesText.filterPanelCollapse'],
            ['Notices/CpvSelector.jsx', 'texts.cpv_hide_codes'],
            ['Notices/SavedShow.jsx', 'tsn.new_action'],
            ['AI/Show.jsx', 'tai.hide_requirement_form'],
        ]) {
            for (const className of classNamesRendering(read(file), label)) {
                assert.match(className, /DISCLOSURE_/, `${file} (${label}) should use the disclosure style`);
            }
        }
    });

    test('aria-expanded survives on the toggles that had it', () => {
        for (const [file, state] of [
            ['Notices/Index.jsx', 'isFilterPanelOpen'],
            ['Notices/CpvSelector.jsx', 'showChips'],
            ['AI/Show.jsx', 'showManualRequirementForm'],
        ]) {
            assert.ok(read(file).includes(`aria-expanded={${state}}`), `${file} lost aria-expanded`);
        }
    });
});

describe('the Go/No-Go decision reads as terminal', () => {
    const savedShow = read('Notices/SavedShow.jsx');

    test('setting No-Go warns, in the dialog as well as on the trigger', () => {
        // The trigger is coloured by actionButtonClassName, which already special-cases no_go.
        assert.match(savedShow, /status === 'no_go'\)? \{\s*\n\s*return 'border-amber-200/);

        for (const className of classNamesRendering(savedShow, '{isStatusActionProcessing ? tsn.updating : tsn.setNoGo}')) {
            assert.match(className, /WARNING_ACTION/, 'the final confirmation must warn too, not read as an ordinary submit');
        }
    });

    test('reopening a case is the primary way back into the decision flow', () => {
        for (const className of classNamesRendering(savedShow, 'tsn.reopen_no_go_action')) {
            assert.match(className, /PRIMARY_ACTION/);
        }
    });

    test('cancelling out of either dialog stays secondary', () => {
        for (const className of classNamesRendering(savedShow, '{common.cancel}')) {
            assert.match(className, /bg-white/);
            assert.ok(!className.includes('violet-600'), 'cancel must never compete with the submit');
        }
    });
});

describe('live search puts the weight on saving, not on leaving', () => {
    const index = read('Notices/Index.jsx');
    const card = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '..', 'Components', 'App', 'DiscoveryNoticeCard.jsx'), 'utf8');

    /** The class expression of the button whose handler is `handler`, up to its closing tag. */
    function buttonWithHandler(source, handler) {
        const at = source.indexOf(handler);
        assert.notEqual(at, -1, `no button calls ${handler}`);

        return source.slice(at, source.indexOf('</button>', at));
    }

    test('saving a Doffin hit is the primary action on both cards', () => {
        // Both the inline list and the discovery/watch-alert card render their own save button;
        // they drifted apart before, so both are asserted here.
        for (const [name, source, handler] of [
            ['live list', index, 'onClick={() => saveNotice(notice)}'],
            ['discovery card', card, 'onClick={saveNoticeToWorklist}'],
        ]) {
            const button = buttonWithHandler(source, handler);

            assert.match(button, /PRIMARY_COLOURS/, `${name}: save must read as the primary action`);
            assert.ok(!button.includes('rose') && !button.includes('amber'), `${name}: save is neither destructive nor terminal`);
        }
    });

    test('saving keeps its already-saved and in-history states distinct from primary', () => {
        // Primary is for the state where the action is still available; the other two are read-only.
        const button = buttonWithHandler(index, 'onClick={() => saveNotice(notice)}');

        assert.match(button, /notice\.is_saved/);
        assert.match(button, /notice\.is_in_history/);
        assert.match(button, /cursor-not-allowed/);
    });

    test('opening a notice in Doffin stays a support action everywhere it appears', () => {
        // Only the button-shaped renderings: the same label also appears as a plain hyperlink inside
        // a detail field, where button semantics — and button geometry — do not apply.
        for (const label of ['noticesText.alertsOpenDoffin', 'noticesText.openInDoffinLabel', '{noticeExternalLinkLabel(notice, noticesText)}']) {
            const asButton = classNamesRendering(index, label).filter((className) => /inline-flex|_ACTION/.test(className));

            assert.notEqual(asButton.length, 0, `${label} is no longer rendered as an action`);

            for (const className of asButton) {
                assert.match(className, /SECONDARY_ACTION/, `${label} must be secondary`);
            }
        }
    });

    test('showing and hiding the filters commits nothing', () => {
        for (const className of classNamesRendering(index, 'noticesText.filterPanelCollapse')) {
            assert.match(className, /DISCLOSURE_/);
            for (const role of ['PRIMARY', 'WARNING', 'DESTRUCTIVE']) {
                assert.ok(!className.includes(role), `the filter toggle must not borrow ${role}`);
            }
        }

        assert.ok(index.includes('aria-expanded={isFilterPanelOpen}'), 'the filter toggle lost aria-expanded');
    });

    test('the search action itself is the primary one inside the filter panel', () => {
        for (const className of classNamesRendering(index, '{common.search}')) {
            assert.ok(/bg-violet-600|PRIMARY/.test(className), 'Søk is the panel primary');
        }
    });
});

describe('primary borrows the tone of the active navigation item', () => {
    const layout = readFileSync(
        join(dirname(fileURLToPath(import.meta.url)), '..', 'Layouts', 'CustomerAppLayout.jsx'),
        'utf8',
    );

    /** The classes the top navigation gives its active item. */
    function activeNavigationClasses() {
        const match = layout.match(/isActive\s*\n?\s*\?\s*'([^']+)'/);
        assert.notEqual(match, null, 'the active navigation state is no longer recognisable');

        return match[1];
    }

    /** The pages whose primary actions this suite watches, as [name, source] pairs. */
    function primarySources() {
        return ['Notices/Index.jsx', 'Notices/SavedShow.jsx', 'WatchProfiles/Index.jsx', 'Users/Create.jsx', 'Wiki/Index.jsx', 'Wiki/Ask.jsx']
            .map((file) => [file, read(file)]);
    }

    test('it uses the same ground and the same ink as the active menu item', () => {
        const active = activeNavigationClasses();

        for (const token of ['bg-violet-50', 'text-violet-700']) {
            assert.ok(active.includes(token), `the navigation no longer uses ${token} — re-anchor this`);
            assert.ok(PRIMARY_ACTION.includes(token), `primary should share the navigation's ${token}`);
        }
    });

    test('its edge sits in the same violet family as the navigation ring', () => {
        assert.match(activeNavigationClasses(), /ring-violet-200/);
        assert.match(PRIMARY_ACTION, /border-violet-200/);
    });

    test('hover is a step up from rest, so the button still answers the pointer', () => {
        assert.match(PRIMARY_ACTION, /bg-violet-50\b/);
        assert.match(PRIMARY_ACTION, /hover:bg-violet-100\b/);
        assert.match(PRIMARY_ACTION, /hover:text-violet-800\b/);
    });

    test('asking the Wiki a question is an ordinary primary submit', () => {
        const ask = read('Wiki/Ask.jsx');
        const button = ask.slice(ask.indexOf('type="submit"'), ask.indexOf('</button>', ask.indexOf('type="submit"')));

        assert.match(button, /PRIMARY_ACTION/, 'Still spørsmål is the primary action on its page');
        assert.match(button, /<svg/, 'the search icon is still rendered');
        assert.match(button, /form\.processing/, 'the loading and disabled state is still driven by the form');
    });

    test('no primary overrides the shared colours for its own disabled state', () => {
        // A local disabled:bg-… repaints the button the moment it is disabled, which is exactly
        // when the shared treatment matters most.
        for (const [file, source] of primarySources()) {
            for (const [, classes] of source.matchAll(/`([^`\n]*\$\{PRIMARY_(?:COLOURS|ACTION)\}[^`\n]*)`/g)) {
                assert.ok(
                    !/disabled:(?:bg|text|border)-/.test(classes),
                    `${file} repaints a primary action while disabled`,
                );
            }
        }
    });

    test('no primary action spells the colours out for itself any more', () => {
        // A local bg-violet-600 would silently opt that button out of this change.
        for (const [file, source] of primarySources()) {
            for (const [, attrs] of source.matchAll(/<(?:button|Link)\b([^>]*)>/g)) {
                assert.ok(!attrs.includes('bg-violet-600'), `${file} still hard-codes the old primary fill`);
            }
        }
    });
});

describe('the watch profile list separates deactivating from deleting', () => {
    const list = read('WatchProfiles/Index.jsx');

    /** Every class expression rendered by the element whose handler or href is `anchor`. */
    function actionsFor(anchor) {
        const found = [...list.matchAll(new RegExp(anchor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'))]
            .map(({ index }) => list.slice(index, list.indexOf('>', list.indexOf('className', index))));

        assert.notEqual(found.length, 0, `no action matches ${anchor}`);

        return found;
    }

    test('the list renders both a mobile and a table layout, so each role appears twice', () => {
        // The two layouts drifted apart before; asserting the count keeps a fix from landing in one.
        assert.equal(actionsFor('onClick={() => toggleActive(watchProfile)}').length, 2);
        assert.equal(actionsFor('onClick={() => deleteWatchProfile(watchProfile)}').length, 2);
    });

    test('deactivating warns rather than reading as deletion', () => {
        // It is a reversible toggle: the same button turns the profile back on.
        for (const action of actionsFor('onClick={() => toggleActive(watchProfile)}')) {
            assert.match(action, /WARNING_COLOURS/, 'deactivate should warn');
            assert.ok(!action.includes('DESTRUCTIVE'), 'deactivate deletes nothing');
            assert.ok(!action.includes('rose'), 'deactivate should not keep a rose tint');
        }
    });

    test('activating an inactive profile is the primary way to make it work again', () => {
        for (const action of actionsFor('onClick={() => toggleActive(watchProfile)}')) {
            assert.match(action, /PRIMARY_COLOURS/);
            assert.ok(!action.includes('emerald'), 'emerald was never one of the roles');
        }
    });

    test('deleting is the only destructive action here', () => {
        for (const action of actionsFor('onClick={() => deleteWatchProfile(watchProfile)}')) {
            assert.match(action, /DESTRUCTIVE_COLOURS/);
        }
    });

    test('editing and clearing filters stay support actions', () => {
        for (const action of actionsFor('href={watchProfile.edit_url}')) {
            assert.match(action, /SECONDARY_COLOURS/);
        }

        const reset = list.slice(list.indexOf('Nullstill filtre') - 400, list.indexOf('Nullstill filtre'));
        assert.match(reset, /SECONDARY_COLOURS/);
    });

    test('adding a watch profile is the primary action on the page', () => {
        const add = list.slice(list.indexOf('Legg til Watch Profile') - 400, list.indexOf('Legg til Watch Profile'));
        assert.match(add, /PRIMARY_COLOURS|PRIMARY_ACTION/);
    });

    test('the compact row geometry is untouched by the re-roling', () => {
        // These rows are min-h-10/px-3; taking a whole *_ACTION would have resized them.
        for (const anchor of ['onClick={() => toggleActive(watchProfile)}', 'onClick={() => deleteWatchProfile(watchProfile)}', 'href={watchProfile.edit_url}']) {
            for (const action of actionsFor(anchor)) {
                assert.match(action, /min-h-10 .*px-3 py-2/);
            }
        }
    });
});

describe('the departments tab treats deactivating as reversible', () => {
    const env = read('CustomerEnvironment/Index.jsx');

    /**
     * The departments tab and the users tab render the same actions with the same markup, so an
     * assertion has to be pinned to the department block or it silently reads the users one.
     */
    function departmentBlock() {
        const start = env.indexOf('onClick={() => openEditDepartment(department)}');
        const end = env.indexOf('</button>', env.indexOf('toggleDepartmentActive(department)'));

        assert.ok(start !== -1 && end > start, 'the department row is no longer recognisable');

        return env.slice(start, end);
    }

    test('deactivating a department warns instead of reading as deletion', () => {
        const block = departmentBlock();

        assert.match(block, /WARNING_COLOURS/, 'deactivate should warn');
        assert.ok(!/border-rose-200/.test(block), 'deactivate must not keep the delete colour');
    });

    test('activating a department is the primary way to bring it back', () => {
        const block = departmentBlock();

        assert.match(block, /PRIMARY_COLOURS/);
        assert.ok(!/emerald/.test(block), 'emerald was never one of the roles');
    });

    test('editing a department stays a support action', () => {
        assert.match(departmentBlock(), /SECONDARY_COLOURS/);
    });

    test('creating a department is the primary action of the tab', () => {
        // Anchored on the handler: the label also appears in the page help prose and in the dialog.
        const at = env.indexOf('onClick={openCreateDepartment}');
        assert.notEqual(at, -1, 'the create action is gone');

        const create = env.slice(env.lastIndexOf('<button', at), env.indexOf('>', env.indexOf('className', at)));
        assert.match(create, /PRIMARY_COLOURS|PRIMARY_ACTION/);
    });

    test('the department dialog submits in primary and cancels in secondary', () => {
        const submit = env.slice(env.indexOf("departmentForm.processing ? 'Lagrer...'") - 400, env.indexOf("departmentForm.processing ? 'Lagrer...'"));

        assert.match(submit, /PRIMARY_COLOURS|PRIMARY_ACTION/);

        const cancel = env.slice(env.lastIndexOf('className', env.indexOf('>\n', env.indexOf('Avbryt') - 200)), env.indexOf('Avbryt'));
        assert.ok(!/violet|rose|amber/.test(cancel), 'cancel must not compete with the submit');
    });

    test('departments have no delete action, so none is asserted', () => {
        // Deactivation is the documented way to retire a department without losing history.
        assert.ok(!env.includes('deleteDepartment'), 'a delete action appeared — it needs a destructive role');
    });

    test('the compact row geometry survives the re-roling', () => {
        assert.match(departmentBlock(), /min-h-10 .*px-3 py-2/);
    });
});

describe('the users tab treats deactivating as reversible', () => {
    const env = read('CustomerEnvironment/Index.jsx');

    /** The user row's actions — pinned to the user block, since departments render the same markup. */
    function userBlock() {
        const start = env.indexOf('href={userEditHref(user)}');
        const end = env.indexOf('</button>', env.indexOf('toggleUserActive(user)'));

        assert.ok(start !== -1 && end > start, 'the user row is no longer recognisable');

        return env.slice(start, end);
    }

    test('deactivating a user warns instead of reading as deletion', () => {
        const block = userBlock();

        assert.match(block, /WARNING_COLOURS/, 'deactivate should warn');
        assert.ok(!/border-rose-200/.test(block), 'deactivate must not keep the delete colour');
    });

    test('activating a user is the primary way to restore access', () => {
        const block = userBlock();

        assert.match(block, /PRIMARY_COLOURS/);
        assert.ok(!/emerald/.test(block), 'emerald was never one of the roles');
    });

    test('editing a user stays a support action', () => {
        assert.match(userBlock(), /SECONDARY_COLOURS/);
    });

    test('the toggle keeps a third, roleless state for accounts that cannot be toggled', () => {
        // "Egen konto" is not an action offered — it must not borrow any role's colour.
        const block = userBlock();

        assert.match(block, /bg-slate-100/, 'the not-permitted state is gone');
        assert.match(block, /user\.can_toggle_active/);
    });

    test('creating a user is the primary action of the tab', () => {
        const at = env.indexOf('href={userCreateHref}');
        assert.notEqual(at, -1, 'the create action is gone');

        assert.match(env.slice(at, env.indexOf('>', env.indexOf('className', at))), /PRIMARY_COLOURS|PRIMARY_ACTION/);
    });

    test('users have no delete action, so none is asserted', () => {
        assert.ok(!env.includes('deleteUser'), 'a delete action appeared — it needs a destructive role');
    });

    test('the compact row geometry survives the re-roling', () => {
        assert.match(userBlock(), /min-h-10 .*px-3 py-2/);
    });

    test('departments and users no longer disagree about what deactivating means', () => {
        // Both tabs render the same actions; a fix landing in only one is the failure mode here.
        // Counted where the style is applied, not on the import line.
        const applied = env.match(/\$\{WARNING_COLOURS\}/g) ?? [];

        assert.equal(applied.length, 2, 'both tabs should warn on deactivate');
        assert.ok(!/border-emerald-200/.test(env), 'no roleless emerald action is left on the page');
    });
});

describe('cancelling a subscription is terminal, not destructive', () => {
    const billing = read('Billing/Index.jsx');

    /** The class expression of the action whose handler contains `handler`. */
    function actionFor(handler) {
        const at = billing.indexOf(handler);
        assert.notEqual(at, -1, `no action matches ${handler}`);

        return billing.slice(at, billing.indexOf('>', billing.indexOf('className', at)));
    }

    test('changing the subscription is the primary action of the card', () => {
        assert.match(actionFor('onClick={openPlanChangeModal}'), /PRIMARY_COLOURS/);
    });

    test('cancelling warns rather than threatening deletion', () => {
        // The subscription runs to the end of the period and no data is removed.
        const cancel = actionFor('onClick={() => setConfirmCancel(true)}');

        assert.match(cancel, /WARNING_COLOURS/);
        assert.ok(!/DESTRUCTIVE|rose|red/.test(cancel), 'cancelling deletes nothing');
    });

    test('the confirmation carries the same warning, and cancels in secondary', () => {
        const dialog = billing.slice(billing.indexOf('function ConfirmDialog('), billing.indexOf('export default function BillingIndex'));

        assert.match(dialog, /warning \? WARNING_COLOURS : PRIMARY_COLOURS/, 'the confirm button follows the role');
        assert.match(dialog, /SECONDARY_COLOURS/, 'backing out of the dialog stays secondary');
        assert.ok(!/bg-red-600|bg-blue-600/.test(dialog), 'the dialog no longer uses off-palette fills');
    });

    test('the flag that drives the tone says warning, not danger', () => {
        // It used to be `danger`, which is what made a red confirm look correct.
        assert.ok(!billing.includes('danger'), 'a danger flag would invite the destructive colour back');
        assert.match(billing, /warning = false/);
    });

    test('resuming a cancelled subscription is the primary way back', () => {
        // Cancellation is reversible until the period ends, which is why it warns instead of
        // deleting — and why the way back is an ordinary primary action.
        const resume = actionFor('onClick={() => setConfirmResume(true)}');

        assert.match(resume, /PRIMARY_COLOURS/);
        assert.ok(!/green/.test(resume), 'green was never one of the roles');
    });

    test('the subscription actions keep their own compact geometry', () => {
        for (const handler of ['onClick={openPlanChangeModal}', 'onClick={() => setConfirmCancel(true)}', 'onClick={() => setConfirmResume(true)}']) {
            assert.match(actionFor(handler), /rounded-lg .*px-4 py-2 text-base font-medium/);
        }
    });
});
