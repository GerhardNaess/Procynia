<?php

namespace App\Services\Quality;

use App\Models\QualityFlowClarificationResolution;
use App\Models\QualityItem;
use App\Models\User;

/**
 * What the user has already settled about a process, and how long that holds.
 *
 * TWO OUTCOMES, ONE RULE.
 *
 * "Avvis" and "Avklar" are different answers — the first says the term is left to judgement, the
 * second says what it means — and they are recorded as different things, because a kvalitetsleder
 * reading this table later is entitled to know which of them happened. What they share is the only
 * thing this class does with them: a question the user has settled is not put in front of them
 * again while the description they settled it against still stands.
 *
 * WHY THIS IS A DETERMINISTIC FILTER AND NOT A LINE IN THE PROMPT.
 *
 * The alternative — telling the model "do not ask these three things again" — is a request it
 * usually honours and occasionally rephrases its way around, which is the worst of both: the
 * suggestion comes back wearing different words, the user's answer looks ignored, and there is
 * nothing to point at. Here the model is left to notice whatever it notices, and what the user
 * already said is applied afterwards, by comparison, every time. It also costs nothing: no prompt
 * grows, and a row written before this feature existed behaves the same as one written today.
 *
 * WHY AN ANSWER IS RECORDED AT ALL, WHEN IT CHANGES THE DESCRIPTION.
 *
 * Because a rewrite that defines a term is not a guarantee that the next reading stops asking about
 * it. The model may word the definition in a way it does not recognise as one on the way back in,
 * or notice the same undefined word somewhere else in the text, and the user — who answered the
 * question a moment ago and watched their description change — sees only that Procynia asked twice.
 * The rewrite is what makes the description true; this record is what makes the suggestion stay
 * gone. Leaving it to the rewrite alone was the earlier behaviour, and it is the bug.
 *
 * WHY A RESOLUTION CAN LAPSE.
 *
 * "Vi definerer ikke kritisk leverandør", or a definition of what one is, are answers about a
 * process as it stands. Describe the process again from scratch — different work, different roles,
 * different decisions — and the old answer is no longer about anything the user wrote, so the
 * question is worth asking once more. The test is deliberately blunt: how much of the wording the
 * resolution was recorded against survives in the description being read now. Adding a sentence,
 * fixing a role, answering another clarification — all of those keep most of the text, so
 * resolutions stand. Replacing the description is what clears them.
 *
 * Nothing here reads a description as process content. It is compared, never interpreted.
 */
class QualityFlowClarificationService
{
    /**
     * How much of the settled-against wording has to survive for the resolution to survive with it.
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
        $this->record(
            $item,
            $question,
            $description,
            $user,
            QualityFlowClarificationResolution::OUTCOME_DISMISSED,
        );
    }

    /**
     * Record that the user answered this one, and what the description said once they had.
     *
     * The description given here is the revised text — the one the answer is part of. That is what
     * makes the lapse rule mean the right thing afterwards: refining the revised description keeps
     * the answer, and describing the process again from scratch retires it along with every other
     * resolution about work nobody is doing any more.
     *
     * A later dismissal of the same question overwrites this, and a later answer overwrites a
     * dismissal. One row per question per process, holding the user's most recent word on it.
     */
    public function resolve(QualityItem $item, string $question, ?string $description, ?User $user): void
    {
        $this->record(
            $item,
            $question,
            $description,
            $user,
            QualityFlowClarificationResolution::OUTCOME_ANSWERED,
        );
    }

    /**
     * The suggestions worth putting in front of the user, given what they have already settled.
     *
     * Lapsed resolutions are deleted here rather than ignored. They are about a description that no
     * longer exists, so leaving them would mean a row that silently suppresses a future question
     * about a future process — and the delete is what makes "answers clear when you describe the
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

        $resolutions = QualityFlowClarificationResolution::query()
            ->where('quality_item_id', (int) $item->id)
            ->get();

        if ($resolutions->isEmpty()) {
            return array_values($questions);
        }

        $lapsed = [];
        $stands = [];

        foreach ($resolutions as $resolution) {
            if ($this->stillCovers((string) $resolution->description, $description)) {
                $stands[(string) $resolution->question_key] = true;

                continue;
            }

            $lapsed[] = (int) $resolution->id;
        }

        if ($lapsed !== []) {
            QualityFlowClarificationResolution::query()->whereIn('id', $lapsed)->delete();
        }

        return array_values(array_filter(
            $questions,
            fn (string $question): bool => ! isset($stands[$this->key($question)]),
        ));
    }

    /**
     * What the user has already settled about this process, and still stands.
     *
     * The same comparison `remaining()` makes, asked the other way round and with nothing deleted:
     * this is a read, and a read must not retire a resolution as a side effect of somebody drafting
     * an article. A lapsed row is simply not returned; `remaining()` is still the one that clears it.
     *
     * Why an article draft is given these at all: they are the two things the user has said about
     * their own process that the description cannot say on its own. "Avklar" means the answer is
     * woven into the description, so the draft must not ask the question again; "Avvis" means the
     * term is deliberately left to judgement, so the draft must not invent a definition for it.
     *
     * @return list<array{question: string, outcome: string}>
     */
    public function settled(QualityItem $item, ?string $description): array
    {
        $now = $this->text($description) ?? '';

        return QualityFlowClarificationResolution::query()
            ->where('quality_item_id', (int) $item->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (QualityFlowClarificationResolution $resolution): bool => $this->stillCovers(
                (string) $resolution->description,
                $now,
            ))
            ->map(static fn (QualityFlowClarificationResolution $resolution): array => [
                'question' => (string) $resolution->question,
                'outcome' => (string) $resolution->outcome,
            ])
            ->values()
            ->all();
    }

    /**
     * The form a question is matched on: casing, punctuation and spacing removed.
     *
     * A model asked the same thing twice will not spell it identically, and a resolution that only
     * survives an exact string match is a resolution the user watches fail.
     */
    public function key(string $question): string
    {
        return hash('sha256', implode(' ', $this->words($question)));
    }

    /** The one writer. Which outcome it was is the only thing that differs. */
    private function record(
        QualityItem $item,
        string $question,
        ?string $description,
        ?User $user,
        string $outcome,
    ): void {
        $question = trim($question);

        if ($question === '') {
            return;
        }

        QualityFlowClarificationResolution::query()->updateOrCreate(
            [
                'quality_item_id' => (int) $item->id,
                'question_key' => $this->key($question),
            ],
            [
                'customer_id' => (int) $item->customer_id,
                'question' => $question,
                'outcome' => $outcome,
                'description' => $this->text($description),
                'resolved_by_user_id' => $user?->id,
            ],
        );
    }

    /**
     * Is the description this was settled against still substantially the description being read?
     */
    private function stillCovers(string $settledAgainst, string $now): bool
    {
        $before = array_unique($this->words($settledAgainst));
        $after = array_unique($this->words($now));

        // Nothing to compare against — an older row, or a resolution recorded before a description
        // was known. Honour it: the user settled this, and guessing that they have changed their
        // mind is worse than keeping a suggestion hidden.
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
