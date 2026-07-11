<?php

namespace App\Services\Ai\Wiki\Responses;

use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseEmptyException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseFailedException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseIncompleteException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseInvalidJsonException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseMalformedException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseRefusedException;
use Illuminate\Support\Facades\Log;
use JsonException;

class EnterpriseWikiResponsesDecoder
{
    /**
     * Validate and decode one Enterprise Wiki Responses API envelope.
     *
     * @return array<string, mixed>
     */
    public function decode(array $response, string $operation): array
    {
        $diagnostics = $this->diagnostics($response, $operation);

        try {
            $status = $response['status'] ?? null;

            if (! is_string($status) || trim($status) === '') {
                throw new EnterpriseWikiResponseMalformedException(
                    $this->message($operation, $response, 'response status was missing or malformed'),
                    $diagnostics,
                );
            }

            if ($status === 'incomplete') {
                $reason = $diagnostics['incomplete_reason'] ?? null;
                $suffix = is_string($reason) && $reason !== '' ? " Reason: {$reason}." : '';

                throw new EnterpriseWikiResponseIncompleteException(
                    $this->message($operation, $response, 'response was incomplete').$suffix,
                    $diagnostics,
                );
            }

            if ($status === 'failed') {
                throw new EnterpriseWikiResponseFailedException(
                    $this->message($operation, $response, 'response failed', true),
                    $diagnostics,
                );
            }

            if ($status !== 'completed') {
                throw new EnterpriseWikiResponseMalformedException(
                    $this->message($operation, $response, "response status [{$status}] is unknown"),
                    $diagnostics,
                );
            }

            $inspection = $this->inspectOutput($response);

            if ($inspection['has_refusal']) {
                throw new EnterpriseWikiResponseRefusedException(
                    $this->message($operation, $response, 'response contained a refusal'),
                    $diagnostics,
                );
            }

            $text = $inspection['text'];

            if ($text === '') {
                throw new EnterpriseWikiResponseEmptyException(
                    $this->message($operation, $response, 'response contained no output text'),
                    $diagnostics,
                );
            }

            try {
                $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new EnterpriseWikiResponseInvalidJsonException(
                    $this->message($operation, $response, 'response output was not valid JSON'),
                    $diagnostics,
                    $exception,
                );
            }

            if (! is_array($decoded)) {
                throw new EnterpriseWikiResponseInvalidJsonException(
                    $this->message($operation, $response, 'response JSON was not an object or array'),
                    $diagnostics,
                );
            }

            return $decoded;
        } catch (EnterpriseWikiResponseException $exception) {
            Log::warning('[PROCYNIA][ENTERPRISE_WIKI_RESPONSES] Response rejected.', $diagnostics);

            if ($exception->diagnostics === []) {
                throw new EnterpriseWikiResponseMalformedException(
                    $this->message($operation, $response, 'response envelope was malformed'),
                    $diagnostics,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function diagnostics(array $response, string $operation): array
    {
        $inspection = $this->inspectOutput($response, strict: false);

        return [
            'operation' => $operation,
            'response_id' => $this->safeScalar($response['id'] ?? null),
            'http_status' => $this->safeScalar(data_get($response, '_meta.http_status')),
            'response_status' => $this->safeScalar($response['status'] ?? null),
            'incomplete_reason' => $this->safeScalar(data_get($response, 'incomplete_details.reason')),
            'error_type' => $this->safeScalar(data_get($response, 'error.type')),
            'error_code' => $this->safeScalar(data_get($response, 'error.code')),
            'output_item_types' => $inspection['output_item_types'],
            'content_item_types' => $inspection['content_item_types'],
            'has_refusal' => $inspection['has_refusal'],
            'output_text_segments' => $inspection['segment_count'],
            'output_text_length' => mb_strlen($inspection['text'], 'UTF-8'),
            'input_tokens' => $this->safeInteger(data_get($response, 'usage.input_tokens')),
            'output_tokens' => $this->safeInteger(data_get($response, 'usage.output_tokens')),
            'reasoning_tokens' => $this->safeInteger(data_get($response, 'usage.output_tokens_details.reasoning_tokens')),
            'total_tokens' => $this->safeInteger(data_get($response, 'usage.total_tokens')),
        ];
    }

    /** @return array{text:string, segment_count:int, has_refusal:bool, output_item_types:list<string>, content_item_types:list<string>} */
    private function inspectOutput(array $response, bool $strict = true): array
    {
        $output = $response['output'] ?? null;
        $segments = [];
        $outputTypes = [];
        $contentTypes = [];
        $hasRefusal = false;

        if ($output !== null && ! is_array($output)) {
            if ($strict) {
                throw new EnterpriseWikiResponseMalformedException('Enterprise Wiki Responses: output was malformed.');
            }

            $output = [];
        }

        foreach ($output ?? [] as $item) {
            if (! is_array($item)) {
                if ($strict) {
                    throw new EnterpriseWikiResponseMalformedException('Enterprise Wiki Responses: output item was malformed.');
                }

                continue;
            }

            $type = $item['type'] ?? null;

            if (is_string($type) && $type !== '') {
                $outputTypes[] = $type;
            }

            if ($type !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                if ($strict) {
                    throw new EnterpriseWikiResponseMalformedException('Enterprise Wiki Responses: message content was malformed.');
                }

                continue;
            }

            foreach ($content as $contentItem) {
                if (! is_array($contentItem)) {
                    if ($strict) {
                        throw new EnterpriseWikiResponseMalformedException('Enterprise Wiki Responses: content item was malformed.');
                    }

                    continue;
                }

                $contentType = $contentItem['type'] ?? null;

                if (is_string($contentType) && $contentType !== '') {
                    $contentTypes[] = $contentType;
                }

                if ($contentType === 'refusal') {
                    $hasRefusal = true;
                }

                if ($contentType === 'output_text') {
                    $text = $contentItem['text'] ?? null;

                    if (! is_string($text)) {
                        if ($strict) {
                            throw new EnterpriseWikiResponseMalformedException('Enterprise Wiki Responses: output text was malformed.');
                        }

                        continue;
                    }

                    if (trim($text) !== '') {
                        $segments[] = $text;
                    }
                }
            }
        }

        if ($segments === []) {
            $topLevel = $response['output_text'] ?? null;

            if ($topLevel !== null && ! is_string($topLevel)) {
                if ($strict) {
                    throw new EnterpriseWikiResponseMalformedException('Enterprise Wiki Responses: top-level output text was malformed.');
                }
            } elseif (is_string($topLevel) && trim($topLevel) !== '') {
                $segments[] = $topLevel;
            }
        }

        return [
            'text' => trim(implode('', $segments)),
            'segment_count' => count($segments),
            'has_refusal' => $hasRefusal,
            'output_item_types' => array_values(array_unique($outputTypes)),
            'content_item_types' => array_values(array_unique($contentTypes)),
        ];
    }

    private function message(string $operation, array $response, string $detail, bool $includeError = false): string
    {
        $id = $this->safeScalar($response['id'] ?? null);
        $status = $this->safeScalar($response['status'] ?? null);
        $message = "{$operation}: OpenAI {$detail}.";

        if ($id !== null) {
            $message .= " Response ID: {$id}.";
        }

        if ($status !== null) {
            $message .= " Status: {$status}.";
        }

        if ($includeError) {
            $type = $this->safeScalar(data_get($response, 'error.type'));
            $code = $this->safeScalar(data_get($response, 'error.code'));

            if ($type !== null) {
                $message .= " Error type: {$type}.";
            }

            if ($code !== null) {
                $message .= " Error code: {$code}.";
            }
        }

        return $message;
    }

    private function safeScalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function safeInteger(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }
}
