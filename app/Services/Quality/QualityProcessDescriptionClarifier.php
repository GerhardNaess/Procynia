<?php

namespace App\Services\Quality;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\QualityItem;
use App\Services\Ai\Quality\ProcessDescriptionClarificationAiClient;
use App\Services\Quality\Exceptions\ProcessFlowInterpretationException;
use App\Support\Ai\AiCallContextScope;
use Throwable;

/**
 * An answer to one clarification, turned into the description it was an answer about.
 *
 *   description + question + answer -> ProcessDescriptionClarificationAiClient -> this check
 *
 * WHY THE RESULT IS CHECKED AND NOT JUST RETURNED.
 *
 * The prompt asks the model not to write the question into the description. Asking is enough most
 * of the time, and most of the time is the wrong standard here: the question appearing in the text
 * is precisely the failure this whole change exists to remove, and a user who sees it once has no
 * reason to trust the feature again. So the one promise is kept by comparison rather than by
 * instruction — the question's own words must not survive, contiguously, in what comes back.
 *
 * Checked, never repaired. Stripping the offending sentence would mean editing the user's process
 * description on a guess about which words were theirs, and a description quietly mangled is worse
 * than one left alone. A failed rewrite changes nothing: the description stands as the user wrote
 * it, the clarification stays on screen, and they can answer again in different words.
 *
 * NOTHING HERE IS STORED.
 *
 * Same reason as QualityProcessFlowInterpreter: the revised description is shown before it is
 * adopted. It becomes the process's description when the user adopts the flow built from it, and
 * not a moment earlier.
 */
class QualityProcessDescriptionClarifier
{
    public function __construct(
        private readonly ProcessDescriptionClarificationAiClient $client,
        private readonly AiCallContextScope $contextScope,
    ) {}

    /**
     * @throws ProcessFlowInterpretationException
     */
    public function integrate(
        QualityItem $item,
        string $description,
        string $question,
        string $answer,
        string $languageCode,
    ): string {
        return $this->contextScope->within(
            new AiCallContext(
                customerId: (int) $item->customer_id,
                feature: 'quality',
                operation: 'process_flow_clarification',
                resourceType: 'quality_item',
                resourceId: (int) $item->id,
            ),
            fn (): string => $this->run($item, trim($description), trim($question), trim($answer), $languageCode),
        );
    }

    private function run(QualityItem $item, string $description, string $question, string $answer, string $languageCode): string
    {
        try {
            $rewritten = $this->client->integrate(
                (string) $item->title,
                $description,
                $question,
                $answer,
                $languageCode,
            );
        } catch (AiCostControlException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ProcessFlowInterpretationException::unavailable($exception);
        }

        $rewritten = trim($rewritten);

        if (! $this->isUsable($rewritten, $description, $question)) {
            throw ProcessFlowInterpretationException::clarificationNotIntegrated();
        }

        return $rewritten;
    }

    /**
     * Three things have to be true of a rewrite before the user is shown it.
     *
     * It must fit the limit the interpret endpoint validates on — a description that cannot be read
     * afterwards is not a description the user can do anything with.
     *
     * It must have changed. An answer that left the text exactly as it was means the answer did not
     * land, and re-reading an identical description would remove the clarification from the screen
     * while changing nothing about why it was raised.
     *
     * And it must not contain the question. See the class note.
     */
    private function isUsable(string $rewritten, string $description, string $question): bool
    {
        if ($rewritten === '' || mb_strlen($rewritten) > ProcessDescriptionClarificationAiClient::MAX_DESCRIPTION_LENGTH) {
            return false;
        }

        if ($this->normalise($rewritten) === $this->normalise($description)) {
            return false;
        }

        return ! $this->echoesQuestion($rewritten, $question);
    }

    /**
     * Did the question's own wording survive into the description?
     *
     * Matched on the normalised word sequence rather than the raw string, because a model that
     * drops the question mark, changes the capitalisation or swaps the punctuation has still put
     * the question in the text. Contiguity is what keeps it honest: "kritisk" and "leverandør"
     * appearing separately is the description doing its job, whereas the run of words that makes up
     * the question appearing intact is the transcript this feature refuses to produce.
     *
     * A question of one or two words is not matched at all — the sequence would be too short to
     * distinguish an echo from the ordinary vocabulary of the process, and a false refusal costs
     * the user an answer they gave in good faith.
     */
    private function echoesQuestion(string $rewritten, string $question): bool
    {
        $needle = $this->normalise($question);

        if ($needle === '' || count(explode(' ', $needle)) < 3) {
            return false;
        }

        return str_contains(' '.$this->normalise($rewritten).' ', ' '.$needle.' ');
    }

    /** Casing, punctuation and spacing removed, so what is compared is the words. */
    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }
}
