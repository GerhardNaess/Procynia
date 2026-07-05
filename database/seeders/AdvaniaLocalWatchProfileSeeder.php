<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\WatchProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdvaniaLocalWatchProfileSeeder extends Seeder
{
    private const CUSTOMER_NAME = 'Advania Norge AS';

    private const CUSTOMER_SLUG = 'advania-norge-as';

    public function run(): void
    {
        if (! app()->environment(['local', 'development'])) {
            return;
        }

        // Intentionally manual-only: keep this Advania-specific watch profile set out of DatabaseSeeder
        // so generic seeding stays unchanged and the profiles are only added for local/dev reseeds.
        DB::transaction(function (): void {
            $customer = $this->resolveCustomer();
            $mspDepartment = $this->resolveDepartment(
                $customer,
                'MSP',
                'Avdeling for IT-drift og forvaltning',
            );

            foreach ($this->profiles() as $profile) {
                $this->upsertWatchProfile(
                    customer: $customer,
                    department: $mspDepartment,
                    name: $profile['name'],
                    description: $profile['description'],
                    keywords: $profile['keywords'],
                );
            }
        });
    }

    private function resolveCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => '🇳🇴'],
        );

        return Customer::query()->updateOrCreate(
            ['slug' => self::CUSTOMER_SLUG],
            [
                'name' => self::CUSTOMER_NAME,
                'nationality_id' => $nationality->id,
                'language_id' => $language->id,
                'is_active' => true,
            ],
        );
    }

    private function resolveDepartment(Customer $customer, string $name, string $description): Department
    {
        return Department::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'name' => $name,
            ],
            [
                'description' => $description,
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array<int, array{name:string,description:string,keywords:array<int, string>}>
     */
    private function profiles(): array
    {
        return [
            [
                'name' => 'IT drift og forvaltning',
                'description' => 'Fanger anbud om drift, forvaltning og løpende tjenesteleveranse.',
                'keywords' => [
                    'drift',
                    'forvaltning',
                    'it-drift',
                    'it drift',
                    'tjenestedrift',
                    'driftsavtale',
                    'applikasjonsdrift',
                ],
            ],
            [
                'name' => 'Servicedesk og brukerstøtte',
                'description' => 'Fanger anbud om servicedesk, førstelinje og brukerstøtte.',
                'keywords' => [
                    'servicedesk',
                    'brukerstøtte',
                    'support',
                    'førstelinje',
                    'itil',
                    'hendelseshåndtering',
                ],
            ],
            [
                'name' => 'Infrastruktur og datasenter',
                'description' => 'Fanger anbud om serverdrift, datasenter, lagring og virtualisering.',
                'keywords' => [
                    'serverdrift',
                    'infrastruktur',
                    'datasenter',
                    'lagring',
                    'backup',
                    'virtualisering',
                ],
            ],
            [
                'name' => 'Nettverk og kommunikasjon',
                'description' => 'Fanger anbud om nettverksdrift, brannmur og kommunikasjonstjenester.',
                'keywords' => [
                    'nettverk',
                    'lan',
                    'wan',
                    'brannmur',
                    'wifi',
                    'sd-wan',
                    'nettverksdrift',
                ],
            ],
            [
                'name' => 'Sikkerhet og SOC',
                'description' => 'Fanger anbud om sikkerhetsovervåkning, hendelsesrespons og SOC.',
                'keywords' => [
                    'soc',
                    'sikkerhetsovervåkning',
                    'hendelsesrespons',
                    'sårbarhet',
                    'cybersikkerhet',
                    'siem',
                ],
            ],
            [
                'name' => 'Sky og Microsoft 365',
                'description' => 'Fanger anbud om Azure, Microsoft 365 og skydrift.',
                'keywords' => [
                    'azure',
                    'microsoft 365',
                    'entra id',
                    'intune',
                    'tenant',
                    'skydrift',
                ],
            ],
            [
                'name' => 'Backup, beredskap og recovery',
                'description' => 'Fanger anbud om backup, gjenoppretting, beredskap og kontinuitet.',
                'keywords' => [
                    'backup',
                    'restore',
                    'disaster recovery',
                    'beredskap',
                    'kontinuitet',
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function upsertWatchProfile(
        Customer $customer,
        Department $department,
        string $name,
        string $description,
        array $keywords,
    ): WatchProfile {
        $normalizedName = Str::squish($name);
        $existing = WatchProfile::query()
            ->where('customer_id', $customer->id)
            ->where('department_id', $department->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($normalizedName)])
            ->first();

        $attributes = [
            'customer_id' => $customer->id,
            'user_id' => null,
            'department_id' => $department->id,
            'name' => $normalizedName,
            'description' => $description,
            'keywords' => array_values(array_unique(array_filter(array_map(
                static fn (string $keyword): string => trim($keyword),
                $keywords,
            ), static fn (string $keyword): bool => $keyword !== ''))),
            'is_active' => true,
        ];

        if ($existing instanceof WatchProfile) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        return WatchProfile::query()->create($attributes);
    }
}
