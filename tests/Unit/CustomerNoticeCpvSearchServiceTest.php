<?php

namespace Tests\Unit;

use App\Services\Cpv\CustomerNoticeCpvSearchService;
use Tests\TestCase;

class CustomerNoticeCpvSearchServiceTest extends TestCase
{
    public function test_it_finds_cpv_values_from_plain_language_queries(): void
    {
        $service = new CustomerNoticeCpvSearchService();

        $results = $service->search('renhold');

        $this->assertNotEmpty($results);
        $this->assertSame('90910000', $results[0]['code']);
        $this->assertSame('Rengjøring', $results[0]['label']);
    }

    public function test_it_prioritizes_explicit_synonyms(): void
    {
        $service = new CustomerNoticeCpvSearchService();

        $results = $service->search('it drift');

        $this->assertNotEmpty($results);
        $this->assertSame('72500000', $results[0]['code']);
    }

    public function test_it_resolves_selected_codes_to_display_entries(): void
    {
        $service = new CustomerNoticeCpvSearchService();

        $selected = $service->selectedFromFilter('90910000,72222300');

        $this->assertSame([
            ['code' => '90910000', 'label' => 'Rengjøring'],
            ['code' => '72222300', 'label' => 'IT-tjenester'],
        ], $selected);
    }
}
