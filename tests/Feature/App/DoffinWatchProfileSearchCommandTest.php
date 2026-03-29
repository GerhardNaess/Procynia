<?php

namespace Tests\Feature\App;

use App\Models\WatchProfile;
use App\Services\Doffin\DoffinLiveSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class DoffinWatchProfileSearchCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->app['db']->purge('sqlite');
        $this->app['db']->reconnect('sqlite');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_runs_live_search_for_watch_profile_and_formats_scoped_results(): void
    {
        $profile = $this->createWatchProfile(
            customerId: 7,
            departmentId: 14,
            keywords: ['framework agreement', 'consulting', ''],
            isActive: true,
            cpvCodes: ['72000000', '48000000'],
        );

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->with([
                'q' => '',
                'organization_name' => '',
                'cpv' => '48000000,72000000',
                'keywords' => "framework agreement\nconsulting",
                'publication_period' => '',
                'status' => 'ACTIVE',
            ], 1, 15)
            ->andReturn([
                'numHitsAccessible' => 1,
                'numHitsTotal' => 1,
                'hits' => [
                    [
                        'id' => '2026-105883',
                        'heading' => 'Renholdstjenester for Tromsø brann og redning KF',
                        'buyer' => [
                            ['name' => 'Tromsø kommune'],
                        ],
                        'publicationDate' => '2026-03-26',
                        'deadline' => '2026-04-15',
                    ],
                ],
            ]);

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-profile-search', [
            'watchProfileId' => (string) $profile->id,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("watch_profile_id: {$profile->id}", $output);
        $this->assertStringContainsString('hits: 1', $output);
        $this->assertStringContainsString('"notice_id": "2026-105883"', $output);
        $this->assertStringContainsString('"title": "Renholdstjenester for Tromsø brann og redning KF"', $output);
        $this->assertStringContainsString('"buyer_name": "Tromsø kommune"', $output);
        $this->assertStringContainsString('"publication_date": "2026-03-26"', $output);
        $this->assertStringContainsString('"deadline": "2026-04-15"', $output);
        $this->assertStringContainsString('"external_url": "https://doffin.no/notices/2026-105883"', $output);
        $this->assertStringContainsString('"customer_id": 7', $output);
        $this->assertStringContainsString('"department_id": 14', $output);
        $this->assertStringContainsString('"watch_profile_id": '.(string) $profile->id, $output);
    }

    public function test_command_handles_zero_hits_without_error_and_without_local_notices_table(): void
    {
        $profile = $this->createWatchProfile(
            customerId: 3,
            departmentId: null,
            keywords: ['consulting'],
            isActive: true,
            cpvCodes: [],
        );

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->with([
                'q' => '',
                'organization_name' => '',
                'cpv' => '',
                'keywords' => 'consulting',
                'publication_period' => '',
                'status' => 'ACTIVE',
            ], 1, 15)
            ->andReturn([
                'numHitsAccessible' => 0,
                'numHitsTotal' => 0,
                'hits' => [],
            ]);

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-profile-search', [
            'watchProfileId' => (string) $profile->id,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('hits: 0', $output);
        $this->assertStringContainsString('results: []', $output);
    }

    public function test_command_does_not_run_search_for_inactive_watch_profile(): void
    {
        $profile = $this->createWatchProfile(
            customerId: 8,
            departmentId: 2,
            keywords: ['consulting'],
            isActive: false,
            cpvCodes: ['72000000'],
        );

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')->never();

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-profile-search', [
            'watchProfileId' => (string) $profile->id,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Watch profile {$profile->id} is inactive. No live Doffin search was run.", $output);
    }

    public function test_command_fails_cleanly_when_watch_profile_is_missing(): void
    {
        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')->never();

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-profile-search', [
            'watchProfileId' => '999',
        ]);

        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Watch profile 999 was not found.', $output);
    }

    public function test_command_passes_through_string_keywords_without_guessing(): void
    {
        $profile = WatchProfile::query()->create([
            'customer_id' => 5,
            'department_id' => null,
            'name' => 'Legacy String Keywords',
            'description' => null,
            'keywords' => [],
            'is_active' => true,
        ]);

        Schema::getConnection()->table('watch_profiles')
            ->where('id', $profile->id)
            ->update([
                'keywords' => 'legacy consulting',
            ]);

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->with([
                'q' => '',
                'organization_name' => '',
                'cpv' => '',
                'keywords' => 'legacy consulting',
                'publication_period' => '',
                'status' => 'ACTIVE',
            ], 1, 15)
            ->andReturn([
                'numHitsAccessible' => 0,
                'numHitsTotal' => 0,
                'hits' => [],
            ]);

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-profile-search', [
            'watchProfileId' => (string) $profile->id,
        ]);

        $this->assertSame(0, $exitCode);
    }

    private function createSchema(): void
    {
        Schema::create('watch_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('watch_profile_cpv_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('watch_profile_id');
            $table->string('cpv_code');
            $table->integer('weight')->default(1);
            $table->timestamps();
        });
    }

    private function createWatchProfile(
        int $customerId,
        ?int $departmentId,
        array $keywords,
        bool $isActive,
        array $cpvCodes,
    ): WatchProfile {
        $profile = WatchProfile::query()->create([
            'customer_id' => $customerId,
            'user_id' => $departmentId === null ? 77 : null,
            'department_id' => $departmentId,
            'name' => 'Profile '.$customerId.'-'.($departmentId ?? 'customer'),
            'description' => null,
            'keywords' => $keywords,
            'is_active' => $isActive,
        ]);

        foreach ($cpvCodes as $cpvCode) {
            $profile->cpvCodes()->create([
                'cpv_code' => $cpvCode,
                'weight' => 1,
            ]);
        }

        return $profile->fresh('cpvCodes');
    }
}
