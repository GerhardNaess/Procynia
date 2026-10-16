<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GoNoGoAssessment;
use App\Models\GoNoGoAssessmentAnswer;
use App\Models\GoNoGoAssessmentCriterion;
use App\Models\GoNoGoAssessmentTemplate;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GoNoGoAssessmentController extends Controller
{
    /** A criterion's reasoning; the page shows the same limit (GoNoGoAssessment.jsx). */
    public const COMMENT_MAX_LENGTH = 2000;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
    ) {
    }

    /**
     * Upsert the Go/No-go assessment for a saved notice.
     *
     * The template_id is recorded at creation time so that historical
     * assessments remain intact even if the template is later changed.
     */
    public function upsert(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            abort(404);
        }

        // The case must be one this person can see (404 otherwise, so nothing is revealed about
        // cases outside their visibility) and one they may work on: assessing is a write, so a
        // viewer who can read the case gets 403, the same line every other case write draws.
        $record = $this->savedNoticeAccess->visibleQueryFor($user)
            ->where('customer_id', $customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        abort_unless($this->savedNoticeAccess->canManage($user, $record), 403);

        // A Go/No-go assessment belongs to the Go/No-go phase of an active case. Once the case has
        // moved on or been archived, the assessment is part of its history and is not rewritten.
        if ($record->archived_at !== null || $record->bid_status !== SavedNotice::BID_STATUS_GO_NO_GO) {
            throw ValidationException::withMessages([
                'template_id' => __('procynia.go_no_go_assessment.case_not_assessable'),
            ]);
        }

        $validated = $request->validate([
            'template_id'              => ['required', 'integer'],
            'answers'                  => ['required', 'array'],
            'answers.*.criterion_id'   => ['required', 'integer'],
            'answers.*.selected_value' => ['nullable', 'string', 'in:lav,middels,hoy'],
            'answers.*.comment'        => ['nullable', 'string', 'max:'.self::COMMENT_MAX_LENGTH],
        ], [
            // Keyed answers.{index}.comment: the page maps the index back to its criterion and
            // shows the message under that criterion's reasoning.
            'answers.*.comment.max'       => __('procynia.go_no_go_assessment.comment_too_long', ['max' => self::COMMENT_MAX_LENGTH]),
            'answers.*.comment.string'    => __('procynia.go_no_go_assessment.comment_invalid'),
            'answers.*.selected_value.in' => __('procynia.go_no_go_assessment.rating_invalid'),
        ]);

        // Verify template belongs to this customer
        $template = GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $customerId)
            ->whereKey($validated['template_id'])
            ->firstOrFail();

        // A deactivated template is retired: existing assessments keep it as history, but no
        // assessment is started or changed against it.
        if (! $template->is_active) {
            throw ValidationException::withMessages([
                'template_id' => __('procynia.go_no_go_assessment.template_inactive'),
            ]);
        }

        // Upsert assessment — template_id is locked at creation to preserve history
        $assessment = GoNoGoAssessment::query()->firstOrCreate(
            ['saved_notice_id' => $record->id, 'template_id' => $template->id],
            ['customer_id' => $customerId, 'created_by' => $user->id],
        );

        $assessment->forceFill(['updated_by' => $user->id])->save();

        // Build a fast lookup of valid criterion IDs for this template. A deactivated criterion is
        // not shown and does not count in the score, so it is not answerable either.
        $validCriterionIds = GoNoGoAssessmentCriterion::query()
            ->where('template_id', $template->id)
            ->where('is_active', true)
            ->pluck('id')
            ->flip()
            ->all();

        // Upsert / delete answers
        foreach ($validated['answers'] as $answerData) {
            $criterionId   = (int) $answerData['criterion_id'];
            $selectedValue = $answerData['selected_value'] ?? null;

            // Silently ignore answers for criteria outside this template or no longer active
            if (! array_key_exists($criterionId, $validCriterionIds)) {
                continue;
            }

            if ($selectedValue === null) {
                GoNoGoAssessmentAnswer::query()
                    ->where('assessment_id', $assessment->id)
                    ->where('criterion_id', $criterionId)
                    ->delete();

                continue;
            }

            $criterion = GoNoGoAssessmentCriterion::query()->find($criterionId);
            $score     = $criterion ? $criterion->computeScore($selectedValue) : 0;

            GoNoGoAssessmentAnswer::query()->updateOrCreate(
                ['assessment_id' => $assessment->id, 'criterion_id' => $criterionId],
                [
                    'selected_value' => $selectedValue,
                    'score'          => $score,
                    'comment'        => $answerData['comment'] ?? null,
                ],
            );
        }

        // Recompute summary from DB (authoritative)
        $this->recomputeSummary($assessment, $template);

        return back()->with('success', 'Vurderingen ble lagret.');
    }

    private function recomputeSummary(GoNoGoAssessment $assessment, GoNoGoAssessmentTemplate $template): void
    {
        $activeCriteria = GoNoGoAssessmentCriterion::query()
            ->where('template_id', $template->id)
            ->where('is_active', true)
            ->get();

        $answeredIds = GoNoGoAssessmentAnswer::query()
            ->where('assessment_id', $assessment->id)
            ->pluck('score', 'criterion_id');

        $maxScore   = $activeCriteria->sum(fn (GoNoGoAssessmentCriterion $c) => $c->weight * 3);
        $totalScore = 0;
        $allAnswered = true;

        foreach ($activeCriteria as $criterion) {
            if (! isset($answeredIds[$criterion->id])) {
                $allAnswered = false;
            } else {
                $totalScore += $answeredIds[$criterion->id];
            }
        }

        $recommendation = null;
        $completedAt    = null;

        if ($allAnswered && $maxScore > 0) {
            $pct            = ($totalScore / $maxScore) * 100;
            $recommendation = $pct >= 75 ? 'go' : ($pct >= 55 ? 'avklar' : 'nogo');
            $completedAt    = $assessment->completed_at ?? now();
        }

        $assessment->forceFill([
            'total_score'    => $totalScore,
            'max_score'      => $maxScore,
            'recommendation' => $recommendation,
            'completed_at'   => $completedAt,
        ])->save();
    }
}
