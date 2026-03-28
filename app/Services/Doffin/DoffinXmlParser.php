<?php

namespace App\Services\Doffin;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Throwable;

class DoffinXmlParser
{
    public function parse(string $xml): array
    {
        $document = $this->loadDocument($xml);
        $xpath = $this->createXPath($document);
        $buyerOrganization = $this->resolveBuyerOrganization($xpath);

        $issueDate = $this->firstNonEmpty($xpath, [
            '(//cbc:IssueDate)[1]',
        ]);

        $issueTime = $this->firstNonEmpty($xpath, [
            '(//cbc:IssueTime)[1]',
        ]);

        $deadlineDate = $this->firstNonEmpty($xpath, [
            '(//cac:TenderSubmissionDeadlinePeriod/cbc:EndDate)[1]',
        ]);

        $deadlineTime = $this->firstNonEmpty($xpath, [
            '(//cac:TenderSubmissionDeadlinePeriod/cbc:EndTime)[1]',
        ]);

        return [
            'title' => $this->firstNonEmpty($xpath, [
                '(//cac:ProcurementProject/cbc:Name[@languageID="NOR"])[1]',
                '(//cac:ProcurementProject/cbc:Name)[1]',
            ]),
            'description' => $this->firstNonEmpty($xpath, [
                '(//cac:ProcurementProject/cbc:Description[@languageID="NOR"])[1]',
                '(//cac:ProcurementProject/cbc:Description)[1]',
            ]),
            'notice_type' => $this->firstNonEmpty($xpath, [
                '(//cbc:NoticeTypeCode)[1]',
            ]),
            'notice_subtype' => $this->firstNonEmpty($xpath, [
                '(//efac:NoticeSubType/cbc:SubTypeCode)[1]',
            ]),
            'status' => null,
            'publication_date' => $this->normalizeDate($issueDate, 'publication_date'),
            'issue_date' => $this->normalizeDateTime($issueDate, $issueTime, 'issue_date'),
            'deadline' => $this->normalizeDateTime($deadlineDate, $deadlineTime, 'deadline'),
            'buyer_name' => $buyerOrganization === null ? null : $this->firstNonEmpty($xpath, [
                '(./efac:Company/cac:PartyName[@languageID="NOR"]/cbc:Name)[1]',
                '(./efac:Company/cac:PartyName/cbc:Name)[1]',
            ], $buyerOrganization),
            'buyer_org_number' => $buyerOrganization === null ? null : $this->firstNonEmpty($xpath, [
                '(./efac:Company/cac:PartyLegalEntity/cbc:CompanyID)[1]',
            ], $buyerOrganization),
            'buyer_city' => $buyerOrganization === null ? null : $this->firstNonEmpty($xpath, [
                '(./efac:Company/cac:PostalAddress/cbc:CityName)[1]',
            ], $buyerOrganization),
            'contact_name' => $buyerOrganization === null ? null : $this->firstNonEmpty($xpath, [
                '(./efac:Company/cac:Contact/cbc:Name)[1]',
            ], $buyerOrganization),
            'contact_email' => $buyerOrganization === null ? null : $this->firstNonEmpty($xpath, [
                '(./efac:Company/cac:Contact/cbc:ElectronicMail)[1]',
            ], $buyerOrganization),
            'contact_phone' => $buyerOrganization === null ? null : $this->firstNonEmpty($xpath, [
                '(./efac:Company/cac:Contact/cbc:Telephone)[1]',
            ], $buyerOrganization),
        ];
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
        $xpath->registerNamespace('notice', 'urn:oasis:names:specification:ubl:schema:xsd:ContractNotice-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('efac', 'http://data.europa.eu/p27/eforms-ubl-extension-aggregate-components/1');
        $xpath->registerNamespace('efbc', 'http://data.europa.eu/p27/eforms-ubl-extension-basic-components/1');
        $xpath->registerNamespace('efext', 'http://data.europa.eu/p27/eforms-ubl-extensions/1');
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');

        return $xpath;
    }

    private function resolveBuyerOrganization(DOMXPath $xpath): ?DOMNode
    {
        $organizationReference = $this->firstNonEmpty($xpath, [
            '(//cac:ContractingParty/cac:Party/cac:PartyIdentification/cbc:ID)[1]',
        ]);

        if ($organizationReference !== null) {
            $organization = $this->firstNode(
                $xpath,
                sprintf(
                    '(//efac:Organizations/efac:Organization[./efac:Company/cac:PartyIdentification/cbc:ID=%s])[1]',
                    $this->toXPathLiteral($organizationReference),
                ),
            );

            if ($organization !== null) {
                return $organization;
            }
        }

        return $this->firstNode($xpath, '(//efac:Organizations/efac:Organization)[1]');
    }

    private function firstNode(DOMXPath $xpath, string $expression, ?DOMNode $contextNode = null): ?DOMNode
    {
        $nodes = $xpath->query($expression, $contextNode);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0);
    }

    private function firstNonEmpty(DOMXPath $xpath, array $expressions, ?DOMNode $contextNode = null): ?string
    {
        foreach ($expressions as $expression) {
            $nodes = $xpath->query($expression, $contextNode);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $value = $this->trimmedOrNull($node->textContent);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function normalizeDate(?string $date, string $field): ?string
    {
        if ($date === null) {
            return null;
        }

        return $this->normalizeDateTimeValue(
            preg_replace('/Z$/', '', $date).'T00:00:00+00:00',
            $field,
        );
    }

    private function normalizeDateTime(?string $date, ?string $time, string $field): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($time === null) {
            return $this->normalizeDate($date, $field);
        }

        return $this->normalizeDateTimeValue(
            preg_replace('/Z$/', '', $date).'T'.$time,
            $field,
        );
    }

    private function normalizeDateTimeValue(string $value, string $field): string
    {
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:sP');
        } catch (Throwable $throwable) {
            throw new RuntimeException("Unable to normalize {$field} value [{$value}].", previous: $throwable);
        }
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function toXPathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        if (! str_contains($value, '"')) {
            return "\"{$value}\"";
        }

        $parts = explode("'", $value);
        $quotedParts = array_map(
            static fn (string $part): string => "'{$part}'",
            $parts,
        );

        return 'concat('.implode(', "\'", ', $quotedParts).')';
    }
}
