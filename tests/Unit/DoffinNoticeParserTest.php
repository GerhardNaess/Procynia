<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinNoticeParser;
use Tests\TestCase;

class DoffinNoticeParserTest extends TestCase
{
    public function test_it_parses_a_single_awarded_supplier_from_eform(): void
    {
        $parser = app(DoffinNoticeParser::class);

        $parsed = $parser->parse([
            'id' => '2026-105588',
            'noticeType' => 'ANNOUNCEMENT_OF_CONCLUSION_OF_CONTRACT',
            'heading' => 'Renholdstjenester',
            'publicationDate' => '2026-03-23',
            'issueDate' => '2026-03-20T11:45:45Z',
            'buyer' => [
                ['id' => '917641404', 'name' => 'Høgskulen på Vestlandet'],
            ],
            'allCpvCodes' => ['90911000', '90910000'],
            'placeOfPerformance' => ['Vestland'],
            'estimatedValue' => [
                'amount' => 88000000,
                'code' => 'NOK',
                'fullLocalizedText' => '88000000 NOK',
            ],
            'awardedNames' => ['4Service Eir Renhold AS'],
            'eform' => [
                [
                    'value' => 'ORG-0005',
                    'sections' => [
                        ['label' => 'Offisielt navn', 'value' => '4Service Eir Renhold AS'],
                        ['label' => 'Organisasjonsnummer', 'value' => '917 719 993'],
                        ['label' => 'Vinner av disse delkontraktene', 'value' => 'LOT-0000'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('2026-105588', $parsed['notice_id']);
        $this->assertSame(['Høgskulen på Vestlandet'], $parsed['buyer_names']);
        $this->assertSame(['917641404'], $parsed['buyer_ids']);
        $this->assertSame(['90911000', '90910000'], $parsed['cpv_codes']);
        $this->assertSame(['4Service Eir Renhold AS'], $parsed['awarded_names']);
        $this->assertSame([
            [
                'supplier_name' => '4Service Eir Renhold AS',
                'organization_number' => '917719993',
                'winner_lots' => ['LOT-0000'],
                'source' => 'eform',
            ],
        ], $parsed['suppliers']);
    }

    public function test_it_falls_back_to_awarded_names_for_missing_eform_winners(): void
    {
        $parser = app(DoffinNoticeParser::class);

        $parsed = $parser->parse([
            'id' => '2026-105909',
            'noticeType' => 'ANNOUNCEMENT_OF_CONCLUSION_OF_CONTRACT',
            'heading' => 'Rammeavtale for kjøp av vikartjenester - renholdspersonell',
            'publicationDate' => '2026-03-27',
            'buyer' => [
                ['id' => '971183675', 'name' => 'Oslo kommune v/ Utviklings- og kompetanseetaten'],
            ],
            'allCpvCodes' => ['79620000'],
            'placeOfPerformance' => ['Oslo'],
            'awardedNames' => ['Eterni Norge AS', '4Service Eir Renhold AS'],
            'eform' => [
                [
                    'value' => 'ORG-0001',
                    'sections' => [
                        ['label' => 'Offisielt navn', 'value' => 'Losing Bidder AS'],
                        ['label' => 'Organisasjonsnummer', 'value' => '999999999'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            [
                'supplier_name' => 'Eterni Norge AS',
                'organization_number' => null,
                'winner_lots' => [],
                'source' => 'awardedNames fallback',
            ],
            [
                'supplier_name' => '4Service Eir Renhold AS',
                'organization_number' => null,
                'winner_lots' => [],
                'source' => 'awardedNames fallback',
            ],
        ], $parsed['suppliers']);
    }

    public function test_it_keeps_multiple_winners_and_multiple_winner_lots(): void
    {
        $parser = app(DoffinNoticeParser::class);

        $parsed = $parser->parse([
            'id' => '2026-105909',
            'noticeType' => 'RESULT',
            'heading' => 'Renholdskontrakt',
            'publicationDate' => '2026-03-27',
            'buyer' => [
                ['id' => '971183675', 'name' => 'Oslo kommune'],
            ],
            'awardedNames' => ['Eterni Norge AS', '4Service Eir Renhold AS'],
            'eform' => [
                [
                    'value' => 'ORG-0005',
                    'sections' => [
                        ['label' => 'Offisielt navn', 'value' => '4Service Eir Renhold AS'],
                        ['label' => 'Organisasjonsnummer', 'value' => '917719993'],
                        ['label' => 'Vinner av disse delkontraktene', 'value' => 'LOT-0001'],
                        ['label' => 'Vinner av disse delkontraktene', 'value' => 'LOT-0002'],
                    ],
                ],
                [
                    'value' => 'ORG-0006',
                    'sections' => [
                        ['label' => 'Offisielt navn', 'value' => 'Eterni Norge AS'],
                        ['label' => 'Organisasjonsnummer', 'value' => '123456789'],
                        ['label' => 'Vinner av disse delkontraktene', 'value' => 'LOT-0003'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            [
                'supplier_name' => '4Service Eir Renhold AS',
                'organization_number' => '917719993',
                'winner_lots' => ['LOT-0001', 'LOT-0002'],
                'source' => 'eform',
            ],
            [
                'supplier_name' => 'Eterni Norge AS',
                'organization_number' => '123456789',
                'winner_lots' => ['LOT-0003'],
                'source' => 'eform',
            ],
        ], $parsed['suppliers']);
    }

    public function test_it_accepts_eform_suppliers_without_organization_number(): void
    {
        $parser = app(DoffinNoticeParser::class);

        $parsed = $parser->parse([
            'id' => '2026-105910',
            'noticeType' => 'RESULT',
            'heading' => 'Konsulenttjenester',
            'publicationDate' => '2026-03-28',
            'awardedNames' => ['Supplier Without Org'],
            'eform' => [
                [
                    'value' => 'ORG-0007',
                    'sections' => [
                        ['label' => 'Offisielt navn', 'value' => 'Supplier Without Org'],
                        ['label' => 'Vinner av disse delkontraktene', 'value' => 'LOT-0004'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            [
                'supplier_name' => 'Supplier Without Org',
                'organization_number' => null,
                'winner_lots' => ['LOT-0004'],
                'source' => 'eform',
            ],
        ], $parsed['suppliers']);
    }
}
