<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Services\Ai\AiUsageReportingService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

class AiUsageCapacity extends Page
{
    use HasAdminPageHelp;

    protected string $view = 'filament.pages.ai-usage-capacity';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = null;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 5;

    public string $generatedAt = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $summaryCards = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $customerRows = [];

    #[Url(as: 'customer_search', except: '')]
    public string $customerSearch = '';

    #[Url(as: 'customer_status', except: 'all')]
    public string $customerStatusFilter = 'all';

    #[Url(as: 'customer_sort', except: 'status')]
    public string $customerSortField = 'status';

    #[Url(as: 'customer_direction', except: 'desc')]
    public string $customerSortDirection = 'desc';

    #[Url(as: 'customer_per_page', except: 25)]
    public int $customerPerPage = 25;

    #[Url(as: 'customer_page', except: 1)]
    public int $customerPage = 1;

    public int $customerTotal = 0;

    public int $customerLastPage = 1;

    public int $customerShowingFrom = 0;

    public int $customerShowingTo = 0;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $userRows = [];

    #[Url(as: 'user_search', except: '')]
    public string $userSearch = '';

    #[Url(as: 'user_status', except: 'all')]
    public string $userStatusFilter = 'all';

    #[Url(as: 'user_sort', except: 'status')]
    public string $userSortField = 'status';

    #[Url(as: 'user_direction', except: 'desc')]
    public string $userSortDirection = 'desc';

    #[Url(as: 'user_per_page', except: 25)]
    public int $userPerPage = 25;

    #[Url(as: 'user_page', except: 1)]
    public int $userPage = 1;

    public int $userTotal = 0;

    public int $userLastPage = 1;

    public int $userShowingFrom = 0;

