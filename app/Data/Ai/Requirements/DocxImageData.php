<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

/**
 * One deterministically-parsed inline image from a DOCX document's body-level content flow (see
 * DocumentTextExtractor::extractDocxImages()). Fase 1 scope: images inside table cells, floating/
 * anchored images with complex wrapping, and images inside headers/footers are not extracted —
 * only ordinary `<w:p>` body paragraphs are scanned, and header1.xml/footer1.xml are separate
 * OOXML parts this parser never opens.
 *
 * `sourceImageKey` is the canonical, stable provenance identifier for this image — deterministic
 * from (document, image) position, so re-parsing the same document produces the same key, exactly
 * mirroring DocxTableRowData::$sourceRowKey. `withDocumentId()` stamps the final, document-scoped
 * key once the owning EnterpriseWikiDocument row exists, the same two-step pattern used for tables.
 *
 * `bytes` is the raw image content, held only long enough to be persisted by a storage service —
 * deliberately excluded from toArray()/jsonSerialize() so it can never end up serialized into a
 * queued job payload, a log line, or a JSON column.
 */
final readonly class DocxImageData implements JsonSerializable
{
    public function __construct(
        public string $sourceImageKey,
        public int $imageIndex,
        public int $documentOrder,
        public string $relationshipId,
        public string $originalMediaPath,
        public ?string $mimeType,
        public ?int $width,
        public ?int $height,
        public string $contentHash,
        public ?string $sectionNumber,
        public ?string $sectionTitle,
        public ?string $caption,
        public ?string $altText,
        public ?string $textBefore,
        public ?string $textAfter,
        public string $bytes,
    ) {}

    public function toArray(): array
    {
        return [
            'source_image_key' => $this->sourceImageKey,
            'image_index' => $this->imageIndex,
            'document_order' => $this->documentOrder,
            'relationship_id' => $this->relationshipId,
            'original_media_path' => $this->originalMediaPath,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'content_hash' => $this->contentHash,
            'section_number' => $this->sectionNumber,
            'section_title' => $this->sectionTitle,
            'caption' => $this->caption,
            'alt_text' => $this->altText,
            'text_before' => $this->textBefore,
            'text_after' => $this->textAfter,
            // 'bytes' is deliberately omitted — see class docblock.
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Stamps the final, document-scoped source_image_key once the owning document's DB id is
     * known — mirrors DocxTableData::withDocumentId().
     */
    public function withSourceImageKey(string $sourceImageKey): self
    {
        return new self(
            sourceImageKey: $sourceImageKey,
            imageIndex: $this->imageIndex,
            documentOrder: $this->documentOrder,
            relationshipId: $this->relationshipId,
            originalMediaPath: $this->originalMediaPath,
            mimeType: $this->mimeType,
            width: $this->width,
            height: $this->height,
            contentHash: $this->contentHash,
            sectionNumber: $this->sectionNumber,
            sectionTitle: $this->sectionTitle,
            caption: $this->caption,
            altText: $this->altText,
            textBefore: $this->textBefore,
            textAfter: $this->textAfter,
            bytes: $this->bytes,
        );
    }

    /**
     * @param  list<DocxImageData>  $images
     * @return list<DocxImageData>
     */
    public static function manyWithDocumentId(array $images, int $documentId): array
    {
        return array_map(
            static fn (self $image): self => $image->withSourceImageKey(sprintf('doc%d-img%d', $documentId, $image->imageIndex)),
            $images,
        );
    }
}
