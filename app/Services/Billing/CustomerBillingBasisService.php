<?php

namespace App\Services\Billing;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\InvoiceLog;
use Illuminate\Support\Collection;

/**
 * Purpose: Build a read-only billing basis snapshot for one customer.
 * Inputs: A customer model.
 * Returns: A structured view model for internal billing basis reporting.
 * Side effects: None.
 */
class CustomerBillingBasisService
{
    public const BASIS_STATUS_COMPLETE = 'complete';

    public const BASIS_STATUS_PARTIAL = 'partial';

    public const BASIS_STATUS_NOT_CALCULABLE = 'not_calculable';

    public const CALCULATION_STATUS_COMPLETE = 'complete';

    public const CALCULATION_STATUS_PARTIAL = 'partial';

    public const CALCULATION_STATUS_NOT_CALCULABLE = 'not_calculable';

    public const RECONCILIATION_STATUS_CAN = 'can_reconcile';

    public const RECONCILIATION_STATUS_PARTIAL = 'partial';

    public const RECONCILIATION_STATUS_CANNOT = 'cannot_reconcile';

    public const BILLING_READINESS_STATUS_READY = 'ready';

    public const BILLING_READINESS_STATUS_ATTENTION = 'attention';

    public const BILLING_READINESS_STATUS_BLOCKED = 'blocked';

    public const BILLING_READINESS_STATUS_NOT_CALCULABLE = 'not_calculable';

    private const BILLING_READINESS_CHECK_OK = 'ok';

    private const BILLING_READINESS_CHECK_ATTENTION = 'attention';

    private const BILLING_READINESS_CHECK_BLOCKED = 'blocked';

    private const BILLING_READINESS_CHECK_NOT_CALCULABLE = 'not_calculable';

    private const ACTIVE_STATUSES = ['active', 'pending_cancel'];

    private const RECENT_INVOICE_COUNT = 12;

