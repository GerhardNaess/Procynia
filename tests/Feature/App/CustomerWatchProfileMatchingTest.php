<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Notice;
use App\Models\WatchProfile;
use App\Models\WatchProfileMatch;
use App\Services\Doffin\DoffinWatchProfileMatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerWatchProfileMatchingTest extends TestCase
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
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_watch_profile_with_matching_cpv_persists_match_with_positive_score(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $profile = $this->createWatchProfile($customer->id, null, [], [
            ['cpv_code' => '99999991', 'weight' => 33],
        ]);
        $notice = $this->createNotice('DOFFIN-CPV-1', 'Maritim anskaffelse', null, 'Kystverket', ['99999991']);

        $summary = app(DoffinWatchProfileMatchService::class)->run($customer->id);

        $this->assertSame(1, $summary['profiles_processed']);
        $this->assertSame(1, $summary['matches_created']);
        $this->assertDatabaseHas('watch_profile_matches', [
            'customer_id' => $customer->id,
            'watch_profile_id' => $profile->id,
            'notice_id' => $notice->id,
            'score' => 33,
            'matched_cpv_count' => 1,
            'matched_keywords_count' => 0,
        ]);
        $this->assertSame(1, app(DoffinWatchProfileMatchService::class)->newHitsLastDayCount($customer->id));
    }

    public function test_watch_profile_with_matching_keyword_persists_match_with_positive_score(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $profile = $this->createWatchProfile($customer->id, null, ['unik-beredskapsterm-42']);
        $notice = $this->createNotice('DOFFIN-KW-1', 'Anskaffelse av unik-beredskapsterm-42', null, 'Beredskapsetaten');

        app(DoffinWatchProfileMatchService::class)->run($customer->id);

        $match = WatchProfileMatch::query()
            ->where('watch_profile_id', $profile->id)
            ->where('notice_id', $notice->id)
            ->first();

        $this->assertInstanceOf(WatchProfileMatch::class, $match);
        $this->assertSame(1, $match->matched_keywords_count);
        $this->assertSame(0, $match->matched_cpv_count);
        $this->assertGreaterThan(0, $match->score);
    }

    public function test_zero_score_notice_is_not_persisted(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $this->createWatchProfile($customer->id, null, ['helt-unik-term-ingen-treff'], [
            ['cpv_code' => '99999992', 'weight' => 25],
        ]);
        $this->createNotice('DOFFIN-NONE-1', 'Vanlig renhold', 'Ingen match her', 'Oslo kommune', ['11111111']);

        $summary = app(DoffinWatchProfileMatchService::class)->run($customer->id);

        $this->assertSame(0, $summary['matches_created']);
        $this->assertDatabaseCount('watch_profile_matches', 0);
    }

    public function test_same_watch_profile_and_notice_are_not_duplicated_and_last_seen_updates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-28 10:00:00'));

        $customer = $this->createCustomer('Procynia AS');
        $profile = $this->createWatchProfile($customer->id, null, ['unik-repeat-term']);
        $notice = $this->createNotice('DOFFIN-REPEAT-1', 'unik-repeat-term i tittel', null, 'Kunde A');

        $service = app(DoffinWatchProfileMatchService::class);
        $firstSummary = $service->run($customer->id);
        $firstMatch = WatchProfileMatch::query()
            ->where('watch_profile_id', $profile->id)
            ->where('notice_id', $notice->id)
            ->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-03-28 16:00:00'));

        $secondSummary = $service->run($customer->id);
        $secondMatch = WatchProfileMatch::query()
            ->where('watch_profile_id', $profile->id)
            ->where('notice_id', $notice->id)
            ->firstOrFail();

        $this->assertSame(1, $firstSummary['matches_created']);
        $this->assertSame(1, $secondSummary['matches_updated']);
        $this->assertDatabaseCount('watch_profile_matches', 1);
        $this->assertTrue($firstMatch->first_seen_at->equalTo(Carbon::parse('2026-03-28 10:00:00')));
        $this->assertTrue($secondMatch->first_seen_at->equalTo(Carbon::parse('2026-03-28 10:00:00')));
        $this->assertTrue($secondMatch->last_seen_at->equalTo(Carbon::parse('2026-03-28 16:00:00')));
    }

    public function test_new_hits_last_day_count_is_customer_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-28 12:00:00'));

        $primary = $this->createCustomer('Procynia AS');
        $secondary = $this->createCustomer('Annen Kunde AS');
        $primaryProfile = $this->createWatchProfile($primary->id, null, ['unik-scope-term-primary']);
        $secondaryProfile = $this->createWatchProfile($secondary->id, null, ['unik-scope-term-secondary']);
        $primaryNotice = $this->createNotice('DOFFIN-SCOPE-1', 'unik-scope-term-primary', null, 'Kunde A');
        $secondaryNotice = $this->createNotice('DOFFIN-SCOPE-2', 'unik-scope-term-secondary', null, 'Kunde B');

        $service = app(DoffinWatchProfileMatchService::class);
        $service->run($primary->id);

        WatchProfileMatch::query()->create([
            'customer_id' => $secondary->id,
            'department_id' => $secondaryProfile->department_id,
            'watch_profile_id' => $secondaryProfile->id,
            'notice_id' => $secondaryNotice->id,
            'score' => 20,
            'matched_keywords_count' => 1,
            'matched_cpv_count' => 0,
            'first_seen_at' => Carbon::parse('2026-03-26 12:00:00'),
            'last_seen_at' => Carbon::parse('2026-03-26 12:00:00'),
        ]);

        $this->assertSame(1, $service->newHitsLastDayCount($primary->id));
        $this->assertSame(0, $service->newHitsLastDayCount($secondary->id));

        $this->assertDatabaseHas('watch_profile_matches', [
            'watch_profile_id' => $primaryProfile->id,
            'notice_id' => $primaryNotice->id,
        ]);
    }

    public function test_artisan_command_matches_profiles_for_selected_customer(): void
    {
        $primary = $this->createCustomer('Procynia AS');
        $secondary = $this->createCustomer('Annen Kunde AS');
        $primaryProfile = $this->createWatchProfile($primary->id, null, ['unik-command-term']);
        $secondaryProfile = $this->createWatchProfile($secondary->id, null, ['uten-treff-her']);
        $notice = $this->createNotice('DOFFIN-CMD-1', 'unik-command-term', null, 'Kunde A');

        $this->artisan('doffin:watch-match', [
            '--customer' => (string) $primary->id,
        ])
            ->expectsOutput('Watch profile matching completed.')
            ->expectsOutput('profiles_processed: 1')
            ->expectsOutput('matches_created: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('watch_profile_matches', [
            'watch_profile_id' => $primaryProfile->id,
            'notice_id' => $notice->id,
        ]);
        $this->assertDatabaseMissing('watch_profile_matches', [
            'watch_profile_id' => $secondaryProfile->id,
            'notice_id' => $notice->id,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('watch_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('department_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('watch_profile_cpv_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('watch_profile_id');
            $table->string('cpv_code');
            $table->integer('weight')->default(1);
            $table->timestamps();
            $table->unique(['watch_profile_id', 'cpv_code']);
        });

        Schema::create('notices', function (Blueprint $table): void {
            $table->id();
            $table->string('notice_id')->unique();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->string('buyer_name')->nullable();
            $table->timestamps();
        });

        Schema::create('notice_cpv_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_id');
            $table->string('cpv_code');
            $table->timestamps();
            $table->unique(['notice_id', 'cpv_code']);
        });

        Schema::create('watch_profile_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('department_id')->nullable();
            $table->foreignId('watch_profile_id');
            $table->foreignId('notice_id');
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('matched_keywords_count')->default(0);
            $table->unsignedInteger('matched_cpv_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['watch_profile_id', 'notice_id']);
        });
    }

    private function createCustomer(string $name): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
    }

    private function createWatchProfile(
        int $customerId,
        ?int $departmentId,
        array $keywords = [],
        array $cpvRules = [],
    ): WatchProfile {
        $profile = WatchProfile::query()->create([
            'customer_id' => $customerId,
            'department_id' => $departmentId,
            'name' => 'Profile '.substr(md5(implode('|', $keywords).json_encode($cpvRules)), 0, 10),
            'description' => null,
            'keywords' => $keywords,
            'is_active' => true,
        ]);

        if ($cpvRules !== []) {
            $profile->cpvCodes()->createMany($cpvRules);
        }

        return $profile;
    }

    private function createNotice(
        string $noticeId,
        ?string $title,
        ?string $description,
        ?string $buyerName,
        array $cpvCodes = [],
    ): Notice {
        $notice = Notice::query()->create([
            'notice_id' => $noticeId,
            'title' => $title,
            'description' => $description,
            'buyer_name' => $buyerName,
        ]);

        if ($cpvCodes !== []) {
            $notice->cpvCodes()->createMany(
                array_map(static fn (string $cpvCode): array => ['cpv_code' => $cpvCode], $cpvCodes),
            );
        }

        return $notice;
    }
}
