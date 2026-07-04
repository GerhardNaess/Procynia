<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\WatchProfile;
use Illuminate\Database\Seeder;

class WatchProfileSeeder extends Seeder
{
    public function run(): void
    {
        $customer = $this->resolveCustomer();

        $profile = WatchProfile::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'name' => 'Advania Core',
            ],
            [
                'description' => 'Core CPV watch profile for MVP scoring',
                'keywords' => ['framework agreement', 'consulting'],
                'is_active' => true,
            ],
        );

        $cpvCodes = [
            '03000000' => 20,
            '03111700' => 20,
            '48000000' => 20,
            '72000000' => 20,
            '72200000' => 25,
        ];

        foreach ($cpvCodes as $cpvCode => $weight) {
            $profile->cpvCodes()->updateOrCreate(
                ['cpv_code' => $cpvCode],
                ['weight' => $weight],
            );
        }
    }

    private function resolveCustomer(): Customer
    {
        foreach (['demo-customer-as', 'e2e-test-customer', 'default-customer'] as $slug) {
            $customer = Customer::query()->where('slug', $slug)->first();

            if ($customer instanceof Customer) {
                return $customer;
            }
        }

        $customer = Customer::query()->orderBy('id')->first();

        if ($customer instanceof Customer) {
            return $customer;
        }

        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => '🇳🇴'],
        );

        return Customer::query()->create([
            'name' => 'Demo Customer AS',
            'slug' => 'demo-customer-as',
            'nationality_id' => $nationality->id,
            'language_id' => $language->id,
            'is_active' => true,
        ]);
    }
}
