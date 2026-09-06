import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const panel = readFileSync(join(here, 'WikiReviewPanel.jsx'), 'utf8');
const show = readFileSync(join(here, 'Show.jsx'), 'utf8');

/**
 * The Wiki page had to answer six questions after steps 1-10: what is published, what is being
 * worked on, who owns it, who are we waiting for, what was I asked to change, and what can I do.
 * The old UI answered none of them — it showed a System-Owner-only Approve/Reject pair and sent
 * empty request bodies, which the new endpoints reject outright.
 *
 * The project has no JSX renderer, so these are source-level guards, the same idiom used elsewhere
 * in this suite. Behaviour that can be tested for real is covered by the PHP feature tests.
 */
describe('the page reads what the backend actually sends', () => {
    test('Show passes the review assignment into the panel', () => {
        assert.match(show, /review_assignment: reviewAssignment = null/, 'the prop is destructured');
        assert.match(show, /<WikiReviewPanel/);
        assert.match(show, /reviewAssignment=\{reviewAssignment\}/);
    });

    test('the old System-Owner-only approval block is gone', () => {
        // It gated on isSystemOwner and ignored assignment, capability and the source-owner gate.
        assert.ok(!show.includes('const actionableForOwner = isSystemOwner'));
        assert.ok(!show.includes("tw.pending_review_notice"), 'the "waiting for a System Owner" notice is gone');
    });

    test('requests carry the payloads the endpoints now require', () => {
        // Empty bodies would 422: submit needs a reviewer, a return needs a reason.
        assert.match(panel, /\/submit`, \{ reviewer_user_id: Number\(reviewerId\) \}/);
        assert.match(panel, /\/reject`, \{ reason: trimmed \}/);
        assert.match(panel, /\/reject`, \{ comment: trimmed \}/);
    });
});

describe('published and working versions are told apart', () => {
    test('both are named, and the published one is not implied to be gone', () => {
        assert.match(panel, /review_published_version/);
        assert.match(panel, /review_working_version/);
        assert.match(panel, /review_published_still_serves/);
        assert.match(panel, /Ingen publisert versjon ennå/, 'a page with nothing published says so');
    });

    test('the working version is only named when it differs from the published one', () => {
        assert.match(panel, /reviewAssignment\.published_version_id !== currentVersion\.id/);
    });
});

describe('status reads as workflow, not as refusal', () => {
    test('rejected is presented as changes requested', () => {
        assert.match(show, /rejected: tw\.status_changes_requested \?\? 'Endringer kreves'/);
        assert.ok(!show.includes("rejected: tw.status_rejected ?? 'Avvist'"));
    });
});

describe('responsibility is shown only where there is something to say', () => {
    test('owner, submitter and reviewer each render only when set', () => {
        assert.match(panel, /function OwnerLine/);
        assert.match(panel, /if \(! name\) \{\s*\n\s*return null;/, 'an unset name renders nothing');

        for (const key of ['review_page_owner', 'review_submitted_by', 'review_reviewer']) {
            assert.ok(panel.includes(key), `${key} is rendered`);
        }
    });
});

describe('submitting names a reviewer explicitly', () => {
    test('the reviewer is chosen in a dialog, from the eligible list only', () => {
        assert.match(panel, /<ActionDialog/);
        assert.match(panel, /eligibleReviewers\.map/);
        assert.ok(!panel.includes('prompt('), 'never a browser prompt');
    });

    test('submitting is blocked until somebody is chosen', () => {
        assert.match(panel, /disabled=\{busy \|\| reviewerId === ''\}/);
    });

    test('a single candidate is pre-selected but still visible and changeable', () => {
        assert.match(panel, /eligibleReviewers\.length === 1 \? String\(eligibleReviewers\[0\]\.id\) : ''/);
    });

    test('no eligible reviewer is explained, not silently broken', () => {
        assert.match(panel, /disabled=\{busy \|\| eligibleReviewers\.length === 0\}/);
        assert.match(panel, /review_no_eligible_reviewers/);
        assert.ok(!panel.includes('approve_wiki_pages'), 'permission keys never reach the screen');
    });
});

describe('the source-owner gate is not final approval', () => {
    test('its actions are worded as vouching for source content', () => {
        assert.match(panel, /review_approve_source/);
        assert.match(panel, /Godkjenn kildeinnhold/);
    });

    test('only the owner of a requirement is offered its actions', () => {
        assert.match(panel, /requirement\.can_decide && requirement\.status === 'pending'/);
    });

    test('each requirement shows who it belongs to and how many documents it covers', () => {
        assert.match(panel, /requirement\.owner\?\.name/);
        assert.match(panel, /requirement\.source_document_ids/);
    });

    test('status is carried by a word, not only by colour', () => {
        assert.match(panel, /REQUIREMENT_STATUS/);
        assert.match(panel, /label: 'Venter'/);
        assert.match(panel, /label: 'Godkjent'/);
        assert.match(panel, /label: 'Endringer kreves'/);
    });
});

describe('final approval says what it does', () => {
    test('the primary action names publication', () => {
        assert.match(panel, /Godkjenn og publiser/);
    });

    test('it is offered only when the backend says it is available', () => {
        assert.match(panel, /isInReview && reviewAssignment\.can_approve_final/);
    });

    test('a System Owner stepping into somebody else\'s assignment is labelled as such', () => {
        assert.match(panel, /review_approve_as_system_owner/);
        assert.match(panel, /reviewAssignment\.reviewer\.id !== currentUserId/);
    });
});

describe('blockers are explained in words', () => {
    test('every raw key is mapped, and none is rendered', () => {
        for (const key of ['source_owners_pending', 'own_submission', 'not_assigned', 'missing_assignment']) {
            assert.ok(panel.includes(`'${key}'`), `${key} is handled`);
        }

        // The keys appear only as switch cases, never inside rendered markup.
        const rendered = panel.slice(panel.indexOf('return ('));
        for (const key of ['source_owners_pending', 'missing_capability', 'not_in_review']) {
            assert.ok(!rendered.includes(key), `${key} must not be shown to a user`);
        }
    });

    test('the pending case names who is being waited on', () => {
        assert.match(panel, /waitingFor\.join\(', '\)/);
    });

    test('blockers that are not the reader\'s problem stay silent', () => {
        assert.match(panel, /default:\s*\n(\s*\/\/.*\n)*\s*return null;/);
    });
});

describe('changes requested is easy to find and act on', () => {
    test('the latest reason, author and role are shown near the top', () => {
        assert.match(panel, /changes\.latest\.reason/);
        assert.match(panel, /changes\.latest\.actor\?\.name/);
        assert.match(panel, /review_role_document_owner/);
        assert.match(panel, /review_role_reviewer/);
    });

    test('older feedback is available but folded away', () => {
        assert.match(panel, /changes\.history\.length > 1/);
        assert.match(panel, /changes\.history\.slice\(1\)/);
        assert.match(panel, /DISCLOSURE_INLINE/);
        assert.match(panel, /aria-expanded=\{showHistory\}/);
    });

    test('a reason is required before changes can be requested', () => {
        assert.match(panel, /const MIN_REASON = 10/);
        assert.match(panel, /const MAX_REASON = 2000/);
        assert.match(panel, /disabled=\{busy \|\| reasonTooShort\}/);
    });

    test('the dialog says the published version is untouched', () => {
        assert.match(panel, /review_request_changes_page/);
        assert.match(panel, /review_request_changes_source/);
        assert.match(panel, /Den publiserte versjonen berøres ikke/);
    });
});

describe('reopening goes through the backend', () => {
    test('it calls submit without a reviewer, which is the reopen route', () => {
        assert.match(panel, /const reopen = \(\) => run\('reopen', `\/app\/wiki\/\$\{page\.slug\}\/submit`\)/);
        assert.match(panel, /canReopen/);
    });
});

describe('the action roles follow the house standard', () => {
    test('it uses the shared styles rather than its own colours', () => {
        assert.match(panel, /from '\.\.\/\.\.\/\.\.\/Support\/actionStyles'/);

        for (const banned of ['bg-emerald-600', 'bg-violet-600', 'bg-rose-600']) {
            assert.ok(!panel.includes(banned), `${banned} is not a role in the standard`);
        }
    });

    test('asking for changes warns; publishing is primary; cancel is secondary', () => {
        const requestChangesButton = panel.slice(panel.lastIndexOf('<button', panel.indexOf("onClick={requestChanges}")), panel.indexOf("onClick={requestChanges}") + 200);
        assert.match(requestChangesButton, /WARNING_ACTION/);
    });
});

describe('accessibility', () => {
    test('the select and the textarea are labelled', () => {
        assert.match(panel, /htmlFor="wiki-reviewer"/);
        assert.match(panel, /id="wiki-reviewer"/);
        assert.match(panel, /htmlFor="wiki-change-reason"/);
        assert.match(panel, /aria-describedby="wiki-change-reason-help"/);
    });

    test('dialogs are the shared, focus-trapping ones', () => {
        assert.match(panel, /import ActionDialog/);
        assert.match(panel, /titleId="wiki-submit-title"/);
        assert.match(panel, /titleId="wiki-changes-title"/);
        assert.match(panel, /initialFocusRef=\{reviewerSelectRef\}/);
        assert.match(panel, /returnFocusRef=\{submitTriggerRef\}/);
    });
});

describe('responsive', () => {
    test('a requirement row stacks on a narrow screen', () => {
        assert.match(panel, /flex flex-col gap-2 .*sm:flex-row/);
    });

    test('nothing relies on a wide table', () => {
        assert.ok(!panel.includes('<table'), 'requirements are a list, not a table');
    });
});

describe('the page keeps its help layer', () => {
    test('every help section is still in Show', () => {
        // Renamed in step 12 when the help was rewritten to match the implemented flow; the point
        // of this guard is that the help layer is never lost while the UI around it changes.
        for (const section of ['about', 'versions', 'responsibility', 'changes']) {
            assert.ok(
                show.includes(`show_page_help_section_${section}`),
                `the ${section} help section must not be lost`,
            );
        }
    });

    test('the submit InfoHint moved into the panel rather than being dropped', () => {
        assert.match(panel, /show_page_help_submit_hint_label/);
        assert.match(panel, /show_page_help_submit_hint\b/);
        assert.match(panel, /<InfoHint/);
    });
});
