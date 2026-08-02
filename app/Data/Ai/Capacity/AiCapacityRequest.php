<?php

namespace App\Data\Ai\Capacity;

use JsonSerializable;

/**
 * Everything EnterpriseWikiAiCapacityPlanner needs to compute an output-token budget, built
 * only from facts already available before the AI call. Deliberately excludes a guessed
 * result-object count the caller cannot actually know yet (e.g. how many concept candidates a
 * maintainer decision will enumerate) — EnterpriseWikiAiCapacityPlanner derives that growth from
 * inputSizeChars instead, via the operation's configured profile.
 */
final readonly class AiCapacityRequest implements JsonSerializable
{
    public function __construct(
        public string $operationType,
        public string $model,
        public int $inputSizeChars,
        public int $expectedResultObjects,
        public int $retryAttempt = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_type' => $this->operationType,
            'model' => $this->model,
            'input_size_chars' => $this->inputSizeChars,
            'expected_result_objects' => $this->expectedResultObjects,
            'retry_attempt' => $this->retryAttempt,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
