<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GoNoGoAssessment;
use App\Models\GoNoGoAssessmentAnswer;
use App\Models\GoNoGoAssessmentCriterion;
use App\Models\GoNoGoAssessmentTemplate;
use App\Models\SavedNotice;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoNoGoAssessmentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
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

        // Verify notice belongs to this customer
        $record = SavedNotice::query()
            ->where('customer_id', $customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $validated = $request->validate([
            'template_id'              => ['required', 'integer'],
            'answers'                  => ['required', 'array'],
            'answers.*.criterion_id'   => ['required', 'integer'],
            'answers.*.selected_value' => ['nullable', 'string', 'in:lav,middels,hoy'],
            'answers.*.comment'        => ['nullable', 'string', 'max:2000'],
        ]);

        // Verify template belongs to this customer
        $template = GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $customerId)
            ->whereKey($validated['template_id'])
            ->firstOrFail();

        // Upsert assessment — template_id is locked at creation to preserve history
        $assessment = GoNoGoAssessment::query()->firstOrCreate(
            ['saved_notice_id' => $record->id, 'template_id' => $template->id],
            ['customer_id' => $customerId, 'created_by' => $user->id],
        );

        $assessment->forceFill(['updated_by' => $user->id])->save();

        // Build a fast lookup of valid criterion IDs for this template
        $validCriterionIds = GoNoGoAssessmentCriterion::query()
            ->where('template_id', $template->id)
            ->pluck('id')
            ->flip()
            ->all();

        // Upsert / delete answers
        foreach ($validated['answers'] as $answerData) {
            $criterionId   = (int) $answerData['criterion_id'];
            $selectedValue = $answerData['selected_value'] ?? null;

            // Silently ignore answers for criteria outside this template
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
