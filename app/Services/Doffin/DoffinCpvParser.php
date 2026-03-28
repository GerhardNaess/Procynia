<?php

namespace App\Services\Doffin;

use DOMDocument;
use DOMXPath;
use RuntimeException;

class DoffinCpvParser
{
    public function parse(string $xml): array
    {
        $document = $this->loadDocument($xml);
        $xpath = $this->createXPath($document);

        $codes = [];

        foreach ($this->cpvExpressions() as $expression) {
            $nodes = $xpath->query($expression);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                $code = $this->normalizeCode($node->textContent);

                if ($code === null) {
                    continue;
                }

                if (! $this->looksLikeCpvCode($code)) {
                    continue;
                }

                if (! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
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

    private function cpvExpressions(): array
    {
        return [
            '//cac:MainCommodityClassification/cbc:ItemClassificationCode[@listName="cpv"]',
            '//cac:AdditionalCommodityClassification/cbc:ItemClassificationCode[@listName="cpv"]',
        ];
    }

    private function normalizeCode(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function looksLikeCpvCode(string $value): bool
    {
        return preg_match('/^\d{8}$/', $value) === 1;
    }
}
