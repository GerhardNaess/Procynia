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

    private const DEPARTMENT_NAME = 'MSP';

    private const WATCH_PROFILE_NAME = 'IT drift - samlet';

    private const LEGACY_PROFILE_NAMES = [
        'IT drift og forvaltning',
        'Servicedesk og brukerstøtte',
        'Infrastruktur og datasenter',
        'Nettverk og kommunikasjon',
        'Sikkerhet og SOC',
        'Sky og Microsoft 365',
        'Backup, beredskap og recovery',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'development'])) {
            return;
        }

        // Intentionally manual-only: keep this Advania-specific watch profile set out of DatabaseSeeder
        // so generic seeding stays unchanged and the profile is only added for local/dev reseeds.
        DB::transaction(function (): void {
            $customer = $this->resolveCustomer();
            $mspDepartment = $this->resolveDepartment(
                $customer,
                self::DEPARTMENT_NAME,
                'Avdeling for IT-drift og forvaltning',
            );

            $this->removeLegacyProfiles($customer, $mspDepartment);

            $profile = $this->upsertWatchProfile(
                customer: $customer,
                department: $mspDepartment,
                name: self::WATCH_PROFILE_NAME,
                description: 'Samlet watch list for IT-drift, forvaltning, support, infrastruktur og sikkerhet.',
                keywords: $this->keywords(),
            );

            $this->syncCpvCodes($profile, $this->cpvRules());
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
     * @return array<int, string>
     */
    private function keywords(): array
    {
        return [
            'IT drift',
            'IT-drift',
            'forvaltning',
            'driftstjenester',
            'managed services',
            'applikasjonsdrift',
            'tjenestedrift',
            'servicedesk',
            'brukerstøtte',
            'support',
            'helpdesk',
            'ITIL',
            'hendelseshåndtering',
            'infrastruktur',
            'serverdrift',
            'datasenter',
            'virtualisering',
            'lagring',
            'nettverk',
            'nettverksdrift',
            'LAN',
            'WAN',
            'WiFi',
            'brannmur',
            'SD-WAN',
            'SOC',
            'sikkerhetsovervåkning',
            'cybersikkerhet',
            'SIEM',
            'hendelsesrespons',
            'Azure',
            'Microsoft 365',
            'Entra ID',
            'Intune',
            'skydrift',
            'tenant',
            'backup',
            'restore',
            'disaster recovery',
            'beredskap',
            'kontinuitet',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function cpvRules(): array
    {
        return [
            '72000000' => 20,
            '72250000' => 20,
            '72253000' => 20,
            '72253100' => 20,
            '72253200' => 20,
            '72261000' => 20,
            '72267000' => 20,
            '72267100' => 20,
            '72315000' => 20,
            '72315100' => 20,
            '72315200' => 20,
            '72500000' => 20,
            '72510000' => 20,
            '72511000' => 20,
            '72514000' => 20,
            '72514100' => 20,
            '72514300' => 20,
            '72590000' => 20,
            '72591000' => 20,
            '72600000' => 20,
            '72610000' => 20,
            '72611000' => 20,
            '72700000' => 20,
            '72710000' => 20,
            '72720000' => 20,
            '72900000' => 20,
            '72910000' => 20,
            '50312000' => 12,
            '50312300' => 12,
            '50312600' => 12,
            '50330000' => 12,
            '50332000' => 12,
            '64200000' => 14,
            '64210000' => 14,
            '64215000' => 14,
            '64216000' => 14,
            '64216110' => 14,
            '64220000' => 14,
            '30200000' => 10,
            '30210000' => 10,
            '30230000' => 10,
            '32400000' => 10,
            '32410000' => 10,
            '32420000' => 10,
            '32424000' => 10,
            '48000000' => 10,
            '48200000' => 10,
            '48210000' => 10,
            '48220000' => 10,
            '48730000' => 10,
            '48760000' => 10,
            '48800000' => 10,
            '48820000' => 10,
            '48821000' => 10,
        ];
    }

    private function removeLegacyProfiles(Customer $customer, Department $department): void
    {
        WatchProfile::query()
            ->where('customer_id', $customer->id)
            ->where('department_id', $department->id)
            ->whereNull('user_id')
            ->whereIn('name', self::LEGACY_PROFILE_NAMES)
            ->delete();
    }

    /**
     * @param  array<string, int>  $cpvRules
     */
    private function syncCpvCodes(WatchProfile $profile, array $cpvRules): void
    {
        $desiredCpvCodes = array_keys($cpvRules);

        $profile->cpvCodes()
            ->whereNotIn('cpv_code', $desiredCpvCodes)
            ->delete();

        foreach ($cpvRules as $cpvCode => $weight) {
            $profile->cpvCodes()->updateOrCreate(
                ['cpv_code' => $cpvCode],
                ['weight' => $weight],
            );
        }
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
            ->whereNull('user_id')
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
