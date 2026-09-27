<?php

namespace App\Services\OpportunitySources;

/**
 * The source-neutral notice shape Procynia needs from an external opportunity source today.
 */
class NormalizedNotice
{
    /**
     * @param  array<int, string>  $cpvCodes
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $externalId,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $buyerName,
        public readonly ?string $publicationDate,
        public readonly ?string $deadline,
        public readonly ?string $status,
        public readonly ?string $sourceUrl,
        public readonly array $cpvCodes = [],
        public readonly array $rawPayload = [],
    ) {}

    public function primaryCpvCode(): ?string
    {
        return $this->cpvCodes[0] ?? null;
    }

    /**
     * Preserve the existing frontend payload contract for discovery notice cards.
     *
     * @param  array<int, string>  $savedExternalIds
     * @param  array<int, string>  $archivedExternalIds
     * @return array<string, mixed>
     */
    public function toDiscoveryPayload(array $savedExternalIds = [], array $archivedExternalIds = []): array
    {
        return [
            'id' => $this->externalId,
            'notice_id' => $this->externalId,
            'title' => $this->title ?? '',
            'buyer_name' => $this->buyerName ?? '',
            'summary' => $this->description,
            'publication_date' => $this->publicationDate,
            'deadline' => $this->deadline,
            'status' => $this->status,
            'relevance_level' => null,
            'score' => null,
            'department' => null,
            'saved_search_name' => null,
            'cpv_code' => $this->primaryCpvCode(),
            'is_new' => false,
            'external_url' => $this->sourceUrl,
            'is_saved' => in_array($this->externalId, $savedExternalIds, true),
            'is_in_history' => in_array($this->externalId, $archivedExternalIds, true),
        ];
    }
}
