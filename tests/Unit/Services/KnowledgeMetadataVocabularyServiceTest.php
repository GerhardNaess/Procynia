<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeMetadataTerm;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeMetadataVocabularyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_approved_terms_and_resolves_synonyms(): void
    {
        $customer = $this->createCustomer('Vocabulary Customer AS');

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'service_product_tag',
            'canonical_name' => 'Governance',
            'synonyms' => ['samhandling', 'styring'],
            'description' => 'Styringsmodell for tjenesten.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'theme_tag',
            'canonical_name' => 'Drift',
            'synonyms' => ['driftsmodell'],
            'description' => 'Driftsrelaterte temaer.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'topic',
            'canonical_name' => 'Samhandlingsfora og møtestruktur',
            'synonyms' => ['møtestruktur'],
            'description' => 'Faste møtefora.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'sub_topic',
            'canonical_name' => 'Strategisk, taktisk og operativ oppfølging',
            'synonyms' => ['operativ oppfølging'],
            'description' => 'Oppfølgingsstruktur.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'keyword',
            'canonical_name' => 'SLA',
            'synonyms' => ['service level agreement'],
            'description' => 'Nøkkelord.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'keyword',
            'canonical_name' => 'Skjult',
            'synonyms' => ['ikke med'],
            'description' => 'Skal ikke med fordi den ikke er godkjent.',
            'approved' => false,
        ]);

        $service = app(KnowledgeMetadataVocabularyService::class);
        $map = $service->buildForCustomer($customer->id);

        $this->assertSame(['Governance'], $map['fields']['service_product_tag']);
        $this->assertSame(['Drift'], $map['fields']['theme_tag']);
        $this->assertSame(['Samhandlingsfora og møtestruktur'], $map['fields']['topic']);
        $this->assertSame(['Strategisk, taktisk og operativ oppfølging'], $map['fields']['sub_topic']);
        $this->assertSame(['SLA'], $map['fields']['keywords']);
        $this->assertSame('Governance', $service->resolveCanonicalValue($map, 'service_product_tag', 'samhandling'));
        $this->assertSame('Drift', $service->resolveCanonicalValue($map, 'theme_tag', 'driftsmodell'));
        $this->assertSame('Samhandlingsfora og møtestruktur', $service->resolveCanonicalValue($map, 'topic', 'møtestruktur'));
        $this->assertSame('Strategisk, taktisk og operativ oppfølging', $service->resolveCanonicalValue($map, 'sub_topic', 'operativ oppfølging'));
        $this->assertNull($service->resolveCanonicalValue($map, 'keyword', 'ikke med'));
        $this->assertSame(1, $map['field_counts']['service_product_tag']);
        $this->assertSame(1, $map['field_counts']['theme_tag']);
        $this->assertSame(1, $map['field_counts']['topic']);
        $this->assertSame(1, $map['field_counts']['sub_topic']);
        $this->assertSame(1, $map['field_counts']['keywords']);
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
