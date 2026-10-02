<?php

namespace App\Services\Quality;

use App\Models\QualityFlowClarificationDismissal;
use App\Models\QualityItem;
use App\Models\User;

/**
 * What the user has already said no to, and how long that answer holds.
 *
 * WHY THIS IS A DETERMINISTIC FILTER AND NOT A LINE IN THE PROMPT.
 *
 * The alternative — telling the model "do not ask these three things again" — is a request it
 * usually honours and occasionally rephrases its way around, which is the worst of both: the
 * suggestion comes back wearing different words, the dismissal looks broken, and there is nothing
 * to point at. Here the model is left to notice whatever it notices, and the answer the user
 * already gave is applied afterwards, by comparison, every time. It also costs nothing: no prompt
 * grows, and a dismissal made before this feature existed behaves the same as one made today.
 *
 * WHY A DISMISSAL CAN LAPSE.
 *
 * "Vi definerer ikke kritisk leverandør" is an answer about a process as it stands. Describe the
 * process again from scratch — different work, different roles, different decisions — and the old
 * answer is no longer about anything the user wrote, so the question is worth asking once more.
 * The test is deliberately blunt: how much of the wording the dismissal was made against survives
 * in the description being read now. Adding a sentence, fixing a role, answering another
 * clarification — all of those keep most of the text, so dismissals stand. Replacing the
 * description is what clears them.
 *
 * Nothing here reads a description as process content. It is compared, never interpreted.
 */
class QualityFlowClarificationService
{
    /**
     * How much of the dismissed-against wording has to survive for the dismissal to survive with it.
     *
     * Shared vocabulary over total vocabulary, so it is symmetric: a description half rewritten and
     * one with as much again appended both fall through it. Half is where "I refined what I wrote"
     * stops and "I described something else" begins — high enough that a rewritten paragraph clears
     * the old answers, low enough that ordinary editing does not nag the user all over again.
     */
    private const SURVIVES_ABOVE = 0.5;

    /**
     * Record that the user does not want to be asked this again.
     *
     * Idempotent on the question: dismissing the same suggestion twice refreshes which description
     * it was dismissed against rather than stacking rows, because the second no is about the text
     * as it stands now.
     */
    public function dismiss(QualityItem $item, string $question, ?string $description, ?User $user): void
    {
        $question = trim($question);

        if ($question === '') {
            return;
        }

        QualityFlowClarificationDismissal::query()->updateOrCreate(
            [
                'quality_item_id' => (int) $item->id,
                'question_key' => $this->key($question),
            ],
            [
                'customer_id' => (int) $item->customer_id,
                'question' => $question,
                'description' => $this->text($description),
                'dismissed_by_user_id' => $user?->id,
            ],
        );
    }

    /**
     * The suggestions worth putting in front of the user, given what they have already turned down.
     *
     * Lapsed dismissals are deleted here rather than ignored. They are about a description that no
     * longer exists, so leaving them would mean a row that silently suppresses a future question
     * about a future process — and the delete is what makes "dismissals clear when you describe the
     * process again" true rather than approximately true.
     *
     * @param  list<string>  $questions
     * @return list<string>
     */
    public function remaining(QualityItem $item, string $description, array $questions): array
    {
        if ($questions === []) {
            return [];
        }

        $dismissals = QualityFlowClarificationDismissal::query()
            ->where('quality_item_id', (int) $item->id)
            ->get();

        if ($dismissals->isEmpty()) {
            return array_values($questions);
        }

        $lapsed = [];
        $stands = [];

        foreach ($dismissals as $dismissal) {
            if ($this->stillCovers((string) $dismissal->description, $description)) {
                $stands[(string) $dismissal->question_key] = true;

                continue;
            }

            $lapsed[] = (int) $dismissal->id;
        }

        if ($lapsed !== []) {
            QualityFlowClarificationDismissal::query()->whereIn('id', $lapsed)->delete();
        }

        return array_values(array_filter(
            $questions,
            fn (string $question): bool => ! isset($stands[$this->key($question)]),
        ));
    }

    /**
     * The form a question is matched on: casing, punctuation and spacing removed.
     *
     * A model asked the same thing twice will not spell it identically, and a dismissal that only
     * survives an exact string match is a dismissal the user watches fail.
     */
    public function key(string $question): string
    {
        return hash('sha256', implode(' ', $this->words($question)));
    }

    /**
     * Is the description this was dismissed against still substantially the description being read?
     */
    private function stillCovers(string $dismissedAgainst, string $now): bool
    {
        $before = array_unique($this->words($dismissedAgainst));
        $after = array_unique($this->words($now));

        // Nothing to compare against — an older row, or a dismissal made before a description was
        // known. Honour it: the user said no, and guessing that they have changed their mind is
        // worse than keeping a suggestion hidden.
        if ($before === [] || $after === []) {
            return true;
        }

        $shared = count(array_intersect($before, $after));
        $total = count(array_unique(array_merge($before, $after)));

        return $total > 0 && ($shared / $total) > self::SURVIVES_ABOVE;
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $normalised = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));

        return $normalised === '' ? [] : explode(' ', $normalised);
    }

    private function text(?string $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
