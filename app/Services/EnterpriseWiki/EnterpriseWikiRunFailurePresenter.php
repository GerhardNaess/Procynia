<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;

/**
 * What a stopped Wiki run says to the people who use Kjøringer: a short, translated heading and
 * sentence, never the stored technical message. The stored message is kept untouched as the
 * diagnostics behind «Vis tekniske detaljer» — this only reads it.
 *
 * The stored text is classified only where its shape is certain. The page-generation failure is
 * written by exactly one place, FinalizeEnterpriseWikiPageGeneration::markRunFailed()
 * ("%d of %d <phase> page(s) failed to generate: … Details — <title> (<type>): <reason> | …"), and
 * a missing-evidence reason by exactly one exception, EnterpriseWikiPlannedSectionEvidenceMissingException.
 * Both formats are matched in full, so runs stored before any structured code existed (Wiki run 26)
 * read correctly without being rewritten. Anything else is reported with a neutral sentence — a raw
 * exception text is never the default view, and an unknown failure is never guessed at.
 *
 * The number of sections without evidence is only stated when every name the exception listed is a
 * planned topic in the run's own page plan: the exception joins names with ", ", so a name that
 * itself contains a comma cannot be counted from the text alone, and a message cut at the stored
 * length limit may have lost names. Either way the sentence then says "one or more" instead.
 */
class EnterpriseWikiRunFailurePresenter
{
    public const KIND_PLANNED_SECTION_EVIDENCE_MISSING = 'planned_section_evidence_missing';

    public const KIND_PAGE_GENERATION_FAILED = 'page_generation_failed';

    public const KIND_UNKNOWN = 'unknown';

    /** FinalizeEnterpriseWikiPageGeneration::markRunFailed() truncates error_message to this. */
    private const STORED_MESSAGE_LIMIT = 1000;

    private const PAGE_GENERATION_PATTERN = '/^(\d+) of (\d+) (initial|concept\/entity) page\(s\) failed to generate: .*? Details — (.+)$/su';

    private const PAGE_DETAIL_PATTERN = '/^(.+?) \(([a-z_]+)\): (.*)$/su';

    private const PLANNED_EVIDENCE_PATTERN = '/^\[EnterpriseWikiPlannedSectionEvidenceMissingException\] Run \[\d+\] page \[\d+\] \(([a-z_]+)\): planned_section_no_evidence before generation: (.+)\.$/su';

    private const PAGE_TYPES = ['article', 'summary', 'concept', 'entity'];

    /**
     * null when there is no failure to explain here: the run did not stop, or it stopped on a
     * transient planning failure, which Kjøringer already explains in its own words.
     *
     * @return array{kind: string, title: string, lines: list<string>, failed_phase: ?string}|null
     */
    public function present(EnterpriseWikiIngestRun $run): ?array
    {
        $isFailed = $run->status === EnterpriseWikiIngestRun::STATUS_FAILED;
        $isEscalated = $run->status === EnterpriseWikiIngestRun::STATUS_ESCALATED;

        if ($isFailed && $run->failed_phase === 'maintainer_decision' && $run->transient_failure) {
            return null;
        }

        $message = trim((string) $run->error_message);

        if ($isEscalated && $message === '') {
            return null;
        }

        if (! $isFailed && ! $isEscalated) {
            return null;
        }

        if ($message === '' && trim((string) $run->qa_last_error) === '') {
            return null;
        }

        return $this->classifyPageGenerationFailure($run, $message) ?? $this->unknown();
    }

    /**
     * @return array{kind: string, title: string, lines: list<string>, failed_phase: ?string}|null
     */
    private function classifyPageGenerationFailure(EnterpriseWikiIngestRun $run, string $message): ?array
    {
        if (preg_match(self::PAGE_GENERATION_PATTERN, $message, $match) !== 1) {
            return null;
        }

        $failedCount = (int) $match[1];
        $totalCount = (int) $match[2];
        // Both generation phases are the timeline's «Sider» step; the message is only written
        // once page generation has run, so every step before it had finished.
        $failedPhase = EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES;
        $mayBeTruncated = mb_strlen($message) >= self::STORED_MESSAGE_LIMIT;

        $pages = [];

        foreach (explode(' | ', $match[4]) as $detail) {
            if (preg_match(self::PAGE_DETAIL_PATTERN, trim($detail), $page) !== 1) {
                $pages[] = null;

                continue;
            }

            $pages[] = ['title' => $page[1], 'type' => $page[2], 'reason' => $page[3]];
        }

        $knownPages = array_values(array_filter($pages));

        if ($knownPages === [] || count($knownPages) !== count($pages)) {
            return $this->pageGenerationFailed($failedCount, $totalCount, [], $failedPhase);
        }

        $evidencePages = [];

        foreach ($knownPages as $index => $page) {
            if (preg_match(self::PLANNED_EVIDENCE_PATTERN, $page['reason'], $evidence) !== 1) {
                $evidencePages = [];

                break;
            }

            $isLast = $index === count($knownPages) - 1;
            $evidencePages[] = [
                'title' => $page['title'],
                'type' => $page['type'],
                'missing' => $mayBeTruncated && $isLast ? null : $this->countPlannedTopics($run, $evidence[2]),
            ];
        }

        if ($evidencePages === [] || count($evidencePages) !== $failedCount) {
            return $this->pageGenerationFailed($failedCount, $totalCount, array_column($knownPages, 'title'), $failedPhase);
        }

        return $this->plannedSectionEvidenceMissing($evidencePages, $failedPhase);
    }