    public int $userShowingTo = 0;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $operationRows = [];

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('procynia.ai_usage_capacity.navigation_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->buildPageHelpAction(
                static::fetchPageHelp('admin.ai_usage_capacity')
            ),
        ];
    }

    public function mount(AiUsageReportingService $service): void
    {
        $report = $service->report();

        $this->generatedAt = (string) $report['generated_at'];
        $this->summaryCards = $report['summary_cards'];
        $this->operationRows = $report['operations'];

        $customerRows = $this->prepareCustomerRows($report['customers']);
        $customerStatusById = $this->customerStatusById($customerRows);

        $this->applyCustomerTableState($customerRows);
        $this->applyUserTableState($this->prepareUserRows($report['users'], $customerStatusById));
    }

    public function getTitle(): string
    {
        return (string) __('procynia.ai_usage_capacity.page_title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('procynia.ai_usage_capacity.page_subtitle');
    }

    public function updatedCustomerSearch(): void
    {
        $this->customerPage = 1;
        $this->reloadData();
    }

    public function updatedCustomerStatusFilter(): void
    {
        $this->customerPage = 1;
        $this->reloadData();
    }

    public function updatedCustomerSortField(): void
    {
        $this->customerPage = 1;
        $this->reloadData();
    }

    public function updatedCustomerSortDirection(): void
    {
        $this->customerPage = 1;
        $this->reloadData();
    }

    public function updatedCustomerPerPage(): void
    {
        $this->customerPage = 1;
        $this->reloadData();
    }

    public function updatedUserSearch(): void
    {
        $this->userPage = 1;
        $this->reloadData();
    }

    public function updatedUserStatusFilter(): void
    {
        $this->userPage = 1;
        $this->reloadData();
    }

    public function updatedUserSortField(): void
    {
        $this->userPage = 1;
        $this->reloadData();
    }

    public function updatedUserSortDirection(): void
    {
        $this->userPage = 1;
        $this->reloadData();
    }

    public function updatedUserPerPage(): void
    {
        $this->userPage = 1;
        $this->reloadData();
    }

    public function resetCustomerFilters(): void
    {
        $this->customerSearch = '';
        $this->customerStatusFilter = 'all';
        $this->customerSortField = 'status';
        $this->customerSortDirection = 'desc';
        $this->customerPerPage = 25;
        $this->customerPage = 1;
        $this->reloadData();
    }

    public function resetUserFilters(): void
    {
        $this->userSearch = '';
        $this->userStatusFilter = 'all';
        $this->userSortField = 'status';
        $this->userSortDirection = 'desc';
        $this->userPerPage = 25;
        $this->userPage = 1;
        $this->reloadData();
    }

    public function previousCustomerPage(): void
    {
        if ($this->customerPage <= 1) {
            return;
        }

        $this->customerPage--;
        $this->reloadData();
    }

    public function nextCustomerPage(): void
    {
        if ($this->customerPage >= $this->customerLastPage) {
            return;
        }

        $this->customerPage++;
        $this->reloadData();
    }

    public function previousUserPage(): void
    {
        if ($this->userPage <= 1) {
            return;
        }

        $this->userPage--;
        $this->reloadData();
    }

    public function nextUserPage(): void
    {
        if ($this->userPage >= $this->userLastPage) {
            return;
        }

        $this->userPage++;
        $this->reloadData();
    }

    /**
     * Purpose: Reload the page data using the current Livewire filters and paging state.
     * Inputs: None.
     * Returns: None.
     * Side effects: Re-runs the reporting service and refreshes the visible rows.
     */
    private function reloadData(): void
    {
        $this->loadData(app(AiUsageReportingService::class));
    }

    /**
     * Purpose: Reload the page data from the reporting service.
     * Inputs: The reporting service.
     * Returns: None.
     * Side effects: Reads AI usage data and updates the visible page state.
     */
    private function loadData(AiUsageReportingService $service): void
    {
        $report = $service->report();

        $this->generatedAt = (string) $report['generated_at'];
        $this->summaryCards = $report['summary_cards'];
        $this->operationRows = $report['operations'];

        $customerRows = $this->prepareCustomerRows($report['customers']);
        $customerStatusById = $this->customerStatusById($customerRows);

        $this->applyCustomerTableState($customerRows);
        $this->applyUserTableState($this->prepareUserRows($report['users'], $customerStatusById));
    }

    /**
     * Purpose: Normalize the customer rows with a direct status key used for filtering and sorting.
     * Inputs: The raw customer rows from the reporting service.
     * Returns: Normalized customer rows.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function prepareCustomerRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['status'] = (string) ($row['capacity']['status'] ?? 'undefined');
        }
        unset($row);

        return $rows;
    }

    /**
     * Purpose: Build a customer status lookup keyed by customer id.
     * Inputs: The normalized customer rows.
     * Returns: A map from customer id to status key.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function customerStatusById(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $customerId = (string) ($row['id'] ?? '');

            if ($customerId === '') {
                continue;
            }

            $map[$customerId] = (string) ($row['status'] ?? 'undefined');
        }

        return $map;
    }

    /**
     * Purpose: Normalize the user rows with an operational status key used for filtering and sorting.
     * Inputs: The raw user rows and the current customer status map.
     * Returns: Normalized user rows.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $customerStatusById
     * @return array<int, array<string, mixed>>
     */
    private function prepareUserRows(array $rows, array $customerStatusById): array
    {
        foreach ($rows as &$row) {
            $customerId = (string) ($row['customer_id'] ?? '');
            $customerStatus = $customerStatusById[$customerId] ?? 'undefined';
            $blockedCount = (int) ($row['counts']['blocked'] ?? 0);

            if ($blockedCount > 0 || $customerStatus === 'over') {
                $status = 'blocked';
            } elseif ($customerStatus === 'near') {
                $status = 'near';
            } else {
                $status = 'within';
            }

            $row['status'] = $status;
            $row['status_label'] = (string) __('procynia.ai_usage_capacity.user_status.'.$status);
        }
        unset($row);

        return $rows;
    }

    /**
     * Purpose: Apply the current customer search, filter, sort, and pagination state.
     * Inputs: The normalized customer rows.
     * Returns: None.
     * Side effects: Updates the public customer table state.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function applyCustomerTableState(array $rows): void
    {
        $rows = $this->filterCustomerRows($rows);
        $rows = $this->sortCustomerRows($rows);

        $this->customerTotal = count($rows);
        $this->customerPerPage = $this->normalizePerPage($this->customerPerPage);
        $this->customerLastPage = max(1, (int) ceil($this->customerTotal / $this->customerPerPage));
        $this->customerPage = min(max(1, $this->customerPage), $this->customerLastPage);

        $offset = ($this->customerPage - 1) * $this->customerPerPage;
        $this->customerRows = array_slice($rows, $offset, $this->customerPerPage);
        $this->customerShowingFrom = $this->customerTotal > 0 ? $offset + 1 : 0;
        $this->customerShowingTo = $this->customerTotal > 0 ? min($this->customerTotal, $offset + count($this->customerRows)) : 0;
    }

    /**
     * Purpose: Apply the current user search, filter, sort, and pagination state.
     * Inputs: The normalized user rows.
     * Returns: None.
     * Side effects: Updates the public user table state.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function applyUserTableState(array $rows): void
    {
        $rows = $this->filterUserRows($rows);
        $rows = $this->sortUserRows($rows);

        $this->userTotal = count($rows);
        $this->userPerPage = $this->normalizePerPage($this->userPerPage);
        $this->userLastPage = max(1, (int) ceil($this->userTotal / $this->userPerPage));
        $this->userPage = min(max(1, $this->userPage), $this->userLastPage);

        $offset = ($this->userPage - 1) * $this->userPerPage;
        $this->userRows = array_slice($rows, $offset, $this->userPerPage);
        $this->userShowingFrom = $this->userTotal > 0 ? $offset + 1 : 0;
        $this->userShowingTo = $this->userTotal > 0 ? min($this->userTotal, $offset + count($this->userRows)) : 0;
    }

    /**
     * Purpose: Determine whether the customer table is filtered.
     * Inputs: None.
     * Returns: True when search or status filters are active.
     * Side effects: None.
     */
    public function customerFiltersActive(): bool
    {
        return trim($this->customerSearch) !== '' || $this->customerStatusFilter !== 'all';
    }

    /**
     * Purpose: Determine whether the user table is filtered.
     * Inputs: None.
     * Returns: True when search or status filters are active.
     * Side effects: None.
     */
    public function userFiltersActive(): bool
    {
        return trim($this->userSearch) !== '' || $this->userStatusFilter !== 'all';
    }

    /**
     * Purpose: Build the customer search, sort and filter options for the view.
     * Inputs: None.
     * Returns: A translated option list.
     * Side effects: None.
     *
     * @return array<string, string>
     */
    public function customerStatusOptions(): array
    {
        return [
            'all' => __('procynia.ai_usage_capacity.filters.all'),
            'near' => __('procynia.ai_usage_capacity.filters.customer_near'),
            'within' => __('procynia.ai_usage_capacity.filters.customer_within'),
            'blocked' => __('procynia.ai_usage_capacity.filters.customer_blocked'),
        ];
    }

    /**
     * Purpose: Build the user search, sort and filter options for the view.
     * Inputs: None.
     * Returns: A translated option list.
     * Side effects: None.
     *
     * @return array<string, string>
     */
    public function userStatusOptions(): array
    {
        return [
            'all' => __('procynia.ai_usage_capacity.filters.all'),
            'blocked' => __('procynia.ai_usage_capacity.filters.user_blocked'),
            'within' => __('procynia.ai_usage_capacity.filters.user_within'),
            'near' => __('procynia.ai_usage_capacity.filters.user_near'),
        ];
    }

    /**
     * Purpose: Build the customer sort options for the view.
     * Inputs: None.
     * Returns: A translated option list.
     * Side effects: None.
     *
     * @return array<string, string>
     */
    public function customerSortOptions(): array
    {
        return [
            'status' => __('procynia.ai_usage_capacity.sort.customer_status'),
            'name' => __('procynia.ai_usage_capacity.sort.customer_name'),
            'plan' => __('procynia.ai_usage_capacity.sort.customer_plan'),
            'usage_24h' => __('procynia.ai_usage_capacity.sort.customer_usage_24h'),
            'usage_7d' => __('procynia.ai_usage_capacity.sort.customer_usage_7d'),
            'usage_30d' => __('procynia.ai_usage_capacity.sort.customer_usage_30d'),
            'allowed' => __('procynia.ai_usage_capacity.sort.customer_allowed'),
            'blocked' => __('procynia.ai_usage_capacity.sort.customer_blocked'),
            'last_blocked_at' => __('procynia.ai_usage_capacity.sort.customer_last_blocked_at'),
        ];
    }

    /**
     * Purpose: Build the user sort options for the view.
     * Inputs: None.
     * Returns: A translated option list.
     * Side effects: None.
     *
     * @return array<string, string>
     */
    public function userSortOptions(): array
    {
        return [
            'status' => __('procynia.ai_usage_capacity.sort.user_status'),
            'name' => __('procynia.ai_usage_capacity.sort.user_name'),
            'email' => __('procynia.ai_usage_capacity.sort.user_email'),
            'customer_name' => __('procynia.ai_usage_capacity.sort.user_customer'),
            'usage_24h' => __('procynia.ai_usage_capacity.sort.user_usage_24h'),
            'usage_7d' => __('procynia.ai_usage_capacity.sort.user_usage_7d'),
            'usage_30d' => __('procynia.ai_usage_capacity.sort.user_usage_30d'),
            'allowed' => __('procynia.ai_usage_capacity.sort.user_allowed'),
            'blocked' => __('procynia.ai_usage_capacity.sort.user_blocked'),
            'last_blocked_at' => __('procynia.ai_usage_capacity.sort.user_last_blocked_at'),
        ];
    }

    /**
     * Purpose: Build the page-size options shared by both tables.
     * Inputs: None.
     * Returns: An ordered list of page-size options.
     * Side effects: None.
     *
     * @return array<int, int>
     */
    public function perPageOptions(): array
    {
        return [25, 50, 100];
    }

    /**
     * Purpose: Remove rows that do not match the customer filters.
     * Inputs: The candidate customer rows.
     * Returns: The filtered rows.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterCustomerRows(array $rows): array
    {
        $search = trim(Str::lower($this->customerSearch));

        $filtered = array_filter(
            $rows,
            function (array $row) use ($search): bool {
                if ($search !== '' && ! Str::contains(Str::lower((string) ($row['name'] ?? '')), $search)) {
                    return false;
                }

                return match ($this->customerStatusFilter) {
                    'near' => ($row['status'] ?? 'undefined') === 'near',
                    'within' => ($row['status'] ?? 'undefined') === 'within',
                    'blocked' => ($row['status'] ?? 'undefined') === 'over',
                    default => true,
                };
            },
        );

        return array_values($filtered);
    }

    /**
     * Purpose: Remove rows that do not match the user filters.
     * Inputs: The candidate user rows.
     * Returns: The filtered rows.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterUserRows(array $rows): array
    {
        $search = trim(Str::lower($this->userSearch));

        $filtered = array_filter(
            $rows,
            function (array $row) use ($search): bool {
                if ($search !== '') {
                    $haystack = Str::lower(implode(' ', [
                        (string) ($row['name'] ?? ''),
                        (string) ($row['email'] ?? ''),
                    ]));

                    if (! Str::contains($haystack, $search)) {
                        return false;
                    }
                }

                return match ($this->userStatusFilter) {
                    'blocked' => ($row['status'] ?? 'within') === 'blocked',
                    'near' => ($row['status'] ?? 'within') === 'near',
                    'within' => ($row['status'] ?? 'within') === 'within',
                    default => true,
                };
            },
        );

        return array_values($filtered);
    }

    /**
     * Purpose: Sort customer rows according to the active sort controls.
     * Inputs: The filtered customer rows.
     * Returns: The sorted rows.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortCustomerRows(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            $comparison = $this->compareCustomerRows($left, $right, $this->customerSortField);

            if ($comparison !== 0) {
                return $this->customerSortDirection === 'asc' ? $comparison : -$comparison;
            }

            return $this->compareText((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $rows;
    }

    /**
     * Purpose: Sort user rows according to the active sort controls.
     * Inputs: The filtered user rows.
     * Returns: The sorted rows.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortUserRows(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            $comparison = $this->compareUserRows($left, $right, $this->userSortField);

            if ($comparison !== 0) {
                return $this->userSortDirection === 'asc' ? $comparison : -$comparison;
            }

            $comparison = $this->compareText((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));

            if ($comparison === 0) {
                $comparison = $this->compareText((string) ($left['email'] ?? ''), (string) ($right['email'] ?? ''));
            }

            return $comparison;
        });

        return $rows;
    }

    /**
     * Purpose: Compare two customer rows for a given sort field.
     * Inputs: Two customer rows and the field key.
     * Returns: A three-way comparison result.
     * Side effects: None.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareCustomerRows(array $left, array $right, string $field): int
    {
        return match ($field) {
            'name' => $this->compareText((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')),
            'plan' => $this->compareText((string) ($left['plan'] ?? ''), (string) ($right['plan'] ?? '')),
            'status' => $this->compareRank(
                $this->customerStatusRank((string) ($left['status'] ?? 'undefined')),
                $this->customerStatusRank((string) ($right['status'] ?? 'undefined')),
            ),
            'usage_24h' => $this->compareNumber((int) ($left['periods']['24h'] ?? 0), (int) ($right['periods']['24h'] ?? 0)),
            'usage_7d' => $this->compareNumber((int) ($left['periods']['7d'] ?? 0), (int) ($right['periods']['7d'] ?? 0)),
            'usage_30d' => $this->compareNumber((int) ($left['periods']['30d'] ?? 0), (int) ($right['periods']['30d'] ?? 0)),
            'allowed' => $this->compareNumber((int) ($left['counts']['allowed'] ?? 0), (int) ($right['counts']['allowed'] ?? 0)),
            'blocked' => $this->compareNumber((int) ($left['counts']['blocked'] ?? 0), (int) ($right['counts']['blocked'] ?? 0)),
            'last_blocked_at' => $this->compareTimestamp($left['last_blocked_at'] ?? null, $right['last_blocked_at'] ?? null),
            default => $this->compareText((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')),
        };
    }

    /**
     * Purpose: Compare two user rows for a given sort field.
     * Inputs: Two user rows and the field key.
     * Returns: A three-way comparison result.
     * Side effects: None.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareUserRows(array $left, array $right, string $field): int
    {
        return match ($field) {
            'name' => $this->compareText((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')),
            'email' => $this->compareText((string) ($left['email'] ?? ''), (string) ($right['email'] ?? '')),
            'customer_name' => $this->compareText((string) ($left['customer_name'] ?? ''), (string) ($right['customer_name'] ?? '')),
            'status' => $this->compareRank(
                $this->userStatusRank((string) ($left['status'] ?? 'within')),
                $this->userStatusRank((string) ($right['status'] ?? 'within')),
            ),
            'usage_24h' => $this->compareNumber((int) ($left['periods']['24h'] ?? 0), (int) ($right['periods']['24h'] ?? 0)),
            'usage_7d' => $this->compareNumber((int) ($left['periods']['7d'] ?? 0), (int) ($right['periods']['7d'] ?? 0)),
            'usage_30d' => $this->compareNumber((int) ($left['periods']['30d'] ?? 0), (int) ($right['periods']['30d'] ?? 0)),
            'allowed' => $this->compareNumber((int) ($left['counts']['allowed'] ?? 0), (int) ($right['counts']['allowed'] ?? 0)),
            'blocked' => $this->compareNumber((int) ($left['counts']['blocked'] ?? 0), (int) ($right['counts']['blocked'] ?? 0)),
            'last_blocked_at' => $this->compareTimestamp($left['last_blocked_at'] ?? null, $right['last_blocked_at'] ?? null),
            default => $this->compareText((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')),
        };
    }

    /**
     * Purpose: Compare two plain text values in a locale-safe manner.
     * Inputs: The left and right text values.
     * Returns: A three-way comparison result.
     * Side effects: None.
     */
    private function compareText(string $left, string $right): int
    {
        return strnatcasecmp($left, $right);
    }

    /**
     * Purpose: Compare two integer values.
     * Inputs: The left and right numeric values.
     * Returns: A three-way comparison result.
     * Side effects: None.
     */
    private function compareNumber(int $left, int $right): int
    {
        return $left <=> $right;
    }

    /**
     * Purpose: Compare two rank values.
     * Inputs: The left and right rank values.
     * Returns: A three-way comparison result.
     * Side effects: None.
     */
    private function compareRank(int $left, int $right): int
    {
        return $left <=> $right;
    }

    /**
     * Purpose: Compare two timestamps stored in the report format.
     * Inputs: Two formatted timestamps or null.
     * Returns: A three-way comparison result.
     * Side effects: None.
     */
    private function compareTimestamp(?string $left, ?string $right): int
    {
        return $this->timestampValue($left) <=> $this->timestampValue($right);
    }

    /**
     * Purpose: Convert a report timestamp into a comparable integer.
     * Inputs: A report timestamp string or null.
     * Returns: A Unix timestamp, or zero when unavailable.
     * Side effects: None.
     */
    private function timestampValue(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }

        try {
            return Carbon::createFromFormat('d.m.Y H:i', $value)?->getTimestamp() ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Purpose: Resolve a rank for customer capacity statuses.
     * Inputs: The status key.
     * Returns: A comparable integer rank.
     * Side effects: None.
     */
    private function customerStatusRank(string $status): int
    {
        return match ($status) {
            'over' => 3,
            'near' => 2,
            'within' => 1,
            default => 0,
        };
    }

    /**
     * Purpose: Resolve a rank for user operational statuses.
     * Inputs: The status key.
     * Returns: A comparable integer rank.
     * Side effects: None.
     */
    private function userStatusRank(string $status): int
    {
        return match ($status) {
            'blocked' => 3,
            'near' => 2,
            'within' => 1,
            default => 0,
        };
    }

    /**
     * Purpose: Normalize a page-size selection to a supported value.
     * Inputs: The requested page size.
     * Returns: One of the supported page sizes.
     * Side effects: None.
     */
    private function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, $this->perPageOptions(), true) ? $perPage : 25;
    }
}
