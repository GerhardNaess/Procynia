<?php

namespace App\Filament\Pages;

use App\Data\Ai\Experience\AiExperienceFilter;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Services\Ai\Commercial\AiCapacityTierCatalog;
use App\Services\Ai\Experience\AiExperienceAnalysisService;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\Ai\AiOperationCatalog;
use App\Support\CustomerContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Purpose: Internal AI experience analysis — how actual AI usage varies with users, modules and
 *          capacity levels, beside the current capacity formula.
 * Inputs: Livewire filter properties; `?periode=` opens one customer/period.
 * Returns: AiExperienceAnalysisService (snapshots per customer and billing period, plus the shared
 *          per-operation statistics and a calendar trend from the trusted ledger).
 * Side effects: None. READ-ONLY: no action on this page changes config, a weight, a multiplier, a
 *          price, a tier or a customer's capacity.
 * Access: Internal Procynia admin only (CustomerContext::isInternalAdmin()); never under /app.
 * Queries: a bounded handful per render (two for the snapshots, one each for trend, operations,
 *          estimate signals and the filter options) — never per customer or per period. The
 *          operation filter lists the operation registry, not the ledger.
 */
class AiErfaring extends Page
{
    protected string $view = 'filament.pages.ai-erfaring';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'ai-erfaring';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $customerId = '';

    public string $feature = '';

    public string $operation = '';

    public string $tier = '';

    public string $package = '';

    public string $usersMin = '';

    public string $usersMax = '';

    public string $interval = '';

    public bool $includeIncomplete = false;

    public string $sort = 'cost';

    #[Url(as: 'periode')]
    public ?int $periodId = null;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return __('procynia.ai_admin.experience.navigation');
    }

    public function getTitle(): string
    {
        return __('procynia.ai_admin.experience.title');
    }

    public function getSubheading(): ?string
    {
        return __('procynia.ai_admin.experience.subheading');
    }

    public function openPeriod(int $id): void
    {
        $this->periodId = $id;
    }

    public function closePeriod(): void
    {
        $this->periodId = null;
    }

    public function resetFilters(): void
    {
        $this->reset(['dateFrom', 'dateTo', 'customerId', 'feature', 'operation', 'tier', 'package', 'usersMin', 'usersMax', 'interval', 'includeIncomplete', 'sort', 'periodId']);
    }

    public function filter(): AiExperienceFilter
    {
        return new AiExperienceFilter(
            from: $this->date($this->dateFrom),
            to: $this->date($this->dateTo),
            customerId: ctype_digit($this->customerId) ? (int) $this->customerId : null,
            feature: $this->feature !== '' ? $this->feature : null,
            operation: $this->operation !== '' ? $this->operation : null,
            tier: $this->tier !== '' ? $this->tier : null,
            package: $this->package !== '' ? $this->package : null,
            usersMin: ctype_digit($this->usersMin) ? (int) $this->usersMin : null,
            usersMax: ctype_digit($this->usersMax) ? (int) $this->usersMax : null,
            interval: in_array($this->interval, ['month', 'year'], true) ? $this->interval : null,
            includeIncomplete: $this->includeIncomplete,
        );
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $analysis = app(AiExperienceAnalysisService::class);
        $filter = $this->filter();
        $sort = in_array($this->sort, AiExperienceAnalysisService::SORTS, true) ? $this->sort : 'cost';

        return [
            'hasTrustedData' => AiUsageAttempt::query()->where('ledger_version', '>=', AiUsageAttempt::TRUSTED_SINCE_LEDGER_VERSION)->exists(),
            'report' => $analysis->report($filter, $sort),
            'detail' => $this->periodId === null ? null : ($analysis->detail($this->periodId, $filter->operation) ?? false),
            'operations' => $analysis->operations($filter),
            'trend' => $analysis->trend($filter),
            'customerOptions' => Customer::query()->orderBy('name')->pluck('name', 'id')->all(),
            'featureOptions' => $analysis->featureKeys(),
            'operationOptions' => AiOperationCatalog::registeredOperations(),
            'tierOptions' => array_keys(app(AiCapacityTierCatalog::class)->all()),
            'packageOptions' => array_keys(app(ModuleEntitlementService::class)->packages()),
        ];
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