    /**
     * @param  list<array{title: string, type: string, missing: ?int}>  $pages
     * @return array{kind: string, title: string, lines: list<string>, failed_phase: ?string}
     */
    private function plannedSectionEvidenceMissing(array $pages, string $failedPhase): array
    {
        $stopped = __('procynia.wiki.run_failure_evidence_stopped');

        if (count($pages) === 1) {
            $page = $pages[0];
            $type = in_array($page['type'], self::PAGE_TYPES, true) ? $page['type'] : 'page';

            return [
                'kind' => self::KIND_PLANNED_SECTION_EVIDENCE_MISSING,
                'title' => __('procynia.wiki.run_failure_evidence_title.'.$type),
                'lines' => [
                    __('procynia.wiki.run_failure_evidence_page', [
                        'page' => __('procynia.wiki.run_failure_page_noun.'.$type),
                        'title' => $page['title'],
                        'sections' => $this->sectionsPhrase($page['missing']),
                    ]),
                    $stopped,
                ],
                'failed_phase' => $failedPhase,
            ];
        }

        return [
            'kind' => self::KIND_PLANNED_SECTION_EVIDENCE_MISSING,
            'title' => __('procynia.wiki.run_failure_evidence_title.pages'),
            'lines' => [
                __('procynia.wiki.run_failure_evidence_pages', ['titles' => $this->quotedTitles(array_column($pages, 'title'))]),
                $stopped,
            ],
            'failed_phase' => $failedPhase,
        ];
    }

    /**
     * @param  list<string>  $titles
     * @return array{kind: string, title: string, lines: list<string>, failed_phase: ?string}
     */
    private function pageGenerationFailed(int $failedCount, int $totalCount, array $titles, string $failedPhase): array
    {

        return [
            'kind' => self::KIND_PAGE_GENERATION_FAILED,
            'title' => __('procynia.wiki.run_failure_pages_title'),
            'lines' => [
                $titles === []
                    ? __('procynia.wiki.run_failure_pages_summary', ['failed' => $failedCount, 'total' => $totalCount])
                    : __('procynia.wiki.run_failure_pages_summary_titles', ['failed' => $failedCount, 'total' => $totalCount, 'titles' => $this->quotedTitles($titles)]),
                __('procynia.wiki.run_failure_pages_stopped'),
            ],
            'failed_phase' => $failedPhase,
        ];
    }

    /**
     * @return array{kind: string, title: string, lines: list<string>, failed_phase: ?string}
     */
    private function unknown(): array
    {
        return [
            'kind' => self::KIND_UNKNOWN,
            'title' => __('procynia.wiki.run_failure_unknown_title'),
            'lines' => [__('procynia.wiki.run_failure_unknown_text')],
            'failed_phase' => null,
        ];
    }

    /**
     * How many planned sections the exception named, confirmed against the run's page plan: the
     * listed text must split into planned topic names exactly, rejoining pieces where a name
     * itself contains ", ". null when that cannot be confirmed.
     */
    private function countPlannedTopics(EnterpriseWikiIngestRun $run, string $listed): ?int
    {
        $known = $this->plannedTopicNames((array) ($run->maintainer_decision_json ?? []));

        if ($known === []) {
            return null;
        }

        $count = 0;
        $buffer = null;

        foreach (explode(', ', $listed) as $piece) {
            $buffer = $buffer === null ? $piece : $buffer.', '.$piece;

            if (isset($known[$buffer])) {
                $count++;
                $buffer = null;
            }
        }

        return $buffer === null && $count > 0 ? $count : null;
    }

    /**
     * Every topic name the maintainer decision planned, wherever it sits (article, summary,
     * concept and entity pages each carry `owned_topics`).
     *
     * @param  array<mixed>  $node
     * @return array<string, true>
     */
    private function plannedTopicNames(array $node): array
    {
        $names = [];

        foreach ($node as $key => $value) {
            if ($key === 'owned_topics' && is_array($value)) {
                foreach ($value as $topic) {
                    $name = is_array($topic) ? ($topic['topic'] ?? null) : $topic;

                    if (is_string($name) && trim($name) !== '') {
                        $names[trim($name)] = true;
                    }
                }
            } elseif (is_array($value)) {
                $names += $this->plannedTopicNames($value);
            }
        }

        return $names;
    }

    private function sectionsPhrase(?int $count): string
    {
        if ($count === null) {
            return __('procynia.wiki.run_failure_sections_unknown');
        }

        $words = (array) __('procynia.wiki.run_failure_count_words');

        return trans_choice('procynia.wiki.run_failure_sections', $count, ['number' => $words[$count] ?? (string) $count]);
    }

    /** @param list<string> $titles */
    private function quotedTitles(array $titles): string
    {
        return implode(', ', array_map(
            static fn (string $title): string => __('procynia.wiki.run_failure_quoted_title', ['title' => $title]),
            $titles,
        ));
    }
}
