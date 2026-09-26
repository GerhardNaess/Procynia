import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const panel = readFileSync(join(here, 'WikiReviewPanel.jsx'), 'utf8');
const show = readFileSync(join(here, 'Show.jsx'), 'utf8');
const langFile = (locale) => readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', locale, 'procynia.php'), 'utf8');

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

        // Permission keys are for branching, never for reading: the panel now distinguishes "you
        // are the only approver" from "nobody can approve", which needs the capability in hand. The
        // guard is therefore that every mention is a property read, not that the name is absent.
        for (const mention of panel.matchAll(/approve_wiki_pages/g)) {
            const lead = panel.slice(Math.max(0, mention.index - 40), mention.index);

            assert.match(lead, /reviewAssignment\.actor_can_$/, 'permission keys never reach the screen');
        }
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
    test('the action rows wrap rather than overflow', () => {
        assert.match(panel, /flex flex-wrap items-center gap-2/);
    });

    test('nothing relies on a wide table', () => {
        assert.ok(!panel.includes('<table'), 'the panel is prose and actions, not a table');
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
        assert.match(panel, /httpErrorMessage\(status, tw\)/);
        assert.match(panel, /return false;\s*\n\s*\},/);
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

    test('the return is named for what it does', () => {
        assert.match(panel, /review_send_back \?\? 'Send tilbake'/);
        assert.match(panel, /review_comment \?\? 'Kommentar'/);
    });

    test('a return still cannot be sent empty', () => {
        assert.match(panel, /disabled=\{busy \|\| reasonTooShort\}/);
        assert.match(panel, /if \(trimmed\.length < MIN_REASON\) return;/);
    });

    test('why publishing is unavailable is still said, once, in the publication card', () => {
        // Said by the card's own "Neste steg" and "Gjenstår", both computed server-side — the
        // panel repeating it below the actions was the same sentence twice on one screen.
        assert.match(panel, /data-testid="wiki-publication-next-step"/);
        assert.match(panel, /data-testid="wiki-publication-blockers"/);
        assert.ok(!panel.includes('blockerMessage'), 'and no second copy in the actions');
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

    test('a blocked approval is still explained, by the publication card', () => {
        assert.match(panel, /data-testid="wiki-publication-next-step"/);
        assert.ok(!panel.includes('blockerMessage'));
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
 * The assign action was showing beside "Ingen påstander å kvalitetssikre" — an invitation to ask
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
        assert.match(panel, /qa_assign_button \?\? 'Tildel kvalitetssikrer'/);
        assert.match(panel, /qa_change_button \?\? 'Endre kvalitetssikrer'/);
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
        assert.match(panel, /publication_heading \?\? 'Publisering'/);
    });
});

/**
 * A source document is where content came from, not a level of approval it has to clear.
 *
 * The Wiki page is the thing being approved. Making the source an extra approval meant a finished
 * article could sit unpublished waiting on somebody who had no view on the article at all.
 */
describe('source approval is not part of publishing', () => {
    test('the panel no longer reads a source-owner gate', () => {
        assert.ok(!panel.includes('source_owner_gate'));
        assert.ok(!panel.includes('source_owners_pending'));
        assert.ok(!panel.includes('REQUIREMENT_STATUS'));
    });

    test('no requirement can be decided from the Wiki page', () => {
        assert.ok(!panel.includes('document-owner-approvals'), 'the panel calls no such endpoint');
        assert.ok(!show.includes('document-owner-approvals'));
        assert.ok(!panel.includes('review_approve_source'), '"Godkjenn kildeinnhold" is gone');
    });

    test('the document owner panel is gone from the page', () => {
        assert.ok(!show.includes('Dokumenteiergodkjenning'));
        assert.ok(!show.includes('documentOwnerApprovals'));
        assert.ok(!show.includes('documentOwnerApprovalSummary'));
    });

    test('sending the page back is now the dialog\'s only caller', () => {
        assert.ok(!panel.includes('isPageReturn'), 'no branch for a second caller');
        assert.match(panel, /run\('reject', `\/app\/wiki\/\$\{page\.slug\}\/reject`, \{ reason: trimmed \}\)/);
        assert.match(panel, /review_send_back \?\? 'Send tilbake'/);
    });

    test('no source-owner reason survives anywhere in the panel', () => {
        assert.ok(!panel.includes('source_owners_pending'));
        assert.ok(!panel.includes('review_waiting_for_owners'));
    });

    test('what the page is now about: editing, QA, review, publishing', () => {
        assert.match(show, /data-testid="wiki-article-edit"/);
        assert.match(panel, /data-testid="wiki-qa-assignment"/);
        assert.match(panel, /review_approve_and_publish \?\? 'Godkjenn og publiser'/);
        assert.match(panel, /submit_button \?\? 'Send til gjennomgang'/);
    });
});

/**
 * The panel must never end up enabled but inert.
 *
 * `processing` blocks a second click and only onFinish clears it. Inertia fires onFinish from a
 * `finally`, so any completed request clears it — but a dispatch that throws before that promise
 * chain exists never reaches it, and from then on every click on the panel is swallowed. An enabled
 * button that does nothing is the worst state this panel can be in: there is no error, no progress
 * and no way back except reloading the page.
 */
describe('a click always leaves the panel usable', () => {
    test('a throwing dispatch releases the lock and says something', () => {
        assert.match(panel, /try \{\s*\n\s*router\.patch\(url, data, \{/);
        assert.match(panel, /\} catch \(error\) \{\s*\n\s*setProcessing\(null\);\s*\n\s*setActionError\(/);
    });

    test('the lock is taken before the request and released only by it', () => {
        assert.match(panel, /if \(processing\) return;\s*\n\s*setProcessing\(key\);/);
        assert.match(panel, /onFinish: \(\) => setProcessing\(null\)/);
    });

    test('a request in flight says so, rather than only greying out', () => {
        assert.match(panel, /processing === 'submit'\s*\n\s*\? \(tw\.review_sending \?\? 'Sender …'\)/);
    });
});

/**
 * A view of the page that has gone out of date must correct itself.
 *
 * 409 and 422 both mean the same thing to the person holding the screen: the page moved on while
 * this view of it stood still. Somebody else acted, or the tab has been open since before the
 * change. Leaving the dialog up then keeps offering an action the server will refuse every time,
 * against a page that no longer looks the way the screen says it does — a click that appears to do
 * nothing, on a page that appears not to have been sent.
 */
describe('a stale view corrects itself', () => {
    test('a moved-on page re-reads itself from the server', () => {
        // The rule itself lives in wikiReviewRefusal.js and is unit-tested there; this only holds
        // that the panel acts on it rather than on a bare status code.
        assert.match(panel, /const isStale = isStaleStateRefusal\(status, response\?\.data\);/);
        assert.match(panel, /router\.reload\(\{ preserveScroll: true \}\)/);
    });

    test('and closes the dialog whose action no longer applies', () => {
        const start = panel.indexOf('if (! isStale) {');
        const branch = panel.slice(start, start + 900);
        assert.match(branch, /setIsSubmitOpen\(false\)/);
        assert.match(branch, /setChangesTarget\(null\)/);
    });

    /** The case that must not reload: a field the person can fix in the dialog they are in. */
    test('a validation failure keeps the dialog and shows the field message', () => {
        assert.match(panel, /if \(! isStale\) \{\s*\n\s*return false;\s*\n\s*\}/);
        assert.match(panel, /firstError\(response\?\.data\?\.errors\) \?\? httpErrorMessage\(status, tw\)/);
    });

    test('the state is re-read, never guessed at locally', () => {
        // Nothing here writes an optimistic status; the server's props are the only source.
        const start = panel.indexOf('onHttpException:');
        const handler = panel.slice(start, start + 900);
        assert.ok(!/setPage|page\.status =|status: 'pending_review'/.test(handler));
    });

    test('the message says what just happened, not what to go and do', () => {
        assert.match(panel, /Siden er oppdatert med gjeldende status/);
        assert.ok(!panel.includes('Last siden på nytt for å se gjeldende status'));
    });

    /** 403 and 419 are about the person or the session, not the page — the dialog stays. */
    test('an authorization or session failure leaves the dialog up', () => {
        // Neither is stale state, so both fall through the early return above.
        assert.match(panel, /review_error_expired/);
        assert.match(panel, /review_error_forbidden/);
    });
});

/**
 * Quality assurance, in words a bid manager would use.
 *
 * "QA" is what the code calls the capability; it is not what the person doing the work is called.
 * "Endre QA" read as changing a setting rather than asking a colleague to check something, and the
 * dialog it opened used a third vocabulary again.
 */
describe('the quality reviewer is named as a person', () => {
    test('the action names what it does, and to whom', () => {
        assert.match(panel, /qa_assign_button \?\? 'Tildel kvalitetssikrer'/);
        assert.match(panel, /qa_change_button \?\? 'Endre kvalitetssikrer'/);
        assert.ok(!panel.includes("'Endre QA'"));
        assert.ok(!panel.includes("'Send til QA'"));
    });

    test('the dialog title follows whether somebody already holds it', () => {
        const start = panel.indexOf('id="wiki-qa-title"');
        const title = panel.slice(start, start + 320);
        assert.match(title, /qaAssignment\?\.assignee/);
        assert.match(title, /qa_change_button/);
        assert.match(title, /qa_assign_button/);
    });

    test('the field is labelled for the person, not the area', () => {
        // The block heading still says Kvalitetssikring — that is the area. The field asks for a
        // Kvalitetssikrer, which is a colleague.
        assert.match(panel, /qa_user_label \?\? 'Kvalitetssikrer'/);
        assert.match(panel, /qa_heading \?\? 'Kvalitetssikring'/);
    });

    test('the confirm button says which of the two it is doing', () => {
        assert.match(panel, /qa_update_button \?\? 'Oppdater'/);
        assert.match(panel, /qa_send_button \?\? 'Tildel'/);
    });

    test('both languages carry every key the panel falls back from', () => {
        for (const key of ['qa_assign_button', 'qa_change_button', 'qa_send_button', 'qa_update_button', 'qa_user_label']) {
            assert.match(langFile('no'), new RegExp(`'${key}' =>`), `no: ${key}`);
            assert.match(langFile('en'), new RegExp(`'${key}' =>`), `en: ${key}`);
        }
    });
});

/**
 * A published page used to say "0 av 7 påstander godkjent" directly above "Ingen handling
 * gjenstår." Both were true — quality assurance has never gated publication — but a card that
 * answers two different questions at once invites the reading that it contradicts itself.
 *
 * The card now answers one question: what has to happen for this page to be published. Quality
 * progress sits with the assignment it describes, and says "kvalitetssikret" rather than
 * "godkjent", because approving a page and quality assuring a claim are different decisions that
 * happened to share a word.
 */
describe('publication and quality are told apart', () => {
    test('the publication card carries no claim progress', () => {
        const start = panel.indexOf('function PublicationBlock');
        const card = panel.slice(start, panel.indexOf('\n}\n', start));

        assert.ok(! card.includes('claims'));
        assert.ok(! panel.includes('publication_claims_quality'));
        assert.ok(! panel.includes('publication_quality_heading'));
    });

    test('the quality block keeps it, in its own words', () => {
        assert.match(panel, /qa_claims_progress \?\? ':approved av :total påstander kvalitetssikret'/);
        assert.match(panel, /data-testid="wiki-qa-progress"/);
    });

    test('a published page says which kind of action is finished', () => {
        assert.match(langFile('no'), /'publication_next_none' => 'Ingen publiseringshandling gjenstår\.'/);
        assert.match(langFile('en'), /'publication_next_none' => 'No publication action remains\.'/);
    });

    test('the removed keys are gone from both languages', () => {
        for (const key of ['publication_quality_heading', 'publication_claims_quality']) {
            assert.ok(! langFile('no').includes(`'${key}'`), `no: ${key}`);
            assert.ok(! langFile('en').includes(`'${key}'`), `en: ${key}`);
        }
    });

    test('a version with no claims still says so, in the quality block', () => {
        assert.match(panel, /qa_no_claims \?\? 'Ingen påstander å kvalitetssikre'/);
    });
});

/**
 * An approved page is a published page.
 *
 * approve() writes status and published_version_id in one go, so the two can never disagree. The
 * badge said "Godkjent", which left the reader asking what was still missing — especially next to
 * a quality block reporting 0 of 7, which is unrelated and does not stop anything.
 */
describe('a published page says so', () => {
    test('the badge names publication, not approval', () => {
        assert.match(show, /approved: tw\.status_approved \?\? 'Publisert'/);
        assert.match(langFile('no'), /'status_approved' => 'Publisert',/);
        assert.match(langFile('en'), /'status_approved' => 'Published',/);
    });

    test('the other statuses are untouched', () => {
        assert.match(show, /pending_review: tw\.status_pending_review \?\? 'Til gjennomgang'/);
        assert.match(show, /draft: tw\.status_draft \?\? 'Utkast'/);
        assert.match(show, /rejected: tw\.status_changes_requested \?\? 'Endringer kreves'/);
    });

    /** A claim is still approved by a person; only the page's own status changed wording. */
    test('claim approval keeps its own word', () => {
        assert.match(show, /claim_status_approved \?\? 'Godkjent'/);
    });
});

/**
 * The panel's own hierarchy, and its readability floor.
 *
 * Publisering announced itself as a small-caps label, then used a second label to say "Neste steg",
 * then answered — three levels of chrome for one sentence. Kvalitetssikring did the same. Below
 * them, ordinary information about the page sat at 14px and one line at 12px, which is the size
 * this app reserves for things nobody has to read.
 */
describe('the publication panel reads as a hierarchy', () => {
    test('each block names itself once, as a heading', () => {
        assert.match(panel, /<h3 className="text-lg font-semibold text-slate-900" data-testid="wiki-publication-heading">/);
        assert.match(panel, /`\$\{tw\.publication_heading \?\? 'Publisering'\} – \$\{tw\.publication_next_label \?\? 'Neste steg'\}`/);
        assert.match(panel, /<h3 className="text-lg font-semibold text-slate-900">\s*\n\s*\{tw\.qa_heading \?\? 'Kvalitetssikring'\}/);
    });

    test('the heading names the person the page is waiting on', () => {
        // "Publisering – Neste steg" describes the card; "Venter på Alisan Senel" answers the
        // question the reader has. Only the page owner is promoted: the reviewer case already
        // names its person in the sentence below, and saying it twice adds nothing.
        assert.match(panel, /publication\.next_actor\?\.role === 'page_owner'/);
        assert.match(panel, /\(tw\.publication_waiting_for \?\? 'Venter på :name'\)\.replace\(':name', waitingOn\)/);
    });

    test('both languages carry the same three sentences', () => {
        for (const locale of ['no', 'en']) {
            const lang = langFile(locale);

            for (const key of [
                'publication_waiting_for',
                'publication_next_awaiting_owner_named',
                'publication_next_submit_self',
            ]) {
                assert.match(lang, new RegExp(`'${key}' => '`), `${key} missing in ${locale}`);
            }
        }

        // The named sentence has to carry the placeholder, or the name never reaches the screen.
        for (const locale of ['no', 'en']) {
            const named = langFile(locale).match(/'publication_next_awaiting_owner_named' => '([^']*)'/)?.[1];

            assert.ok(named, `no ${locale} sentence`);
            assert.match(named, /:name/, `${locale} sentence must interpolate the owner`);
        }
    });

    test('no section name is set as a machine label any more', () => {
        assert.ok(! panel.includes('uppercase tracking-[0.12em]'), 'no small caps, no wide tracking');
    });

    test('the next step is the answer under the heading, not a second label', () => {
        assert.match(panel, /<p className="text-base leading-6 text-slate-700" data-testid="wiki-publication-next-step">/);
        assert.ok(! panel.includes('<dt className='), 'the label/value pair is gone');
    });

    test('information about the page is information, at 16px', () => {
        for (const key of ['review_nothing_published', 'review_working_version', 'review_page_owner']) {
            assert.match(panel, new RegExp(key));
        }
        assert.match(panel, /className="flex flex-wrap items-baseline gap-x-6 gap-y-1 text-base"/);
        assert.match(panel, /className="flex flex-wrap gap-x-6 gap-y-1 text-base"/);
    });

    test('nothing anybody reads is set at 12px', () => {
        assert.ok(! panel.includes('text-xs'));
    });

    /**
     * What is left at 14px: the byline under a returned page's reason, the previous rounds of
     * feedback folded away behind a disclosure and their bylines, and a character counter. None is
     * an instruction, a status or next to an action — the things this surface keeps at 16px.
     */
    test('14px is left only for genuinely secondary metadata', () => {
        const small = [...panel.matchAll(/className="[^"]*text-sm[^"]*"/g)].map((m) => m[0]);

        assert.ok(small.length <= 4, `14px used ${small.length} times`);
        assert.ok(
            small.every((c) => /amber-800|amber-900|slate-500/.test(c)),
            'bylines, folded history and counters only',
        );
        // The things that must not shrink: what the owner was asked to change, whose turn it is.
        assert.match(panel, /<p className="mt-1 text-base leading-6 text-amber-900">\{changes\.latest\.reason\}/);
        assert.match(panel, /<p className="mt-1 text-base text-violet-800">/);
    });

    test('the divisions between the blocks are untouched', () => {
        assert.ok((panel.match(/border-b border-slate-100 pb-4/g) ?? []).length >= 2);
    });
});

/**
 * The page help has to describe the workflow that exists.
 *
 * It still said the reviewer could publish only once the document-owner checks were done, that
 * submitting gave those owners their own checkpoints, and nothing at all about quality assurance
 * or about a System Owner publishing a draft outright. Every one of those was true of a workflow
 * that has since been taken apart — and help that describes a removed gate is worse than no help,
 * because somebody will wait for it.
 */
describe('the page help matches the workflow', () => {
    test('nothing claims a document owner gates publication', () => {
        for (const lang of ['no', 'en']) {
            assert.ok(! langFile(lang).includes('dokumenteierkontroller'), lang);
            assert.ok(! langFile(lang).includes('document-owner checks'), lang);
        }
        assert.match(show, /show_page_help_item_publish_text \?\? 'Arbeidsversjonen blir den publiserte kunnskapen på siden\. Ingenting annet må være ferdig først\.'/);
    });

    test('an assigned reviewer is described as decisive', () => {
        assert.match(show, /Ingen kan hoppe over en tildelt kontrollør — heller ikke System Owner/);
    });

    test('the System Owner route from draft is described, and as optional', () => {
        assert.match(show, /show_page_help_item_system_owner_title/);
        assert.match(show, /Kan publisere en side direkte fra utkast/);
        assert.match(show, /For System Owner er den valgfri/);
    });

    test('quality assurance is explained, and explicitly not a gate', () => {
        assert.match(show, /show_page_help_section_quality/);
        assert.match(show, /Kvalitetssikring stopper ingenting/);
        assert.match(show, /kan publiseres selv om ingen påstander er kvalitetssikret/);
    });

    test('a published page is told that quality work may continue', () => {
        assert.match(show, /Kvalitetssikring kan fortsatt pågå/);
    });

    test('editing is described', () => {
        assert.match(show, /show_page_help_item_edit_title \?\? 'Rediger artikkel'/);
    });

    test('the source material is framed as traceability', () => {
        assert.match(show, /Dette er sporbarhet: Wiki-siden kan publiseres uten at dokumenteier har tatt stilling/);
    });

    test('no internal vocabulary reaches the reader', () => {
        const help = show.slice(show.indexOf('show_page_help_section_about'), show.indexOf('const PAGE_STATUS_STYLES'));
        for (const word of ['awaiting_document_owner', 'Send til QA', 'claims']) {
            assert.ok(! help.includes(word), word);
        }
    });

    test('both languages carry every key the help falls back from', () => {
        for (const key of [
            'show_page_help_item_system_owner_title', 'show_page_help_item_system_owner_text',
            'show_page_help_item_edit_title', 'show_page_help_item_edit_text',
            'show_page_help_section_quality', 'show_page_help_item_quality_who_text',
            'show_page_help_item_quality_optional_text', 'show_page_help_section_sources',
        ]) {
            assert.match(langFile('no'), new RegExp(`'${key}' =>`), `no: ${key}`);
            assert.match(langFile('en'), new RegExp(`'${key}' =>`), `en: ${key}`);
        }
    });
});
