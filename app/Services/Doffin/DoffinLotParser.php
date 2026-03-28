<?php

namespace App\Services\Doffin;

use DOMDocument;
use DOMXPath;
use RuntimeException;

class DoffinLotParser
{
    public function parse(string $xml): array
    {
        $document = $this->loadDocument($xml);
        $xpath = $this->createXPath($document);
        $lots = [];

        $nodes = $xpath->query('//cac:ProcurementProjectLot');

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $lotNode) {
            $lot = [
                'lot_title' => $this->normalizeText(
                    $xpath->evaluate('string((./cac:ProcurementProject/cbc:Name)[1])', $lotNode)
                ),
                'lot_description' => $this->normalizeText(
                    $xpath->evaluate('string((./cac:ProcurementProject/cbc:Description)[1])', $lotNode)
                ),
            ];

            if ($lot['lot_title'] === null && $lot['lot_description'] === null) {
                continue;
            }

            $key = $this->lotKey($lot);

            if (! array_key_exists($key, $lots)) {
                $lots[$key] = $lot;
            }
        }

        return array_values($lots);
    }

    private function loadDocument(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw new RuntimeException('Doffin XML is empty.');
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($loaded) {
            return $document;
        }

        $message = 'Unknown XML parsing error.';

        if ($errors !== []) {
            $message = trim($errors[0]->message);
        }

        throw new RuntimeException("Invalid Doffin XML: {$message}");
    }

    private function createXPath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xpath;
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+$/u', '', $value);

        return $trimmed === null || $trimmed === '' ? null : $trimmed;
    }

    private function lotKey(array $lot): string
    {
        return json_encode([
            $lot['lot_title'],
            $lot['lot_description'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
