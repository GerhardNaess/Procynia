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
        // One flag decides, for a draft and for a page in review alike — the backend works out
        // which of those the actor may publish.
        assert.match(panel, /\{reviewAssignment\.can_approve_final && \(/);
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

        // The keys appear only as switch cases, never inside rendered markup. Anchored on the
        // component itself rather than the file's first `return (`, which is whichever small
        // helper happens to be declared at the top.
        const rendered = panel.slice(panel.indexOf('export default function WikiReviewPanel'));
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

/**
 * A refused handover used to be indistinguishable from a click that never happened: the dialog
 * stayed open, the button came back, and nothing said why. These guard the two halves of the fix —
 * a success visibly finishes the action, and a failure says what went wrong in the dialog the
 * person is standing in.
 */
describe('the handover says what happened', () => {
    test('a success closes the dialog it was started from', () => {
        assert.match(panel, /onSuccess: \(\) => \{\s*setIsSubmitOpen\(false\);/);
        assert.match(panel, /setIsQaOpen\(false\);/);
        assert.match(panel, /setChangesTarget\(null\);/);
    });

    test('validation errors and aborts both reach the same message', () => {
        assert.match(panel, /onError: \(errors\) => setActionError\(/);
        assert.match(panel, /onHttpException: \(response\) => \{/, 'aborts arrive outside onError');
        // Returning false suppresses Inertia's raw overlay in favour of the panel's own message.
        assert.match(panel, /setActionError\(httpErrorMessage\(response\?\.status, tw\)\);\s*\n\s*\n\s*return false;/);
    });

    test('each refusal is explained in terms of the work, not the status code', () => {
        for (const status of ['403', '409', '419', '422']) {
            assert.match(panel, new RegExp(`case ${status}:`), `${status} has its own sentence`);
        }
        assert.match(panel, /review_error_conflict/, 'somebody else took the assignment');
        assert.match(panel, /review_error_expired/, 'the session ran out');
        assert.match(panel, /Last siden på nytt/, 'and each one says what to do about it');
    });

    test('the message is rendered where the action was started', () => {
        assert.match(panel, /data-testid="wiki-review-action-error"/);
        assert.match(panel, /role="alert"/);
        // Once inside the open dialog, once in the panel for the actions that have no dialog.
        assert.ok(panel.split('<ActionError message={actionError} />').length - 1 >= 2);
    });

    test('a stale message does not survive the next attempt', () => {
        assert.match(panel, /setProcessing\(key\);\s*\n\s*setActionError\(null\);/);
        assert.match(panel, /onClose=\{\(\) => \{ setIsSubmitOpen\(false\); setActionError\(null\); \}\}/);
    });
});

/**
 * The reviewer's two decisions, and the fact that they are two.
 *
 * Both used to render behind can_approve_final, which folds six unrelated conditions into one flag.
 * So a reviewer waiting on a document owner — the ordinary case — opened the page and found no
 * action at all, including the return that was the way out of the wait.
 */
describe('the reviewer can act on the page', () => {
    test('the panel is told whose move it is', () => {
        assert.match(panel, /const canSendBack = isInReview && reviewAssignment\.can_send_back === true;/);
        assert.match(panel, /data-testid="wiki-review-turn"/);
        assert.match(panel, /Denne siden venter på din gjennomgang/);
    });

    test('publishing and returning render on their own conditions', () => {
        assert.match(panel, /\{reviewAssignment\.can_approve_final && \(\s*\n\s*<button/);
        assert.match(panel, /\{canSendBack && \(\s*\n\s*<button/);
        // The old single branch that hid both together must be gone.
        assert.ok(!panel.includes('{isInReview && reviewAssignment.can_approve_final && (\n                    <>'));
    });

    test('the return is named for what it does, not for the requirement action beside it', () => {
        // "Be om endringer" is the document owner's objection to their own source. A reviewer
        // sending the whole page back is a different act and reads as one.
        assert.match(panel, /review_send_back \?\? 'Send tilbake'/);
        assert.match(panel, /const isPageReturn = changesTarget === 'page';/);
        assert.match(panel, /review_comment \?\? 'Kommentar'/);
    });

    test('a return still cannot be sent empty', () => {
        assert.match(panel, /disabled=\{busy \|\| reasonTooShort\}/);
        assert.match(panel, /if \(trimmed\.length < MIN_REASON\) return;/);
    });

    test('why publishing is unavailable is still said, next to what is available', () => {
        assert.match(panel, /\{isInReview && ! reviewAssignment\.can_approve_final && blocker && \(/);
    });

    test('the returned page says when it was sent back, not only by whom', () => {
        assert.match(panel, /formatReviewDate\(changes\.latest\.created_at\)/);
    });
});

/**
 * A System Owner can finish any page in their customer's Wiki. That is authority, not a queue —
 * telling them the page is "waiting on your review" when somebody else was named would be false,
 * and would hide the fact that a reviewer is presumably working on it.
 */
describe('stepping in reads differently from being asked', () => {
    test('the turn banner requires actually being the named reviewer', () => {
        assert.match(panel, /const isAssignedReviewer = currentUserId !== null\s*\n\s*&& reviewAssignment\.reviewer\?\.id === currentUserId;/);
        assert.match(panel, /const isMyReview = isInReview && isAssignedReviewer &&/);
    });

    test('a stand-in is told who the page is with, and that they may finish it', () => {
        assert.match(panel, /data-testid="wiki-review-standin"/);
        assert.match(panel, /const actsAsSystemOwner = isInReview && ! isAssignedReviewer/);
        assert.match(panel, /Siden er til gjennomgang hos/);
        assert.match(panel, /Som System Owner kan du ferdigstille siden uten å være tildelt kontrollør/);
    });

    test('the two states are mutually exclusive', () => {
        // isMyReview requires isAssignedReviewer; actsAsSystemOwner requires its negation.
        assert.ok(panel.includes('isInReview && isAssignedReviewer &&'));
        assert.ok(panel.includes('isInReview && ! isAssignedReviewer'));
    });

    test('the reviewer is still named either way', () => {
        assert.match(panel, /review_reviewer \?\? 'Kontrollør:'/);
        assert.match(panel, /reviewAssignment\.reviewer\?\.name/);
    });

    test('an outstanding source owner is still explained rather than silently blocking', () => {
        assert.match(panel, /\{isInReview && ! reviewAssignment\.can_approve_final && blocker && \(/);
        assert.match(panel, /source_owners_pending/);
    });
});

/** A System Owner's draft offers both routes; review is the voluntary one. */
describe('a draft can be published directly', () => {
    test('publishing is no longer gated on the page being in review', () => {
        assert.match(panel, /\{reviewAssignment\.can_approve_final && \(\s*\n\s*<button/);
        assert.match(panel, /const canPublishDraft = page\.status === 'draft' && reviewAssignment\.can_approve_final === true;/);
    });

    test('the panel renders for a draft that can be published', () => {
        assert.match(panel, /showsAnything = canSubmit \|\| canReopen \|\| isInReview \|\| isReturned \|\| canPublishDraft/);
    });

    test('sending it for review remains on offer beside it', () => {
        assert.match(panel, /\{canSubmit && \(/);
        assert.match(panel, /tw\.submit_button \?\? 'Send til gjennomgang'/);
    });
});

/**
 * Editing sits with the text it changes.
 *
 * It used to live in the publication card, in among the review decisions — which is where a reader
 * looks to find out where the page stands, not to start writing. On that row it read as one more
 * decision about the page rather than an action on the article.
 */
describe('the edit action belongs to the article', () => {
    test('the publication card no longer edits anything', () => {
        assert.ok(!panel.includes('onEditArticle'), 'the panel has no edit action');
        assert.ok(!panel.includes('showsArticleEdit'));
        assert.ok(!panel.includes('canEditArticle'));
    });

    test('it renders on the article heading row, right-aligned', () => {
        assert.match(show, /data-testid="wiki-article-edit"/);
        const start = show.indexOf("{tw.article_heading ?? 'Artikkelutkast'}");
        const row = show.slice(start, start + 1600);
        assert.match(row, /article_ai_label \?\? 'AI-generert'/, 'the badge stays beside the heading');
        assert.match(row, /data-testid="wiki-article-edit"/, 'and the action is on the same row');
        assert.match(row, /className="ml-auto inline-flex/, 'pushed to the right');
    });

    test('it is secondary, not a primary action', () => {
        const start = show.indexOf('data-testid="wiki-article-edit"');
        const button = show.slice(start, start + 700);
        assert.match(button, /border-slate-200 bg-white/, 'white with a light border');
        assert.match(button, /text-slate-700/, 'dark text');
        assert.match(button, /hover:bg-slate-50/, 'discreet hover');
        assert.ok(!/violet/.test(button), 'never the violet primary style');
    });

    test('it calls the same handler as before', () => {
        assert.match(show, /onClick=\{startArticleEditing\}/);
        assert.match(show, /const startArticleEditing = \(\) => \{/);
    });

    test('the rule for offering it is unchanged, and read from one place', () => {
        assert.match(show, /import WikiReviewPanel, \{ articleEditUnavailableText \} from '\.\/WikiReviewPanel';/);
        assert.match(show, /workingVersionEdit\?\.unavailable_reason !== 'not_authorized'/);
        assert.match(show, /\(canEditArticle \|\| articleEditUnavailable !== null\)/);
    });

    /**
     * A published page has no "Artikkelutkast" heading, but editing one is a supported route.
     * Hanging the action on the heading alone would have removed it there without anybody asking.
     */
    test('an approved page still gets the action even without the heading', () => {
        assert.match(show, /\{\(!isApproved \|\| \(showsArticleEdit && !isEditingArticle\)\) && \(/);
    });

    test('the action disappears while editing is in progress', () => {
        assert.match(show, /showsArticleEdit && !isEditingArticle && \(/);
    });
});

/**
 * Quality assurance is work on claims, so a version with none offers nobody anything to do.
 * "Send til QA" was showing beside "Ingen påstander å kvalitetssikre" — an invitation to ask
 * somebody to check nothing.
 */
describe('the QA action appears only when there is quality work', () => {
    test('nothing to check means no action', () => {
        assert.match(panel, /const hasClaims = \(claims\.total \?\? 0\) > 0;/);
        assert.match(panel, /const canAssign = hasClaims\s*\n\s*&& qaAssignment\.can_assign/);
    });

    test('the claim count comes from the payload, not from a second rule', () => {
        // claims.total is what the backend already sends for the current version.
        assert.match(panel, /const claims = qaAssignment\.claims \?\? \{ total: 0/);
        assert.ok(!panel.includes('currentVersion.claims'), 'no parallel source of truth');
    });

    test('the empty state says only why, with no leftover assignment line', () => {
        assert.match(panel, /\{\(hasClaims \|\| assignee\) && \(/);
        assert.match(panel, /qa_no_claims \?\? 'Ingen påstander å kvalitetssikre'/);
    });

    test('an existing assignee is still named even if the claims went', () => {
        // Somebody was asked; that remains a fact worth showing.
        assert.match(panel, /hasClaims \|\| assignee/);
    });

    test('when there is work, the action sits beside the progress it acts on', () => {
        const start = panel.indexOf('data-testid="wiki-qa-progress"');
        const row = panel.slice(start, start + 1400);
        assert.match(row, /data-testid="wiki-qa-assign"/, 'same row as the claim progress');
        assert.ok(!/ml-auto/.test(row), 'no longer pushed to the far edge');
    });

    test('assigning itself is untouched', () => {
        assert.match(panel, /qa_assign_button \?\? 'Send til QA'/);
        assert.match(panel, /qa_change_button \?\? 'Endre QA'/);
        assert.match(panel, /\/qa-assignment`, \{ qa_user_id: Number\(qaUserId\) \}/);
    });
});

/**
 * Amber is this page's warning colour, and a draft is not a problem.
 *
 * The banner restated what the status badge and the publication card already say, in the styling
 * reserved for things that need attention — so an ordinary unfinished page looked like one with
 * something wrong with it.
 */
describe('an unfinished page is not dressed as a warning', () => {
    test('the amber draft notice is gone', () => {
        assert.ok(!show.includes('draft_notice'), 'no draft banner');
        assert.ok(!show.includes('pending_review_draft_notice'), 'and none for review either');
        assert.ok(!show.includes('{/* Draft / pending notice */}'));
    });

    test('its flags went with it rather than lingering unused', () => {
        assert.ok(!/const isDraft = /.test(show));
        assert.ok(!/const isPendingReview = /.test(show));
    });

    test('where the page stands is still said, where it belongs', () => {
        // Once, beside the page title.
        assert.match(show, /PAGE_STATUS_STYLES\[page\.status\]/);
    });

    test('the discreet AI marking on the article stays', () => {
        assert.match(show, /article_ai_label \?\? 'AI-generert'/);
        assert.match(show, /bg-amber-100 px-2\.5 py-0\.5 text-xs font-semibold text-amber-700/);
    });
});

/** Status is stated once. Two copies on one screen is one copy that can go stale. */
describe('the publication card explains what happens next, not what the page is', () => {
    test('it carries no status badge of its own', () => {
        assert.ok(!panel.includes('PUBLICATION_STATE_CLS'), 'the style map went with its only reader');
        assert.ok(!panel.includes('publication.state_label'));
    });

    test('the page title still carries the status', () => {
        assert.match(show, /PAGE_STATUS_STYLES\[page\.status\]/);
    });

    test('what the card is actually for is untouched', () => {
        assert.match(panel, /data-testid="wiki-publication-next-step"/);
        assert.match(panel, /data-testid="wiki-publication-blockers"/);
        assert.match(panel, /data-testid="wiki-publication-claims"/);
        assert.match(panel, /publication_heading \?\? 'Publisering'/);
    });
});
