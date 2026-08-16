<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidUtf8Exception;
use App\Services\EnterpriseWiki\EnterpriseWikiUtf8Guard;
use Tests\TestCase;

class EnterpriseWikiUtf8GuardTest extends TestCase
{
    public function test_it_reports_the_exact_nested_field_and_source_element_for_malformed_utf8(): void
    {
        try {
            (new EnterpriseWikiUtf8Guard)->assertValid([
                'planned_sections' => [
                    [],
                    [],
                    ['source_evidence' => [[
                        'source_element_key' => 'tbl-3-row-2',
                        'text' => "Gyldig \xB1 ugyldig",
                    ]]],
                ],
            ], 'enterprise_wiki_ai_request_input');
            $this->fail('Expected malformed UTF-8 to be rejected.');
        } catch (EnterpriseWikiInvalidUtf8Exception $exception) {
            $this->assertSame('enterprise_wiki_invalid_utf8: invalid UTF-8 at [planned_sections[2].source_evidence[0].text] in enterprise_wiki_ai_request_input.', $exception->getMessage());
            $this->assertSame('tbl-3-row-2', $exception->sourceElementKey);
            $this->assertSame(7, $exception->invalidByteOffset);
            $this->assertSame('47796c64696720b1207567796c646967', $exception->hexWindow);
        }
    }

    public function test_it_preserves_norwegian_unicode_and_document_control_labels(): void
    {
        $payload = [
            'source_elements' => [
                ['source_element_type' => 'paragraph', 'reference_text' => 'Ærlig øvelse med å, en–dash, em—dash, «anførselstegn» og ikke-brytende mellomrom.'],
                ['source_element_type' => 'list_item', 'reference_text' => 'Dokument-ID: FG-OPS-014'],
                ['source_element_type' => 'table_row', 'reference_text' => 'Versjon: 2.1 · Gyldig fra: 1. august · Formål: sikker drift'],
            ],
        ];

        (new EnterpriseWikiUtf8Guard)->assertValid($payload, 'enterprise_wiki_ai_request_input');

        $this->assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
