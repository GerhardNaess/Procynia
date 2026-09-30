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
        public readonly ?OpportunityStatus $status,
        public readonly ?string $sourceUrl,
        public readonly array $cpvCodes = [],
        public readonly array $rawPayload = [],
        /**
         * Who this notice is, as opposed to where Procynia read it.
         *
         * Always present, so nothing has to guard against a null before asking; empty when the
         * register said nothing, which is a different answer from a wrong one. Nothing reads it
         * yet — see OpportunityNoticeIdentity for what the two identifiers mean and why the TED
         * publication number is not one of them.
         */
        public readonly OpportunityNoticeIdentity $identity = new OpportunityNoticeIdentity,
    ) {}

    /**
     * The same notice, said to be someone in particular.
     *
     * Identity can arrive later than the notice does — Doffin publishes the eForms identifiers on
     * a different endpoint than the one discovery reads — so there has to be a way to add it
     * without rebuilding a notice field by field at the call site, where a forgotten argument
     * would silently drop a title. Everything else is carried over unchanged; only who this is
     * changes, and only for the copy returned.
     */
    public function withIdentity(OpportunityNoticeIdentity $identity): self
    {
        return new self(
            sourceKey: $this->sourceKey,
            externalId: $this->externalId,
            title: $this->title,
            description: $this->description,
            buyerName: $this->buyerName,
            publicationDate: $this->publicationDate,
            deadline: $this->deadline,
            status: $this->status,
            sourceUrl: $this->sourceUrl,
            cpvCodes: $this->cpvCodes,
            rawPayload: $this->rawPayload,
            identity: $identity,
        );
    }

    /**
     * What the register called this notice's status, verbatim.
     *
     * Read from the raw hit rather than reconstructed from the enum, so an unrecognised status is
     * still shown to the user rather than disappearing because Procynia had no case for it.
     */
    public function providerStatusLabel(): ?string
    {
        $label = trim((string) ($this->rawPayload['status'] ?? ''));

        return $label === '' ? null : $label;
    }

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
            // The register's own word, not the enum's.
            //
            // The frontend prints this string straight into the status badge, so it is display
            // text rather than a value — serialising the enum instead would relabel every card
            // from ACTIVE to "open", which is a UI change and this phase has none. The meaning
            // travels typed in $status; the label stays where it has always come from, which is
            // the raw hit the register sent. Giving these labels a translated, source-neutral
            // presentation is a UI job, and belongs with the phase that does UI.
            'status' => $this->providerStatusLabel(),
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
