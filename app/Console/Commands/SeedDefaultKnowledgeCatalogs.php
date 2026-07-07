<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\KnowledgeDocumentCategory;
use App\Models\KnowledgeDocumentTopic;
use Illuminate\Console\Command;

class SeedDefaultKnowledgeCatalogs extends Command
{
    protected $signature = 'knowledge:seed-default-catalogs
                            {--customer-id= : Seed only this customer ID (optional)}';

    protected $description = 'Seed default document categories and topics for active customers. Idempotent.';

    /** @var list<array{name: string, sort_order: int, topics: list<string>}> */
    private const CATALOG = [
        [
            'name' => 'Generelt',
            'sort_order' => 0,
            'topics' => ['Intern bruk', 'Maler og verktøy'],
        ],
        [
            'name' => 'Avtaler og kontrakter',
            'sort_order' => 10,
            'topics' => ['Rammeavtaler', 'Vedlikeholdsavtaler', 'Tjenesteavtaler'],
        ],
        [
            'name' => 'Tjenestebeskrivelser',
            'sort_order' => 20,
            'topics' => ['Produktblad', 'Leveransebeskrivelser', 'SLA'],
        ],
        [
            'name' => 'Sikkerhet og compliance',
            'sort_order' => 30,
            'topics' => ['Sikkerhetspolicy', 'Sertifiseringer', 'GDPR og personvern'],
        ],
        [
            'name' => 'Drift og support',
            'sort_order' => 40,
            'topics' => ['Supportbeskrivelser', 'Driftsrutiner', 'Beredskap'],
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
            $this->line('[KNOWLEDGE][CATALOG] No active customers found.');

            return self::SUCCESS;
        }

        $totalCatCreated = 0;
        $totalCatSkipped = 0;
        $totalTopicCreated = 0;
        $totalTopicSkipped = 0;
        $totalPivotLinked = 0;

        foreach ($customers as $customer) {
            [$catCreated, $catSkipped, $topicCreated, $topicSkipped, $pivotLinked] = $this->seedForCustomer($customer);

            $totalCatCreated += $catCreated;
            $totalCatSkipped += $catSkipped;
            $totalTopicCreated += $topicCreated;
            $totalTopicSkipped += $topicSkipped;
            $totalPivotLinked += $pivotLinked;
        }

        $this->line(sprintf(
            '[KNOWLEDGE][CATALOG] customers=%d categories_created=%d categories_skipped=%d topics_created=%d topics_skipped=%d pivot_linked=%d',
            $customers->count(),
            $totalCatCreated,
            $totalCatSkipped,
            $totalTopicCreated,
            $totalTopicSkipped,
            $totalPivotLinked,
        ));

        return self::SUCCESS;
    }

    /** @return array{int, int, int, int, int} [cat_created, cat_skipped, topic_created, topic_skipped, pivot_linked] */
    private function seedForCustomer(Customer $customer): array
    {
        $catCreated = 0;
        $catSkipped = 0;
        $topicCreated = 0;
        $topicSkipped = 0;
        $pivotLinked = 0;

        foreach (self::CATALOG as $entry) {
            $category = KnowledgeDocumentCategory::query()
                ->where('customer_id', $customer->id)
                ->whereRaw('LOWER(name) = LOWER(?)', [$entry['name']])
                ->whereNull('deleted_at')
                ->first();

            if ($category !== null) {
                $catSkipped++;
            } else {
                $category = KnowledgeDocumentCategory::query()->create([
                    'customer_id' => $customer->id,
                    'name' => $entry['name'],
                    'sort_order' => $entry['sort_order'],
                    'is_active' => true,
                ]);
                $catCreated++;
            }

            $topicIds = [];

            foreach ($entry['topics'] as $topicName) {
                $topic = KnowledgeDocumentTopic::query()
                    ->where('customer_id', $customer->id)
                    ->whereRaw('LOWER(name) = LOWER(?)', [$topicName])
                    ->whereNull('deleted_at')
                    ->first();

                if ($topic !== null) {
                    $topicSkipped++;
                } else {
                    $topic = KnowledgeDocumentTopic::query()->create([
                        'customer_id' => $customer->id,
                        'name' => $topicName,
                        'sort_order' => 0,
                        'is_active' => true,
                    ]);
                    $topicCreated++;
                }

                $topicIds[] = $topic->id;
            }

            $syncResult = $category->topics()->syncWithoutDetaching($topicIds);
            $pivotLinked += count($syncResult['attached']);
        }

        return [$catCreated, $catSkipped, $topicCreated, $topicSkipped, $pivotLinked];
    }
}
