import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Reject and delete must stay two visibly different things on "I arbeid": rejection is reversible
 * and keeps the requirement, deletion is permanent and takes its answer draft with it. These read
 * the page source, because the value here is that both affordances exist, are separately confirmed,
 * and cannot be triggered by the same click.
 */
const source = readFileSync(fileURLToPath(new URL('./Show.jsx', import.meta.url)), 'utf8');

describe('Reject stays intact', () => {
    test('per-requirement reject and its confirmation are untouched', () => {
        assert.match(source, /confirmRejectRequirementId/);
        assert.match(source, /tai\.reject_confirm_title/);
        assert.match(source, /updateRequirementReviewStatus\(requirement, 'rejected'\)/);
    });

    test('restore is still offered for a rejected requirement', () => {
        assert.match(source, /rejected: \[\s*\{\s*label: tai\.approval_action_restore/);
    });

    test('reject all is still there with its own confirmation', () => {
        assert.match(source, /rejectAllExtractedRequirements\(\)/);
        assert.match(source, /tai\.reject_all_confirm_title/);
    });
});

describe('Delete is a separate, permanent action', () => {
    test('every extracted requirement offers delete', () => {
        assert.match(source, /data-testid="requirement-delete-button"/);
        assert.match(source, /tai\.requirement_action_delete/);
        // Only when the server said this requirement may be deleted.
        assert.match(source, /requirement\.delete_url && confirmDeleteRequirementId/);
    });

    test('deleting requires its own confirmation, not the rejection one', () => {
        assert.match(source, /data-testid="requirement-delete-confirm"/);
        assert.match(source, /tai\.requirement_delete_confirm_title/);
        assert.match(source, /tai\.requirement_delete_confirm_message/);
        // Opening one confirmation closes the other, so the two can never be one click apart.
        assert.match(source, /setConfirmRejectRequirementId\(null\);\s*\n\s*setConfirmDeleteRequirementId\(requirement\.id\)/);
    });

    test('delete all exists with its own confirmation', () => {
        assert.match(source, /data-testid="requirements-delete-all-button"/);
        assert.match(source, /data-testid="requirements-delete-all-confirm"/);
        assert.match(source, /deleteAllExtractedRequirements/);
    });

    test('the bulk confirmation shows the number that will actually be deleted', () => {
        // extractedRequirementRows excludes manual requirements, matching the server's
        // ai_candidate scope — so the count shown cannot overstate what is deleted.
        assert.match(source, /requirements_delete_all_confirm_title[\s\S]{0,200}extractedRequirementRows\.length/);
        assert.match(source, /extractedRequirementRows = requirementRows\.filter\([\s\S]{0,120}!== 'manual'/);
    });

    test('the client never sends its own list of ids', () => {
        const handler = source.slice(
            source.indexOf('const deleteAllExtractedRequirements'),
            source.indexOf('const rejectAllExtractedRequirements'),
        );

        assert.ok(handler.includes('router.delete(requirementsDeleteAllUrl'));
        assert.ok(!handler.includes('requirement_ids'));
        assert.ok(!handler.includes('.map('));
    });

    test('both delete actions are disabled while one is running', () => {
        assert.match(source, /deletingRequirementId !== null/);
        assert.match(source, /disabled=\{requirementUpdatesLocked \|\| deletingAllRequirements\}/);
    });
});

describe('Wording', () => {
    const no = readFileSync(fileURLToPath(new URL('../../../../../lang/no/procynia.php', import.meta.url)), 'utf8');
    const en = readFileSync(fileURLToPath(new URL('../../../../../lang/en/procynia.php', import.meta.url)), 'utf8');

    test('both languages define the delete wording', () => {
        for (const file of [no, en]) {
            for (const key of [
                'requirement_action_delete',
                'requirement_delete_confirm_title',
                'requirement_delete_confirm_message',
                'requirement_delete_confirm_button',
                'requirements_delete_all_button',
                'requirements_delete_all_confirm_title',
                'requirements_delete_all_confirm_message',
            ]) {
                assert.ok(file.includes(`'${key}' =>`), `missing ${key}`);
            }
        }
    });

    test('the confirmation is honest about what cascades and what is kept', () => {
        // The FK cascade takes the answer draft, assessments and revisions; the document survives.
        assert.match(no, /svarutkast, vurderinger og endringshistorikk/);
        assert.match(no, /Kildedokumentet beholdes/);
        assert.match(no, /hentes inn på nytt ved ny ekstraksjon/);
    });
});