    /**
     * Purpose: Build the full billing basis snapshot for a customer.
     * Inputs: The customer whose billing basis should be analyzed.
     * Returns: A stable array with customer, summary, line groups, invoices, and reconciliation data.
     * Side effects: Executes read-only database queries.
     *
     * @return array{
     *     customer: array<string, mixed>,
     *     summary: array<string, mixed>,
     *     billing_readiness: array<string, mixed>,
     *     line_groups: array<string, array<string, mixed>>,
     *     invoices: array<string, mixed>,
     *     reconciliation: array<string, mixed>
     * }
     */
    public function build(Customer $customer): array
    {
        $customer = $customer->fresh([
            'billingLines.billingProduct',
            'billingLines.billingPrice.product',
            'billingLines.user',
            'invoiceLogs',
        ]) ?? $customer;

        $lineRows = $this->buildLineRows(
            $customer->billingLines->sortBy(function (CustomerBillingLine $line): string {
                $startsAt = $line->starts_at?->timestamp ?? 0;
                $createdAt = $line->created_at?->timestamp ?? 0;

                return sprintf('%010d-%010d-%010d', $startsAt, $createdAt, $line->id);
            })->values(),
        );

        $activeLineRows = collect($lineRows)
            ->filter(fn (array $line): bool => in_array($line['status'] ?? null, self::ACTIVE_STATUSES, true))
            ->values();

        $historicalLineRows = collect($lineRows)
            ->reject(fn (array $line): bool => in_array($line['status'] ?? null, self::ACTIVE_STATUSES, true))
            ->values();

        $lineGroups = $this->groupLineRows($activeLineRows, $historicalLineRows);
        $summary = $this->buildSummary($customer, $activeLineRows);
        $invoices = $this->buildInvoices($customer->invoiceLogs->sortByDesc('invoice_date'), collect($lineRows));
        $reconciliation = $this->buildReconciliation(collect($lineRows), collect($invoices['recent_invoices'] ?? []), $customer->invoiceLogs);
        $billingReadiness = $this->buildBillingReadiness($customer, $activeLineRows, $customer->invoiceLogs, $summary, $invoices, $reconciliation);

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'plan_label' => $customer->planName(),
                'subscription_plan' => $customer->subscription_plan,
                'billing_interval' => $customer->billing_interval,
                'billing_interval_label' => $this->billingIntervalLabel($customer->billing_interval),
                'billing_discount_percent' => (float) ($customer->billing_discount_percent ?? 0),
                'included_users' => $customer->included_users,
                'included_ai_credits' => $customer->included_ai_credits,
            ],
            'summary' => $summary,
            'billing_readiness' => $billingReadiness,
            'line_groups' => $lineGroups,
            'invoices' => $invoices,
            'reconciliation' => $reconciliation,
        ];
    }

    /**
     * Purpose: Convert all customer billing lines into a normalized analysis payload.
     * Inputs: A collection of billing line models.
     * Returns: A list of line analysis rows.
     * Side effects: None.
     *
     * @param Collection<int, CustomerBillingLine> $lines
     * @return array<int, array<string, mixed>>
     */
    private function buildLineRows(Collection $lines): array
    {
        return $lines
            ->map(fn (CustomerBillingLine $line): array => $this->buildLineRow($line))
            ->all();
    }

    /**
     * Purpose: Build a single normalized billing line payload.
     * Inputs: The billing line model.
     * Returns: A structured line row ready for grouping and rendering.
     * Side effects: None.
     */
    private function buildLineRow(CustomerBillingLine $line): array
    {
        $price = $line->billingPrice;
        $product = $line->billingProduct;
        $metadata = is_array($line->metadata ?? null) ? $line->metadata : [];
        $status = (string) ($line->status ?? 'draft');
        $isActiveBasisLine = in_array($status, self::ACTIVE_STATUSES, true);

        [$groupKey, $groupLabel] = $this->resolveGroup($line, $price, $product, $isActiveBasisLine);

        $quantity = is_numeric($line->quantity) ? (int) $line->quantity : null;
        $source = (string) ($line->source ?? 'system');

        $standardAmount = $this->resolveStandardAmount($price, $metadata);
        $standardCurrency = $this->resolveStandardCurrency($price, $metadata);
        $standardInterval = $this->resolveStandardInterval($price, $metadata);
        $customAmount = $this->resolveCustomAmount($metadata);
        $customCurrency = $this->resolveCustomCurrency($price, $metadata, $standardCurrency);

        $pricingAmount = null;
        $currency = null;
        $warnings = [];

        if ($source === CustomerBillingLine::SOURCE_CUSTOMER_PRICE) {
            $currency = $customCurrency;

            if ($customAmount !== null) {
                $pricingAmount = $customAmount;
            } else {
                $warnings[] = 'Kundespesifikk pris mangler avtalt beløp.';
            }
        } else {
            $pricingAmount = $standardAmount;
            $currency = $standardCurrency;

            if ($pricingAmount === null) {
                $warnings[] = 'Linjen mangler prisgrunnlag.';
            }
        }

        if ($quantity === null || $quantity <= 0) {
            $warnings[] = 'Linjen mangler gyldig antall.';
        }

        if ($currency === null) {
            $warnings[] = 'Linjen mangler valuta.';
        }

        $calculationStatus = $this->resolveCalculationStatus($pricingAmount, $currency, $quantity, $source);
        $lineTotal = $calculationStatus === self::CALCULATION_STATUS_COMPLETE
            ? ($pricingAmount * $quantity)
            : null;

        if ($source === CustomerBillingLine::SOURCE_CUSTOMER_PRICE && $customAmount !== null && $standardAmount !== null) {
            $differenceAmount = $standardAmount - $customAmount;
        } else {
            $differenceAmount = null;
        }

        if ($calculationStatus !== self::CALCULATION_STATUS_COMPLETE) {
            $lineWarnings = $warnings;
            if ($source === CustomerBillingLine::SOURCE_CUSTOMER_PRICE && $customAmount === null) {
                $lineWarnings[] = 'Avtalt pris kan ikke beregnes sikkert.';
            }
        } else {
            $lineWarnings = $warnings;
        }

        return [
            'id' => $line->id,
            'group_key' => $groupKey,
            'group_label' => $groupLabel,
            'description' => $line->description,
            'source' => $source,
            'source_label' => $this->sourceLabel($source),
            'status' => $status,
            'status_label' => $this->lineStatusLabel($status),
            'quantity' => $quantity,
            'starts_at' => $line->starts_at?->toDateTimeString(),
            'ends_at' => $line->ends_at?->toDateTimeString(),
            'billing_product_name' => $product?->name,
            'billing_price_name' => $price?->name,
            'billing_price_unit_amount' => $standardAmount,
            'billing_price_unit_amount_label' => $this->moneyLabel($standardAmount, $standardCurrency),
            'billing_price_currency' => $standardCurrency,
            'billing_price_interval' => $standardInterval,
            'stripe_subscription_id' => $line->stripe_subscription_id,
            'stripe_subscription_item_id' => $line->stripe_subscription_item_id,
            'stripe_invoice_id' => $line->stripe_invoice_id,
            'metadata' => $metadata,
            'calculation_status' => $calculationStatus,
            'calculation_status_label' => $this->calculationStatusLabel($calculationStatus),
            'amount' => $pricingAmount,
            'amount_label' => $this->moneyLabel($pricingAmount, $currency),
            'currency' => $currency,
            'line_total' => $lineTotal,
            'line_total_label' => $this->moneyLabel($lineTotal, $currency),
            'warnings' => collect($lineWarnings ?? $warnings)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'standard_amount' => $standardAmount,
            'standard_amount_label' => $this->moneyLabel($standardAmount, $standardCurrency),
            'custom_amount' => $customAmount,
            'custom_amount_label' => $this->moneyLabel($customAmount, $customCurrency),
            'difference_amount' => $differenceAmount,
            'difference_amount_label' => $this->moneyLabel($differenceAmount, $customCurrency),
            'period_label' => $this->linePeriodLabel($line),
        ];
    }

    /**
     * Purpose: Build the grouped line collections for rendering.
     * Inputs: The active and historical line payloads.
     * Returns: A grouped array keyed by report section.
     * Side effects: None.
     *
     * @param Collection<int, array<string, mixed>> $activeLineRows
     * @param Collection<int, array<string, mixed>> $historicalLineRows
     * @return array<string, array<string, mixed>>
     */
    private function groupLineRows(Collection $activeLineRows, Collection $historicalLineRows): array
    {
        $groups = [
            'base_subscription' => ['label' => 'Grunnabonnement', 'lines' => []],
            'user_based_lines' => ['label' => 'Brukerbaserte linjer', 'lines' => []],
            'recurring_addons' => ['label' => 'Løpende tillegg', 'lines' => []],
            'one_time_charges' => ['label' => 'Engangslinjer', 'lines' => []],
            'customer_specific_prices' => ['label' => 'Kundespesifikke priser', 'lines' => []],
            'manual_or_other_lines' => ['label' => 'Manuelle eller øvrige linjer', 'lines' => []],
            'inactive_or_historical_lines' => ['label' => 'Historiske linjer', 'lines' => []],
        ];

        foreach ($activeLineRows as $lineRow) {
            $groupKey = (string) ($lineRow['group_key'] ?? 'manual_or_other_lines');

            if (! array_key_exists($groupKey, $groups)) {
                $groupKey = 'manual_or_other_lines';
            }

            $groups[$groupKey]['lines'][] = $lineRow;
        }

        $groups['inactive_or_historical_lines']['lines'] = $historicalLineRows->values()->all();

        foreach ($groups as $groupKey => $group) {
            $groups[$groupKey]['count'] = count($group['lines']);
        }

        return $groups;
    }

    /**
     * Purpose: Build the summary section for the billing basis.
     * Inputs: The customer and the active billing line rows.
     * Returns: Summary values and warnings for the page header cards.
     * Side effects: None.
     *
     * @param Collection<int, array<string, mixed>> $activeLineRows
     * @return array<string, mixed>
     */
    private function buildSummary(Customer $customer, Collection $activeLineRows): array
    {
        $basisWarnings = [];
        $calculableLineRows = $activeLineRows->filter(fn (array $line): bool => $line['calculation_status'] === self::CALCULATION_STATUS_COMPLETE);
        $partialLineRows = $activeLineRows->filter(fn (array $line): bool => $line['calculation_status'] === self::CALCULATION_STATUS_PARTIAL);
        $missingLineRows = $activeLineRows->filter(fn (array $line): bool => $line['calculation_status'] === self::CALCULATION_STATUS_NOT_CALCULABLE);

        if ($activeLineRows->isEmpty()) {
            $basisStatus = self::BASIS_STATUS_NOT_CALCULABLE;
            $basisWarnings[] = 'Kunden har ingen aktive interne billing-linjer.';
        } elseif ($calculableLineRows->count() === $activeLineRows->count()) {
            $currencies = $calculableLineRows
                ->pluck('currency')
                ->filter()
                ->unique()
                ->values();

            if ($currencies->count() === 1) {
                $basisStatus = self::BASIS_STATUS_COMPLETE;
            } else {
                $basisStatus = self::BASIS_STATUS_PARTIAL;
                $basisWarnings[] = 'Aktive linjer bruker mer enn én valuta eller mangler valuta.';
            }
        } elseif ($calculableLineRows->isEmpty()) {
            $basisStatus = self::BASIS_STATUS_NOT_CALCULABLE;
        } else {
            $basisStatus = self::BASIS_STATUS_PARTIAL;
        }

        if ($partialLineRows->isNotEmpty()) {
            $basisWarnings[] = 'Noen aktive linjer er bare delvis beregnbare.';
        }

        if ($missingLineRows->isNotEmpty()) {
            $basisWarnings[] = 'Noen aktive linjer mangler sikkert prisgrunnlag.';
        }

        if (filled($customer->subscription_plan) && $activeLineRows->isEmpty()) {
            $basisWarnings[] = 'Standard planpris kan ikke brukes som faktisk kundeavtale alene.';
        }

        $canCalculateExpectedTotal = $basisStatus === self::BASIS_STATUS_COMPLETE;
        $expectedTotalAmount = $canCalculateExpectedTotal
            ? (int) $calculableLineRows->sum(fn (array $line): int => (int) ($line['line_total'] ?? 0))
            : null;
        $expectedTotalCurrency = $canCalculateExpectedTotal
            ? $calculableLineRows->pluck('currency')->first()
            : null;

        $discountPercent = (float) ($customer->billing_discount_percent ?? 0);
        $discountAmount = $canCalculateExpectedTotal
            ? (int) round($expectedTotalAmount * ($discountPercent / 100))
            : null;
        $totalAfterDiscount = $canCalculateExpectedTotal
            ? max(0, $expectedTotalAmount - $discountAmount)
            : null;

        return [
            'basis_status' => $basisStatus,
            'basis_status_label' => $this->basisStatusLabel($basisStatus),
            'can_calculate_expected_total' => $canCalculateExpectedTotal,
            'expected_total_amount' => $expectedTotalAmount,
            'expected_total_currency' => $expectedTotalCurrency,
            'expected_total_label' => $this->moneyLabel($expectedTotalAmount, $expectedTotalCurrency),
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'discount_amount_label' => $this->moneyLabel($discountAmount, $expectedTotalCurrency),
            'total_after_discount' => $totalAfterDiscount,
            'total_after_discount_label' => $this->moneyLabel($totalAfterDiscount, $expectedTotalCurrency),
            'warnings' => collect($basisWarnings)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * Purpose: Build the invoice summary and recent invoice list.
     * Inputs: The customer's invoice logs.
     * Returns: Latest invoice, recent invoices, and a total for the visible period.
     * Side effects: None.
     *
     * @param Collection<int, InvoiceLog> $invoiceLogs
     * @param Collection<int, array<string, mixed>> $lineRows
     * @return array<string, mixed>
     */
    private function buildInvoices(Collection $invoiceLogs, Collection $lineRows): array
    {
        $recentInvoiceLogs = $invoiceLogs
            ->sortByDesc('invoice_date')
            ->take(self::RECENT_INVOICE_COUNT)
            ->values();

        $recentInvoices = $recentInvoiceLogs->map(function (InvoiceLog $invoice) use ($lineRows): array {
            $linkedLineCount = $lineRows
                ->filter(fn (array $line): bool => filled($line['stripe_invoice_id'] ?? null))
                ->filter(fn (array $line): bool => (string) $line['stripe_invoice_id'] === $invoice->stripe_invoice_id)
                ->count();

            return [
                'id' => $invoice->id,
                'stripe_invoice_id' => $invoice->stripe_invoice_id,
                'status' => $invoice->status,
                'status_label' => $this->invoiceStatusLabel($invoice->status),
                'amount_paid' => $invoice->amount_paid,
                'amount_paid_label' => $invoice->amountFormatted(),
                'currency' => strtoupper((string) $invoice->currency),
                'invoice_date' => $invoice->invoice_date?->toDateTimeString(),
                'invoice_date_label' => $invoice->invoice_date?->format('d.m.Y H:i'),
                'line_link_count' => $linkedLineCount,
                'line_link_label' => $this->invoiceLineLinkLabel($linkedLineCount),
            ];
        })->all();

        $currency = $recentInvoiceLogs
            ->pluck('currency')
            ->filter()
            ->map(fn ($value): string => strtoupper((string) $value))
            ->unique()
            ->values();

        $invoiceTotal = $recentInvoiceLogs->isNotEmpty() && $currency->count() === 1
            ? (int) $recentInvoiceLogs->sum('amount_paid')
            : null;

        $latestInvoice = $recentInvoices[0] ?? null;

        return [
            'latest_invoice' => $latestInvoice,
            'recent_invoices' => $recentInvoices,
            'invoice_total_in_period_if_available' => $invoiceTotal,
            'invoice_total_currency' => $currency->count() === 1 ? $currency->first() : null,
            'invoice_total_label' => $this->moneyLabel($invoiceTotal, $currency->count() === 1 ? $currency->first() : null),
            'invoice_period_label' => 'Siste '.self::RECENT_INVOICE_COUNT.' fakturaer',
            'has_invoice_data' => $invoiceLogs->isNotEmpty(),
        ];
    }

    /**
     * Purpose: Build the reconciliation status for invoice logs and internal billing lines.
     * Inputs: Normalized line rows, the visible invoice rows, and the raw invoice logs.
     * Returns: Boolean and label data for the reconciliation section.
     * Side effects: None.
     *
     * @param Collection<int, array<string, mixed>> $lineRows
     * @param Collection<int, array<string, mixed>> $recentInvoices
     * @param Collection<int, InvoiceLog> $invoiceLogs
     * @return array<string, mixed>
     */
    private function buildReconciliation(Collection $lineRows, Collection $recentInvoices, Collection $invoiceLogs): array
    {
        if ($invoiceLogs->isEmpty()) {
            return [
                'has_invoice_data' => false,
                'has_line_to_invoice_links' => false,
                'can_reconcile_lines_to_invoice' => false,
                'reconciliation_status' => self::RECONCILIATION_STATUS_CANNOT,
                'reconciliation_status_label' => 'Kan ikke avstemmes',
                'warnings' => ['Ingen invoice_logs er tilgjengelig for denne kunden.'],
            ];
        }

        $invoiceIds = $invoiceLogs
            ->pluck('stripe_invoice_id')
            ->filter()
            ->unique()
            ->values();

        $linkedLines = $lineRows
            ->filter(fn (array $line): bool => filled($line['stripe_invoice_id'] ?? null))
            ->filter(fn (array $line): bool => $invoiceIds->contains((string) $line['stripe_invoice_id']))
            ->values();

        $matchedInvoiceCount = $linkedLines
            ->pluck('stripe_invoice_id')
            ->filter()
            ->unique()
            ->count();

        $invoiceCount = $invoiceLogs->count();

        if ($matchedInvoiceCount === 0) {
            $status = self::RECONCILIATION_STATUS_CANNOT;
            $label = 'Kan ikke avstemmes';
            $warnings = ['invoice_logs finnes, men ingen interne linjer kan kobles direkte.'];
        } elseif ($matchedInvoiceCount < $invoiceCount) {
            $status = self::RECONCILIATION_STATUS_PARTIAL;
            $label = 'Delvis avstemming';
            $warnings = ['Noen invoice_logs har direkte linjekobling, men ikke alle.'];
        } else {
            $status = self::RECONCILIATION_STATUS_CAN;
            $label = 'Kan avstemmes';
            $warnings = [];
        }

        return [
            'has_invoice_data' => true,
            'has_line_to_invoice_links' => $linkedLines->isNotEmpty(),
            'can_reconcile_lines_to_invoice' => $status === self::RECONCILIATION_STATUS_CAN,
            'reconciliation_status' => $status,
            'reconciliation_status_label' => $label,
            'linked_line_count' => $linkedLines->count(),
            'matched_invoice_count' => $matchedInvoiceCount,
            'invoice_count' => $invoiceCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Purpose: Build a read-only billing readiness status for the customer.
     * Inputs: The customer, active line rows, invoice logs, and the existing basis analysis payloads.
     * Returns: Status, summary, checks, and follow-up items that describe what is missing.
     * Side effects: None.
     *
     * @param Collection<int, array<string, mixed>> $activeLineRows
     * @param Collection<int, InvoiceLog> $invoiceLogs
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $invoices
     * @param array<string, mixed> $reconciliation
     * @return array<string, mixed>
     */
    private function buildBillingReadiness(
        Customer $customer,
        Collection $activeLineRows,
        Collection $invoiceLogs,
        array $summary,
        array $invoices,
        array $reconciliation,
    ): array {
        $hasActiveInternalLines = $activeLineRows->isNotEmpty();
        $basisStatus = (string) data_get($summary, 'basis_status', self::BASIS_STATUS_NOT_CALCULABLE);
        $hasCalculableInternalLines = $basisStatus === self::BASIS_STATUS_COMPLETE;
        $hasStripeCustomer = filled($customer->stripe_id);
        $hasActiveStripeSubscription = $customer->subscribed('default');
        $hasInvoiceLogs = $invoiceLogs->isNotEmpty();
        $hasOneTimeLines = $activeLineRows->contains(function (array $line): bool {
            return ($line['group_key'] ?? null) === 'one_time_charges';
        });
        $hasInvoiceLineLinks = (bool) data_get($reconciliation, 'has_line_to_invoice_links', false);
        $canReconcile = (bool) data_get($reconciliation, 'can_reconcile_lines_to_invoice', false);

        $checks = [
            $this->buildReadinessCheck(
                'internal_lines',
                'Interne linjer',
                $hasActiveInternalLines
                    ? self::BILLING_READINESS_CHECK_OK
                    : ($hasActiveStripeSubscription
                        ? self::BILLING_READINESS_CHECK_ATTENTION
                        : self::BILLING_READINESS_CHECK_NOT_CALCULABLE),
                $hasActiveInternalLines
                    ? 'Aktive interne linjer er registrert.'
                    : ($hasActiveStripeSubscription
                        ? 'Avtale finnes, men interne linjer mangler.'
                        : 'Ingen aktive interne linjer er registrert.'),
            ),
            $this->buildReadinessCheck(
                'line_calculation',
                'Prisgrunnlag',
                ! $hasActiveInternalLines || ! $hasCalculableInternalLines
                    ? self::BILLING_READINESS_CHECK_NOT_CALCULABLE
                    : self::BILLING_READINESS_CHECK_OK,
                ! $hasActiveInternalLines
                    ? 'Ingen aktive interne linjer kan beregnes ennå.'
                    : ($hasCalculableInternalLines
                        ? 'Interne linjer kan beregnes sikkert.'
                        : 'Interne linjer finnes, men hele grunnlaget kan ikke beregnes sikkert.'),
            ),
            $this->buildReadinessCheck(
                'stripe_customer',
                'Fakturakunde',
                $hasStripeCustomer
                    ? self::BILLING_READINESS_CHECK_OK
                    : self::BILLING_READINESS_CHECK_BLOCKED,
                $hasStripeCustomer
                    ? 'Kunden er koblet til fakturasystemet.'
                    : 'Kunden er ikke koblet til fakturasystemet.',
            ),
            $this->buildReadinessCheck(
                'stripe_subscription',
                'Avtale',
                ! $hasStripeCustomer
                    ? self::BILLING_READINESS_CHECK_BLOCKED
                    : ($hasActiveStripeSubscription
                        ? self::BILLING_READINESS_CHECK_OK
                        : self::BILLING_READINESS_CHECK_ATTENTION),
                ! $hasStripeCustomer
                    ? 'Fakturakunde mangler, så avtalen kan ikke brukes.'
                    : ($hasActiveStripeSubscription
                        ? 'Aktiv avtale finnes.'
                        : 'Kunden har fakturakunde, men ingen aktiv avtale.'),
            ),
            $this->buildReadinessCheck(
                'invoice_logs',
                'Faktura',
                $hasInvoiceLogs
                    ? self::BILLING_READINESS_CHECK_OK
                    : self::BILLING_READINESS_CHECK_ATTENTION,
                $hasInvoiceLogs
                    ? 'Faktura er registrert i fakturaloggen.'
                    : ($hasOneTimeLines
                        ? 'Interne engangslinjer finnes, men ingen faktura er registrert.'
                        : 'Ingen faktura er registrert for denne kunden.'),
            ),
            $this->buildReadinessCheck(
                'reconciliation',
                'Fakturakobling',
                $canReconcile
                    ? self::BILLING_READINESS_CHECK_OK
                    : ($hasInvoiceLogs
                        ? self::BILLING_READINESS_CHECK_ATTENTION
                        : self::BILLING_READINESS_CHECK_NOT_CALCULABLE),
                $canReconcile
                    ? 'Interne linjer kan kobles mot faktura.'
                    : ($hasInvoiceLogs
                        ? 'Faktura finnes, men interne linjer kan ikke kobles direkte.'
                        : 'Ingen faktura er tilgjengelig for kobling.'),
            ),
        ];

        $followUpItems = collect($checks)
            ->filter(fn (array $check): bool => ($check['status'] ?? null) !== self::BILLING_READINESS_CHECK_OK)
            ->pluck('message')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($hasActiveInternalLines && ! $hasCalculableInternalLines) {
            $status = self::BILLING_READINESS_STATUS_NOT_CALCULABLE;
            $summaryText = 'Interne linjer finnes, men hele grunnlaget kan ikke beregnes sikkert.';
        } elseif (! $hasActiveInternalLines) {
            if ($hasActiveStripeSubscription) {
                $status = self::BILLING_READINESS_STATUS_ATTENTION;
                $summaryText = 'Avtale finnes, men interne linjer mangler.';
            } else {
                $status = self::BILLING_READINESS_STATUS_NOT_CALCULABLE;
                $summaryText = 'Ingen aktive interne linjer er registrert.';
            }
        } elseif (! $hasStripeCustomer) {
            $status = self::BILLING_READINESS_STATUS_BLOCKED;
            $summaryText = 'Interne linjer er beregnbare, men kunden er ikke koblet til fakturasystemet.';
        } elseif (! $hasActiveStripeSubscription) {
            $status = self::BILLING_READINESS_STATUS_ATTENTION;
            $summaryText = 'Kunden har fakturakunde, men ingen aktiv avtale.';
        } elseif ($hasOneTimeLines && ! $hasInvoiceLogs) {
            $status = self::BILLING_READINESS_STATUS_ATTENTION;
            $summaryText = 'Interne engangslinjer finnes, men ingen faktura er registrert.';
        } elseif ($hasInvoiceLogs && ! $hasInvoiceLineLinks) {
            $status = self::BILLING_READINESS_STATUS_ATTENTION;
            $summaryText = 'Faktura finnes, men interne linjer kan ikke kobles direkte.';
        } elseif ($canReconcile) {
            $status = self::BILLING_READINESS_STATUS_READY;
            $summaryText = 'Interne linjer kan kobles mot faktura.';
        } else {
            $status = self::BILLING_READINESS_STATUS_ATTENTION;
            $summaryText = 'Interne linjer og Stripe er ikke fullt avklart ennå.';
        }

        if ($status === self::BILLING_READINESS_STATUS_READY && $followUpItems === []) {
            $followUpItems = ['Kontroller fakturastatus og betaling i Stripe ved behov.'];
        }

        return [
            'status' => $status,
            'status_label' => $this->billingReadinessStatusLabel($status),
            'severity' => $this->billingReadinessSeverity($status),
            'summary' => $summaryText,
            'checks' => $checks,
            'follow_up_items' => $followUpItems,
        ];
    }

    /**
     * Purpose: Build a standardized readiness check payload.
     * Inputs: The check key, label, status, and message.
     * Returns: A normalized check array for the view layer.
     * Side effects: None.
     *
     * @return array<string, mixed>
     */
    private function buildReadinessCheck(string $key, string $label, string $status, string $message): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $this->billingReadinessCheckStatusLabel($status),
            'message' => $message,
        ];
    }

    /**
     * Purpose: Determine the billing group for a line.
     * Inputs: The line model and related price/product models.
     * Returns: A stable group key and label pair.
     * Side effects: None.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveGroup(CustomerBillingLine $line, ?BillingPrice $price, ?BillingProduct $product, bool $isActiveBasisLine): array
    {
        if (! $isActiveBasisLine) {
            return ['inactive_or_historical_lines', 'Historiske linjer'];
        }

        if ($line->source === CustomerBillingLine::SOURCE_CUSTOMER_PRICE) {
            return ['customer_specific_prices', 'Kundespesifikke priser'];
        }

        if ($price instanceof BillingPrice && $price->interval === BillingPrice::INTERVAL_ONE_TIME) {
            return ['one_time_charges', 'Engangslinjer'];
        }

        if ($product instanceof BillingProduct && $product->category === BillingProduct::CATEGORY_BASE_PLAN) {
            return ['base_subscription', 'Grunnabonnement'];
        }

        if (
            $product instanceof BillingProduct
            && in_array($product->category, [BillingProduct::CATEGORY_USER_SEAT, BillingProduct::CATEGORY_USER_SERVICE], true)
        ) {
            return ['user_based_lines', 'Brukerbaserte linjer'];
        }

        if ($product instanceof BillingProduct && $product->category === BillingProduct::CATEGORY_ADDON) {
            return ['recurring_addons', 'Løpende tillegg'];
        }

        if ($line->user_id !== null) {
            return ['user_based_lines', 'Brukerbaserte linjer'];
        }

        return ['manual_or_other_lines', 'Manuelle eller øvrige linjer'];
    }

    /**
     * Purpose: Resolve the calculation status for a billing line.
     * Inputs: The derived amount, currency, quantity, and billing source.
     * Returns: A stable calculation status string.
     * Side effects: None.
     */
    private function resolveCalculationStatus(?int $amount, ?string $currency, ?int $quantity, string $source): string
    {
        if ($source === CustomerBillingLine::SOURCE_CUSTOMER_PRICE && $amount === null) {
            return self::CALCULATION_STATUS_NOT_CALCULABLE;
        }

        if ($amount === null) {
            return self::CALCULATION_STATUS_NOT_CALCULABLE;
        }

        if ($currency === null || $quantity === null || $quantity <= 0) {
            return self::CALCULATION_STATUS_PARTIAL;
        }

        return self::CALCULATION_STATUS_COMPLETE;
    }

    /**
     * Purpose: Resolve the standard unit amount from the price relation or metadata snapshot.
     * Inputs: The related billing price and the line metadata.
     * Returns: The best available standard amount, or null when absent.
     * Side effects: None.
     */
    private function resolveStandardAmount(?BillingPrice $price, array $metadata): ?int
    {
        $standardAmount = $price?->unit_amount ?? data_get($metadata, 'standard_unit_amount');

        return is_numeric($standardAmount) ? (int) $standardAmount : null;
    }

    /**
     * Purpose: Resolve the standard currency from the price relation or metadata snapshot.
     * Inputs: The related billing price and the line metadata.
     * Returns: The best available currency code, or null when absent.
     * Side effects: None.
     */
    private function resolveStandardCurrency(?BillingPrice $price, array $metadata): ?string
    {
        return $this->normalizeCurrency($price?->currency ?? data_get($metadata, 'standard_currency'));
    }

    /**
     * Purpose: Resolve the standard interval from the price relation or metadata snapshot.
     * Inputs: The related billing price and the line metadata.
     * Returns: The best available interval, or null when absent.
     * Side effects: None.
     */
    private function resolveStandardInterval(?BillingPrice $price, array $metadata): ?string
    {
        $interval = $price?->interval ?? data_get($metadata, 'standard_interval');

        return filled($interval) ? (string) $interval : null;
    }

    /**
     * Purpose: Resolve the custom unit amount from line metadata.
     * Inputs: The line metadata.
     * Returns: The agreed amount, or null when absent.
     * Side effects: None.
     */
    private function resolveCustomAmount(array $metadata): ?int
    {
        $customAmount = data_get($metadata, 'custom_unit_amount');

        return is_numeric($customAmount) ? (int) $customAmount : null;
    }

    /**
     * Purpose: Resolve the custom currency from line metadata or fallback standard currency.
     * Inputs: The price relation, the line metadata, and the standard currency.
     * Returns: The agreed currency, or null when absent.
     * Side effects: None.
     */
    private function resolveCustomCurrency(?BillingPrice $price, array $metadata, ?string $standardCurrency): ?string
    {
        $currency = data_get($metadata, 'custom_currency') ?? $price?->currency ?? data_get($metadata, 'standard_currency') ?? $standardCurrency;

        return $this->normalizeCurrency($currency);
    }

    /**
     * Purpose: Convert a money amount to a safe display string.
     * Inputs: A minor-unit amount and its currency.
     * Returns: A formatted string or the not-calculable marker.
     * Side effects: None.
     */
    private function moneyLabel(?int $amount, ?string $currency): string
    {
        if (! is_int($amount) || $currency === null || $currency === '') {
            return 'Ikke beregnbar';
        }

        return number_format($amount / 100, 0, ',', ' ') . ' ' . strtoupper($currency);
    }

    /**
     * Purpose: Convert a currency value into a normalized uppercase code.
     * Inputs: The raw currency value.
     * Returns: An uppercase currency code or null.
     * Side effects: None.
     */
    private function normalizeCurrency(mixed $currency): ?string
    {
        if (! is_string($currency) && ! is_numeric($currency)) {
            return null;
        }

        $currency = trim((string) $currency);

        return $currency === '' ? null : strtoupper($currency);
    }

    /**
     * Purpose: Convert a billing interval to a human-readable label.
     * Inputs: The raw interval key.
     * Returns: A Norwegian label.
     * Side effects: None.
     */
    private function billingIntervalLabel(?string $interval): string
    {
        return match ($interval) {
            BillingPrice::INTERVAL_YEARLY => 'Årlig',
            BillingPrice::INTERVAL_ONE_TIME => 'Engangs',
            BillingPrice::INTERVAL_MONTHLY => 'Månedlig',
            default => filled($interval) ? ucfirst((string) $interval) : 'Ikke satt',
        };
    }

    /**
     * Purpose: Convert a billing source into a human-readable label.
     * Inputs: The raw source key.
     * Returns: A Norwegian label.
     * Side effects: None.
     */
    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'webhook' => 'Stripe',
            'system', 'admin', 'manual' => 'Procynia',
            CustomerBillingLine::SOURCE_CUSTOMER_PRICE => 'Kundespesifikk pris',
            default => filled($source) ? ucfirst($source) : 'Ukjent',
        };
    }

    /**
     * Purpose: Convert a billing line status into a Norwegian label.
     * Inputs: The raw status key.
     * Returns: A Norwegian label.
     * Side effects: None.
     */
    private function lineStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktiv',
            'pending_cancel' => 'Avsluttes',
            'ended', 'cancelled' => 'Historisk',
            'draft' => 'Kladd',
            default => filled($status) ? ucfirst($status) : 'Ukjent',
        };
    }

    /**
     * Purpose: Convert a calculation status into a Norwegian label.
     * Inputs: The raw calculation status key.
     * Returns: A Norwegian label.
     * Side effects: None.
     */
    private function calculationStatusLabel(string $status): string
    {
        return match ($status) {
            self::CALCULATION_STATUS_COMPLETE => 'Beregnet',
            self::CALCULATION_STATUS_PARTIAL => 'Delvis beregnbar',
            self::CALCULATION_STATUS_NOT_CALCULABLE => 'Ikke beregnbar',
            default => filled($status) ? ucfirst($status) : 'Ukjent',
        };
    }

    /**
     * Purpose: Convert a basis status into a Norwegian label.
     * Inputs: The raw basis status key.
     * Returns: A Norwegian label.
     * Side effects: None.
     */
    private function basisStatusLabel(string $status): string
    {
        return match ($status) {
            self::BASIS_STATUS_COMPLETE => 'Beregnbar for interne linjer',
            self::BASIS_STATUS_PARTIAL => 'Delvis beregnbar',
            self::BASIS_STATUS_NOT_CALCULABLE => 'Ikke beregnbar',
            default => filled($status) ? ucfirst($status) : 'Ukjent',
        };
    }

    /**
     * Purpose: Convert an invoice status into a Norwegian label.
     * Inputs: The raw invoice status key.
     * Returns: A Norwegian label.
     * Side effects: None.
     */
    private function invoiceStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Betalt',
            'open' => 'Åpen',
            'void' => 'Annullert',
            'uncollectible' => 'Uinndrivelig',
            default => filled($status) ? ucfirst($status) : 'Ukjent',
        };
    }

    /**
     * Purpose: Convert a line-link count into a readable invoice linkage label.
     * Inputs: The number of matching internal lines.
     * Returns: A Norwegian label for the invoice row.
     * Side effects: None.
     */
    private function invoiceLineLinkLabel(int $linkedLineCount): string
    {
        if ($linkedLineCount <= 0) {
            return 'Ingen linjekobling';
        }

        if ($linkedLineCount === 1) {
            return 'Direkte kobling';
        }

        return $linkedLineCount . ' linjer koblet';
    }

    /**
     * Purpose: Convert a billing readiness status into a Norwegian label.
     * Inputs: The raw readiness status key.
     * Returns: A Norwegian label for the page badge.
     * Side effects: None.
     */
    private function billingReadinessStatusLabel(string $status): string
    {
        return match ($status) {
            self::BILLING_READINESS_STATUS_READY => 'Klar for oppfølging',
            self::BILLING_READINESS_STATUS_ATTENTION => 'Må følges opp',
            self::BILLING_READINESS_STATUS_BLOCKED => 'Ikke faktureringsklar',
            self::BILLING_READINESS_STATUS_NOT_CALCULABLE => 'Ikke beregnbar',
            default => filled($status) ? ucfirst($status) : 'Ukjent',
        };
    }

    /**
     * Purpose: Convert a billing readiness status into a severity token.
     * Inputs: The raw readiness status key.
     * Returns: A small severity token used by the view for badge styling.
     * Side effects: None.
     */
    private function billingReadinessSeverity(string $status): string
    {
        return match ($status) {
            self::BILLING_READINESS_STATUS_READY => 'success',
            self::BILLING_READINESS_STATUS_ATTENTION => 'warning',
            self::BILLING_READINESS_STATUS_BLOCKED => 'danger',
            self::BILLING_READINESS_STATUS_NOT_CALCULABLE => 'gray',
            default => 'gray',
        };
    }

    /**
     * Purpose: Convert a readiness check status into a short label.
     * Inputs: The raw check status key.
     * Returns: A compact label for check badges.
     * Side effects: None.
     */
    private function billingReadinessCheckStatusLabel(string $status): string
    {
        return match ($status) {
            self::BILLING_READINESS_CHECK_OK => 'OK',
            self::BILLING_READINESS_CHECK_ATTENTION => 'Må følges opp',
            self::BILLING_READINESS_CHECK_BLOCKED => 'Blokkert',
            self::BILLING_READINESS_CHECK_NOT_CALCULABLE => 'Ikke beregnbar',
            default => filled($status) ? ucfirst($status) : 'Ukjent',
        };
    }

    /**
     * Purpose: Build a readable period label for a billing line.
     * Inputs: The billing line model.
     * Returns: A compact start/end label for display.
     * Side effects: None.
     */
    private function linePeriodLabel(CustomerBillingLine $line): string
    {
        $start = $line->starts_at?->format('d.m.Y');
        $end = $line->ends_at?->format('d.m.Y');

        if (! $start && ! $end) {
            return 'Ingen periode registrert';
        }

        if ($start && $end) {
            return "{$start} til {$end}";
        }

        if ($start) {
            return "Fra {$start}";
        }

        return "Til {$end}";
    }
}
