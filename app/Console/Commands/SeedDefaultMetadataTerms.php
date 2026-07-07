<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\KnowledgeMetadataTerm;
use Illuminate\Console\Command;

class SeedDefaultMetadataTerms extends Command
{
    protected $signature = 'knowledge:seed-default-metadata-terms
                            {--customer-id= : Seed only this customer ID (optional)}';

    protected $description = 'Seed default service_product_tag and theme_tag vocabulary terms for active customers. Idempotent.';

    /** @var array<string, list<string>> */
    private const TERMS = [
        KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG => [
            'Service Desk',
            'Drift',
            'Sikkerhet',
            'Nettverk',
            'Skyplattform',
            'Applikasjonsforvaltning',
        ],
        KnowledgeMetadataTerm::TYPE_THEME_TAG => [
            'SLA',
            'Support',
            'Beredskap',
            'Tilgjengelighet',
            'Kompetanse',
            'Sikkerhet og compliance',
        ],
    ];

    public function handle(): int
    {
        $customerId = $this->option('customer-id');

        $query = Customer::query()->where('is_active', true);

        if ($customerId !== null) {
            $query->where('id', (int) $customerId);
        }

        $customers = $query->get();

        if ($customers->isEmpty()) {
            $this->line('[KNOWLEDGE][METADATA_TERMS] No active customers found.');

            return self::SUCCESS;
        }

        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($customers as $customer) {
            [$created, $skipped] = $this->seedForCustomer($customer);
            $totalCreated += $created;
            $totalSkipped += $skipped;
        }

        $this->line(sprintf(
            '[KNOWLEDGE][METADATA_TERMS] customers=%d terms_created=%d terms_skipped=%d',
            $customers->count(),
            $totalCreated,
            $totalSkipped,
        ));

        return self::SUCCESS;
    }

    /** @return array{int, int} [created, skipped] */
    private function seedForCustomer(Customer $customer): array
    {
        $created = 0;
        $skipped = 0;

        foreach (self::TERMS as $type => $names) {
            foreach ($names as $name) {
                $exists = KnowledgeMetadataTerm::query()
                    ->where('customer_id', $customer->id)
                    ->where('type', $type)
                    ->whereRaw('LOWER(canonical_name) = LOWER(?)', [$name])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                KnowledgeMetadataTerm::query()->create([
                    'customer_id' => $customer->id,
                    'type' => $type,
                    'canonical_name' => $name,
                    'synonyms' => null,
                    'description' => null,
                    'approved' => true,
                ]);

                $created++;
            }
        }

        return [$created, $skipped];
    }
}
