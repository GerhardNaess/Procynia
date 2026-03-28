<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\NoticeDocument;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DoffinNoticeDocumentService
{
    public function syncFromNotice(Notice $notice): Collection
    {
        $notice->loadMissing('rawXml');

        $xml = $notice->rawXml?->xml_content;

        if (! is_string($xml) || trim($xml) === '') {
            return collect();
        }

        $documents = $this->parse($xml);

        DB::transaction(function () use ($notice, $documents): void {
            $existing = $notice->documents()->get()->keyBy('source_url');
            $currentUrls = [];

            foreach ($documents as $index => $document) {
                $currentUrls[] = $document['source_url'];

                $notice->documents()->updateOrCreate(
                    ['source_url' => $document['source_url']],
                    [
                        'title' => $document['title'],
                        'sort_order' => $index + 1,
                    ],
                );
            }

            if ($currentUrls !== []) {
                $notice->documents()
                    ->whereNotIn('source_url', $currentUrls)
                    ->delete();
            } else {
                $notice->documents()->delete();
            }
        });

        return $notice->documents()->orderBy('sort_order')->get();
    }

    public function parse(string $xml): array
    {
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET);

        if (! $loaded) {
            throw new RuntimeException('Stored notice XML is invalid and cannot be parsed for documents.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $nodes = $xpath->query('//cac:CallForTendersDocumentReference[cac:Attachment/cac:ExternalReference/cbc:URI]');

        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $documents = [];

        foreach ($nodes as $node) {
            $title = trim((string) $xpath->evaluate('string(cbc:ID)', $node));
            $sourceUrl = trim((string) $xpath->evaluate('string(cac:Attachment/cac:ExternalReference/cbc:URI)', $node));

            if ($sourceUrl === '') {
                continue;
            }

            $documents[$sourceUrl] = [
                'title' => $title !== '' ? $title : basename(parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl),
                'source_url' => $sourceUrl,
            ];
        }

        return array_values($documents);
    }
}
