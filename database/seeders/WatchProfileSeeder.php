<?php

namespace Database\Seeders;

use App\Models\WatchProfile;
use Illuminate\Database\Seeder;

class WatchProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profile = WatchProfile::query()->updateOrCreate(
            ['name' => 'Advania Core'],
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
}
