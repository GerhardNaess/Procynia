<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiErfaring;
use App\Models\AiCustomerExperiencePeriod;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\Experience\AiExperienceSnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesAiExperienceScenarios;
use Tests\TestCase;

/**
 * Admin → AI-erfaring: internal-only, read-only analysis of the experience snapshots.
 */
class AiErfaringPageTest extends TestCase
{
    use CreatesAiExperienceScenarios;
    use RefreshDatabase;

    private Customer $alfa;

    private Customer $beta;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('no');
        $this->pinCapacityConfig();
        Carbon::setTestNow('2026-10-02 12:00:00');
        Customer::query()->update(['is_active' => false]);
        $this->trustedBoundaryAt();

        $this->alfa = $this->experienceCustomer('Alfa Analyse AS', ['basis'], users: 2);
        $this->beta = $this->experienceCustomer('Beta Analyse AS', ['basis', 'tender'], users: 4, tier: 'level_2');
        $this->experienceAttempt($this->alfa, '2026-10-05 10:00:00', 10.0);
        $this->experienceAttempt($this->beta, '2026-10-05 10:00:00', 12.5);
        $this->experienceAttempt($this->beta, '2026-10-06 10:00:00', 37.5, ['operation_key' => 'tender.requirement_answer', 'capacity_verdict' => 'exhausted']);

        app()->make(AiExperienceSnapshotService::class)->refreshAll();
        Carbon::setTestNow('2026-11-10 12:00:00');
        app()->make(AiExperienceSnapshotService::class)->refreshAll();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_internal_admin_sees_the_overview_formula_and_customer_list(): void
    {
        $this->actingAs($this->internalAdmin())->get(AiErfaring::getUrl())
            ->assertOk()
            ->assertSeeText('AI-erfaring')
            ->assertSeeText('Kun analyse. Siden endrer aldri vekter, multiplikatorer, priser eller kundens kapasitet.')
            ->assertSeeText('2 kunder · 2 perioder · 3 AI-kall')
            ->assertSeeText('For lite datagrunnlag for sikker vurdering.')
            ->assertSeeText('Nåværende modell mot observerte data')
            ->assertSeeText('Per bruker')
            ->assertSeeText('Anbud-vekt')
            ->assertSeeText('Nivå 2-multiplikator')
            ->assertSeeText('Alfa Analyse AS')
            ->assertSeeText('Beta Analyse AS')
            ->assertSeeText('Kunder med Anbud har median')
            ->assertSeeText('Trend per kalendermåned');
    }

    public function test_customer_users_never_reach_the_page(): void
    {
        $owner = $this->experienceUser($this->alfa);
        $owner->forceFill(['bid_role' => User::BID_ROLE_SYSTEM_OWNER, 'role' => User::ROLE_CUSTOMER_ADMIN])->save();

        $this->actingAs($owner)->get(AiErfaring::getUrl())->assertForbidden();
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route): bool => str_contains($route->uri(), 'ai-erfaring') && str_starts_with($route->uri(), 'app')));
    }

    public function test_filtering_on_a_customer_and_opening_a_period_shows_setup_usage_and_breakdown(): void
    {
        $this->actingAs($this->internalAdmin());
        $beta = $this->october($this->beta);

        $page = Livewire::test(AiErfaring::class);
        $this->assertSame(['Beta Analyse AS', 'Alfa Analyse AS'], $this->listed($page));

        $page->set('customerId', (string) $this->beta->id);
        $this->assertSame(['Beta Analyse AS'], $this->listed($page));

        $page->call('openPeriod', $beta->id)
            ->assertSet('periodId', $beta->id)
            ->assertSeeText('Oppsett i perioden')
            ->assertSeeText('Basis, Anbud')
            ->assertSeeText('Nivå 2 (×1,50)')
            ->assertSeeText('1 650')
            ->assertSeeText('50,00 NOK')
            ->assertSeeText('Fordeling per modul/funksjon')
            ->assertSeeText('tender.requirement_answer')
            ->assertSeeText('75,0 %')
            ->call('closePeriod')
            ->assertSet('periodId', null)
            ->assertDontSeeText('Oppsett i perioden');
    }

    public function test_tier_feature_and_incomplete_filters_narrow_the_list(): void
    {
        $this->actingAs($this->internalAdmin());

        $page = Livewire::test(AiErfaring::class)->set('tier', 'level_1');
        $this->assertSame(['Alfa Analyse AS'], $this->listed($page));

        $page->set('tier', '')->set('feature', 'tender');
        $this->assertSame(['Beta Analyse AS'], $this->listed($page));

        // November is still open: left out until asked for.
        $page->set('feature', '')->set('dateFrom', '2026-11-01')->assertSeeText('Ingen perioder i utvalget.');
        $this->assertSame([], $this->listed($page));

        $page->set('includeIncomplete', true)->assertSeeText('Åpen');
        $this->assertEqualsCanonicalizing(['Alfa Analyse AS', 'Beta Analyse AS'], $this->listed($page));
    }

    public function test_the_page_changes_nothing_in_the_commercial_model(): void
    {
        $this->actingAs($this->internalAdmin());
        $config = [config('ai_customer_capacity.base'), config('ai_customer_capacity.tiers')];
        $customers = Customer::query()->orderBy('id')->get(['id', 'ai_capacity_tier', 'included_ai_units'])->toArray();

        $page = Livewire::test(AiErfaring::class)
            ->set('customerId', (string) $this->beta->id)
            ->call('openPeriod', $this->october($this->beta)->id)
            ->call('resetFilters');

        $this->assertSame([], $page->instance()->getCachedHeaderActions());
        $this->assertSame($config, [config('ai_customer_capacity.base'), config('ai_customer_capacity.tiers')]);
        $this->assertSame($customers, Customer::query()->orderBy('id')->get(['id', 'ai_capacity_tier', 'included_ai_units'])->toArray());
    }

    public function test_a_render_runs_a_bounded_number_of_queries_however_many_customers(): void
    {
        $this->actingAs($this->internalAdmin());
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(AiErfaring::class)->set('sort', 'calls');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $before = $count();

        foreach (range(1, 5) as $i) {
            $customer = $this->experienceCustomer("Ekstra {$i} AS", ['basis', 'risk'], users: $i);
            $this->experienceAttempt($customer, '2026-10-07 10:00:00', 1.0 * $i);
        }
        app()->make(AiExperienceSnapshotService::class)->refreshAll(recheckFinal: true);

        $this->assertSame($before, $count());
    }

    public function test_it_sits_under_fakturering_in_the_admin_navigation(): void
    {
        $this->assertSame('Fakturering', AiErfaring::getNavigationGroup());
        $this->assertSame('AI-erfaring', AiErfaring::getNavigationLabel());
    }

    /** @return list<string> the customers in the list, in the order shown */
    private function listed($page): array
    {
        return array_column($page->viewData('report')['rows'], 'customer');
    }

    private function october(Customer $customer): AiCustomerExperiencePeriod
    {
        return AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->where('period_start', '2026-10-01 00:00:00')->sole();
    }

    private function internalAdmin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'customer_id' => null, 'is_active' => true]);
    }
}
