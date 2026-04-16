<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNoticeAiDocument;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentSplitPlanner
{
    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Build a deterministic coarse split plan locally without calling OpenAI.
     * Inputs: The source AI document and an optional run id.
     * Returns: A structured array containing plan status and a locally generated split plan.
     * Side effects: Writes diagnostic logs.
     */
    public function plan(SavedNoticeAiDocument $document, ?string $runId = null): array
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $documentText = trim((string) $document->extracted_text);
        $model = 'deterministic_local_splitter';
        $promptVersion = 'deterministic_local_splitter.v2';

        Log::info('[PROCYNIA][DOC_SPLIT] Document split planning started.', [
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'document_title' => (string) $document->original_filename,
            'document_text_length' => mb_strlen($documentText, 'UTF-8'),
            'model' => $model,
            'prompt_version' => $promptVersion,
        ]);

        if ($documentText === '') {
            return $this->failedResult(
                document: $document,
                runId: $runId,
                model: $model,
                promptVersion: $promptVersion,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'invalid_request',
                errorMessage: 'Document extracted text is missing.',
            );
        }

        $parsed = $this->buildDeterministicPlan($documentText);

        $result = [
            'ok' => true,
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'request_id' => null,
            'response_id' => null,
            'status' => 200,
            'document_text_length' => mb_strlen($documentText, 'UTF-8'),
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'elapsed_ms' => $this->elapsedMs($startedAt),
            'raw_output' => null,
            'document_summary' => $parsed['document_summary'],
            'split_plan' => $parsed['split_plan'],
        ];

        Log::info('[PROCYNIA][DOC_SPLIT] Document split planning completed.', [
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'document_title' => (string) $document->original_filename,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'request_id' => null,
            'response_id' => null,
            'status' => 200,
            'document_text_length' => mb_strlen($documentText, 'UTF-8'),
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'elapsed_ms' => $result['elapsed_ms'],
            'document_summary' => $result['document_summary'],
            'split_plan_count' => count($result['split_plan']),
            'split_plan' => $result['split_plan'],
        ]);

        return $result;
    }

    /**
     * Purpose: Build a deterministic failure payload for document split planning.
     * Inputs: The source document and stable failure metadata.
     * Returns: A uniform failure array.
     * Side effects: Writes failure logs.
     */
    private function failedResult(
        SavedNoticeAiDocument $document,
        string $runId,
        string $model,
        string $promptVersion,
        ?int $elapsedMs = null,
        string $errorType = 'unexpected_error',
        string $errorMessage = 'Document split planning failed.',
    ): array {
        $result = [
            'ok' => false,
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'request_id' => null,
            'response_id' => null,
            'status' => null,
            'document_text_length' => mb_strlen(trim((string) $document->extracted_text), 'UTF-8'),
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'elapsed_ms' => $elapsedMs,
            'raw_output' => null,
            'document_summary' => null,
            'split_plan' => [],
            'error_type' => $errorType,
            'error_message' => $errorMessage,
        ];

        Log::warning('[PROCYNIA][DOC_SPLIT] Document split planning failed.', [
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'document_title' => (string) $document->original_filename,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'status' => null,
            'document_text_length' => $result['document_text_length'],
            'elapsed_ms' => $elapsedMs,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
        ]);

        return $result;
    }

    /**
     * Purpose: Build a coarse deterministic split plan with explicit character positions.
     * Inputs: The extracted document text.
     * Returns: A summary and a split plan with start/end positions and anchor previews.
     * Side effects: None.
     */
    private function buildDeterministicPlan(string $documentText): array
    {
        $documentLength = mb_strlen($documentText, 'UTF-8');
        $chunkCount = $this->determineChunkCount($documentLength);
        $boundaries = [0];

        for ($chunkIndex = 1; $chunkIndex < $chunkCount; $chunkIndex++) {
            $target = (int) floor(($documentLength * $chunkIndex) / $chunkCount);
            $min = max($boundaries[count($boundaries) - 1] + 1, $target - 2500);
            $max = min($documentLength, $target + 2500);
            $boundary = $this->findBestBoundary($documentText, $target, $min, $max);

            if ($boundary <= $boundaries[count($boundaries) - 1]) {
                $boundary = $target;
            }

            if ($boundary <= $boundaries[count($boundaries) - 1]) {
                $boundary = min($documentLength, $boundaries[count($boundaries) - 1] + max(1, (int) floor($documentLength / $chunkCount)));
            }

            $boundaries[] = $boundary;
        }

        $boundaries[] = $documentLength;
        $boundaries = $this->normalizeBoundaries($boundaries, $documentLength);

        $splitPlan = [];
        $finalChunkCount = count($boundaries) - 1;

        for ($i = 0; $i < $finalChunkCount; $i++) {
            $start = $boundaries[$i];
            $end = $boundaries[$i + 1];
            $content = trim(mb_substr($documentText, $start, $end - $start, 'UTF-8'));

            if ($content === '') {
                continue;
            }

            $titlePreview = $this->previewText($content, 100);
            $splitPlan[] = [
                'group_id' => sprintf('chunk_%03d', $i + 1),
                'group_type' => $i === 0 ? 'context_only' : 'requirements_section',
                'title' => sprintf('Chunk %d: %s', $i + 1, $titlePreview),
                'start_anchor' => $this->anchorPreviewFromOffset($documentText, $start),
                'end_anchor' => $this->anchorPreviewFromOffset($documentText, max($start, $end - 180)),
                'start_position' => $start,
                'end_position' => $end,
                'reason' => sprintf(
                    'Deterministic coarse chunk %d of %d. Boundary selected near character %d to keep large contiguous sections together and avoid splitting mid-block where possible.',
                    $i + 1,
                    $finalChunkCount,
                    $end
                ),
            ];
        }

        return [
            'document_summary' => [
                'document_type' => 'Deterministically chunked source document',
                'overall_assessment' => sprintf(
                    'The document was split locally into %d coarse chunk(s) based on text length with boundary adjustment to nearby natural breakpoints. This avoids fine-grained structural over-segmentation and keeps downstream processing stable.',
                    count($splitPlan)
                ),
            ],
            'split_plan' => $splitPlan,
        ];
    }

    /**
     * Purpose: Determine a small coarse chunk count from document length.
     * Inputs: Document length in characters.
     * Returns: A chunk count between 1 and 5.
     * Side effects: None.
     */
    private function determineChunkCount(int $documentLength): int
    {
        return match (true) {
            $documentLength <= 9000 => 1,
            $documentLength <= 18000 => 2,
            $documentLength <= 32000 => 3,
            $documentLength <= 50000 => 4,
            default => 5,
        };
    }

    /**
     * Purpose: Find the best nearby break position for a coarse chunk boundary.
     * Inputs: The document text, a target offset, and a search window.
     * Returns: A selected boundary offset.
     * Side effects: None.
     */
    private function findBestBoundary(string $documentText, int $target, int $min, int $max): int
    {
        $windowStart = max(0, $min);
        $windowLength = max(0, $max - $windowStart);
        $windowText = mb_substr($documentText, $windowStart, $windowLength, 'UTF-8');

        if ($windowText === '') {
            return $target;
        }

        $patterns = [
            '/(?:\r\n|\r|\n){2,}/u',
            '/(?:\r\n|\r|\n)(?=(?:Bilag\s+\d+|\d+\.\s|[A-ZÆØÅ][^\r\n]{0,120}(?::|$)))/u',
        ];

        $bestBoundary = null;
        $bestDistance = null;

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $windowText, $matches, PREG_OFFSET_CAPTURE) !== 1 && preg_match_all($pattern, $windowText, $matches, PREG_OFFSET_CAPTURE) !== 0) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $matchText = (string) ($match[0] ?? '');
                $byteOffset = (int) ($match[1] ?? 0);
                $charOffset = mb_strlen(substr($windowText, 0, $byteOffset), 'UTF-8');
                $boundary = $windowStart + $charOffset + mb_strlen($matchText, 'UTF-8');
                $distance = abs($boundary - $target);

                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestBoundary = $boundary;
                }
            }

            if ($bestBoundary !== null) {
                return $bestBoundary;
            }
        }

        return $target;
    }

    /**
     * Purpose: Normalize boundary positions into a strictly increasing list within the document length.
     * Inputs: Raw boundary positions and the full document length.
     * Returns: A strict ordered boundary list.
     * Side effects: None.
     */
    private function normalizeBoundaries(array $boundaries, int $documentLength): array
    {
        $normalized = [0];

        foreach ($boundaries as $boundary) {
            $position = max(0, min($documentLength, (int) $boundary));

            if ($position <= $normalized[count($normalized) - 1]) {
                continue;
            }

            $normalized[] = $position;
        }

        if ($normalized[count($normalized) - 1] !== $documentLength) {
            $normalized[] = $documentLength;
        }

        return $normalized;
    }

    /**
     * Purpose: Build a short anchor preview from a character offset in the original text.
     * Inputs: The full document text and the source offset.
     * Returns: A bounded preview string.
     * Side effects: None.
     */
    private function anchorPreviewFromOffset(string $documentText, int $offset, int $length = 180): string
    {
        $preview = mb_substr($documentText, max(0, $offset), $length, 'UTF-8');

        return trim($preview);
    }

    /**
     * Purpose: Build a bounded preview string for titles and logs.
     * Inputs: Raw text and an optional length cap.
     * Returns: A compact preview string.
     * Side effects: None.
     */
    private function previewText(string $text, int $limit = 100): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalized === '') {
            return '';
        }

        return Str::limit($normalized, $limit, '...');
    }

    /**
     * Purpose: Convert a microtime start value into a rounded millisecond duration.
     * Inputs: The float timestamp captured before the work started.
     * Returns: The elapsed duration in milliseconds.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
