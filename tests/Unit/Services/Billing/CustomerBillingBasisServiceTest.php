<?php

namespace Tests\Unit\Services\Billing;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\InvoiceLog;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Billing\CustomerBillingBasisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerBillingBasisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_it_marks_customer_without_billing_lines_as_not_calculable(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Tom Kunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);

        $report = $this->buildReport($customer);
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_NOT_CALCULABLE, $report['summary']['basis_status']);
        $this->assertFalse($report['summary']['can_calculate_expected_total']);
        $this->assertNull($report['summary']['expected_total_amount']);
        $this->assertContains('Standard planpris kan ikke brukes som faktisk kundeavtale alene.', $report['summary']['warnings']);
        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_NOT_CALCULABLE, $readiness['status']);
        $this->assertSame('Ingen aktive interne linjer er registrert.', $readiness['summary']);
        $this->assertContains('Ingen aktive interne linjer er registrert.', $readiness['follow_up_items']);
    }

    public function test_it_calculates_line_totals_for_active_standard_line(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Standard Linje AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $product = $this->createProduct('base_plan_standard', 'Standard', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_standard_monthly', 'Standard — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Standard abonnement',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
            'starts_at' => now(),
        ]);

        $billingLinesBefore = CustomerBillingLine::query()->count();
        $invoiceLogsBefore = InvoiceLog::query()->count();
        $report = $this->buildReport($customer);
        $line = $report['line_groups']['base_subscription']['lines'][0];

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_COMPLETE, $report['summary']['basis_status']);
        $this->assertTrue($report['summary']['can_calculate_expected_total']);
        $this->assertSame(199000, $report['summary']['expected_total_amount']);
        $this->assertSame('NOK', $report['summary']['expected_total_currency']);
        $this->assertSame(199000, $line['amount']);
        $this->assertSame(199000, $line['line_total']);
        $this->assertSame(CustomerBillingBasisService::CALCULATION_STATUS_COMPLETE, $line['calculation_status']);
        $this->assertSame($billingLinesBefore, CustomerBillingLine::query()->count());
        $this->assertSame($invoiceLogsBefore, InvoiceLog::query()->count());
    }

    public function test_it_sums_multiple_active_lines_correctly(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Flere Linjer AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);

        $baseProduct = $this->createProduct('base_plan_multi', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $basePrice = $this->createPrice($baseProduct, 'base_plan_multi_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $seatProduct = $this->createProduct('user_seat_multi', 'Ekstra bruker', BillingProduct::CATEGORY_USER_SEAT);
        $seatPrice = $this->createPrice($seatProduct, 'user_seat_multi_monthly', 'Ekstra bruker — Månedlig', BillingPrice::INTERVAL_MONTHLY, 49000);
        $user = $this->createUser($customer, 'Bruker Linje AS');

        $addonProduct = $this->createProduct('addon_multi', 'Tillegg', BillingProduct::CATEGORY_ADDON);
        $addonPrice = $this->createPrice($addonProduct, 'addon_multi_monthly', 'Tillegg — Månedlig', BillingPrice::INTERVAL_MONTHLY, 25000);

        $this->createLine($customer, $baseProduct, $basePrice, [
            'description' => 'Grunnabonnement',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $this->createLine($customer, $seatProduct, $seatPrice, [
            'description' => 'Ekstra bruker',
            'quantity' => 2,
            'status' => 'active',
            'source' => 'admin',
            'user_id' => $user->id,
        ]);

        $this->createLine($customer, $addonProduct, $addonPrice, [
            'description' => 'Tilleggstjeneste',
            'quantity' => 4,
            'status' => 'active',
            'source' => 'admin',
        ]);

        $report = $this->buildReport($customer);

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_COMPLETE, $report['summary']['basis_status']);
        $this->assertSame(397000, $report['summary']['expected_total_amount']);
        $this->assertSame('NOK', $report['summary']['expected_total_currency']);
        $this->assertCount(1, $report['line_groups']['base_subscription']['lines']);
        $this->assertCount(1, $report['line_groups']['user_based_lines']['lines']);
        $this->assertCount(1, $report['line_groups']['recurring_addons']['lines']);
    }

    public function test_it_calculates_discount_for_complete_basis(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Rabattkunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 10);
        $product = $this->createProduct('base_plan_discount', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_discount_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 300000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Abonnement med rabatt',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $report = $this->buildReport($customer);

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_COMPLETE, $report['summary']['basis_status']);
        $this->assertSame(300000, $report['summary']['expected_total_amount']);
        $this->assertSame(30000, $report['summary']['discount_amount']);
        $this->assertSame(270000, $report['summary']['total_after_discount']);
    }

    public function test_it_marks_missing_price_as_partial_without_forcing_zero(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Partial Kunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $product = $this->createProduct('base_plan_partial', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_partial_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Beregnet linje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => null,
            'billing_price_id' => null,
            'user_id' => null,
            'description' => 'Manuell linje uten pris',
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'manual',
            'metadata' => [],
        ]);

        $report = $this->buildReport($customer);
        $missingLine = collect($report['line_groups']['manual_or_other_lines']['lines'] ?? [])
            ->firstWhere('description', 'Manuell linje uten pris');
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_PARTIAL, $report['summary']['basis_status']);
        $this->assertFalse($report['summary']['can_calculate_expected_total']);
        $this->assertNull($report['summary']['expected_total_amount']);
        $this->assertContains('Noen aktive linjer mangler sikkert prisgrunnlag.', $report['summary']['warnings']);
        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_NOT_CALCULABLE, $readiness['status']);
        $this->assertContains('Interne linjer finnes, men hele grunnlaget kan ikke beregnes sikkert.', $readiness['follow_up_items']);
        $this->assertNotNull($missingLine);
        $this->assertContains('Linjen mangler prisgrunnlag.', $missingLine['warnings']);
    }

    public function test_it_uses_custom_amount_for_customer_specific_price(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Kundeavtale AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $product = $this->createProduct('addon_customer_price', 'Kundeavtale', BillingProduct::CATEGORY_ADDON);
        $price = $this->createPrice($product, 'addon_customer_price_monthly', 'Kundeavtale — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'user_id' => null,
            'description' => $price->name,
            'quantity' => 2,
            'status' => 'active',
            'starts_at' => now(),
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'metadata' => [
                'pricing_mode' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
                'standard_unit_amount' => 199000,
                'custom_unit_amount' => 149000,
                'standard_currency' => 'nok',
                'custom_currency' => 'nok',
                'standard_interval' => BillingPrice::INTERVAL_MONTHLY,
                'notes' => 'Avtalt i kontrakt',
            ],
        ]);

        $report = $this->buildReport($customer);
        $line = $report['line_groups']['customer_specific_prices']['lines'][0];

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_COMPLETE, $report['summary']['basis_status']);
        $this->assertSame(298000, $report['summary']['expected_total_amount']);
        $this->assertSame(149000, $line['amount']);
        $this->assertSame(298000, $line['line_total']);
        $this->assertSame(199000, $line['standard_amount']);
        $this->assertSame(50000, $line['difference_amount']);
        $this->assertSame(CustomerBillingBasisService::CALCULATION_STATUS_COMPLETE, $line['calculation_status']);
    }

    public function test_it_marks_customer_specific_price_without_custom_amount_as_not_calculable(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Kundeavtale Mangler AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $product = $this->createProduct('addon_customer_price_missing', 'Kundeavtale', BillingProduct::CATEGORY_ADDON);
        $price = $this->createPrice($product, 'addon_customer_price_missing_monthly', 'Kundeavtale — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'user_id' => null,
            'description' => $price->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'metadata' => [
                'pricing_mode' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
                'standard_unit_amount' => 199000,
                'standard_currency' => 'nok',
                'standard_interval' => BillingPrice::INTERVAL_MONTHLY,
            ],
        ]);

        $report = $this->buildReport($customer);
        $line = $report['line_groups']['customer_specific_prices']['lines'][0];
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_NOT_CALCULABLE, $report['summary']['basis_status']);
        $this->assertSame(CustomerBillingBasisService::CALCULATION_STATUS_NOT_CALCULABLE, $line['calculation_status']);
        $this->assertNull($line['amount']);
        $this->assertNull($line['line_total']);
        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_NOT_CALCULABLE, $readiness['status']);
        $this->assertSame('Ikke beregnbar', $readiness['status_label']);
        $this->assertContains('Kundespesifikk pris mangler avtalt beløp.', $line['warnings']);
    }

    public function test_it_marks_multi_currency_basis_as_partial(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Valuta Kunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);

        $nokProduct = $this->createProduct('base_plan_currency_nok', 'NOK', BillingProduct::CATEGORY_BASE_PLAN);
        $nokPrice = $this->createPrice($nokProduct, 'base_plan_currency_nok_monthly', 'NOK — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000, 'nok');

        $eurProduct = $this->createProduct('addon_currency_eur', 'EUR', BillingProduct::CATEGORY_ADDON);
        $eurPrice = $this->createPrice($eurProduct, 'addon_currency_eur_monthly', 'EUR — Månedlig', BillingPrice::INTERVAL_MONTHLY, 9900, 'eur');

        $this->createLine($customer, $nokProduct, $nokPrice, [
            'description' => 'NOK-linje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $this->createLine($customer, $eurProduct, $eurPrice, [
            'description' => 'EUR-linje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'admin',
        ]);

        $report = $this->buildReport($customer);

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_PARTIAL, $report['summary']['basis_status']);
        $this->assertNull($report['summary']['expected_total_amount']);
        $this->assertContains('Aktive linjer bruker mer enn én valuta eller mangler valuta.', $report['summary']['warnings']);
    }

    public function test_it_warns_about_missing_stripe_subscription_but_keeps_basis_calculable(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Stripe Preview AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0, 'cus_preview_warning');
        $product = $this->createProduct('base_plan_preview_warning', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_preview_warning_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Preview linje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $report = $this->buildReport($customer);
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_COMPLETE, $report['summary']['basis_status']);
        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_ATTENTION, $readiness['status']);
        $this->assertContains('Kunden har fakturakunde, men ingen aktiv avtale.', $readiness['follow_up_items']);
    }

    public function test_it_keeps_historical_lines_out_of_active_total_but_returns_them_in_history(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Historikk Kunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $product = $this->createProduct('base_plan_history', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_history_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Aktiv linje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $this->createLine($customer, $product, $price, [
            'description' => 'Historisk linje',
            'quantity' => 1,
            'status' => 'ended',
            'source' => 'system',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $report = $this->buildReport($customer);

        $this->assertSame(CustomerBillingBasisService::BASIS_STATUS_COMPLETE, $report['summary']['basis_status']);
        $this->assertSame(199000, $report['summary']['expected_total_amount']);
        $this->assertCount(1, $report['line_groups']['inactive_or_historical_lines']['lines']);
        $this->assertSame('Historisk linje', $report['line_groups']['inactive_or_historical_lines']['lines'][0]['description']);
    }

    public function test_it_marks_customer_with_calculable_internal_lines_but_without_stripe_customer_as_blocked_for_readiness(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Stripe Mangler AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $product = $this->createProduct('base_plan_stripe_missing', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_stripe_missing_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Beregnbar linje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $report = $this->buildReport($customer);
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_BLOCKED, $readiness['status']);
        $this->assertSame('Ikke faktureringsklar', $readiness['status_label']);
        $this->assertContains('Kunden er ikke koblet til fakturasystemet.', $readiness['follow_up_items']);
        $this->assertSame('Blokkert', collect($readiness['checks'])->firstWhere('key', 'stripe_customer')['status_label']);
    }

    public function test_it_marks_customer_with_stripe_customer_but_without_active_subscription_as_attention_for_readiness(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Subscription Mangler AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0, 'cus_subscription_missing');
        $product = $this->createProduct('base_plan_attention', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_attention_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Beregnbar linje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $report = $this->buildReport($customer);
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_ATTENTION, $readiness['status']);
        $this->assertSame('Må følges opp', $readiness['status_label']);
        $this->assertContains('Kunden har fakturakunde, men ingen aktiv avtale.', $readiness['follow_up_items']);
        $this->assertSame('Må følges opp', collect($readiness['checks'])->firstWhere('key', 'stripe_subscription')['status_label']);
    }

    public function test_it_marks_active_one_time_lines_without_invoice_logs_as_attention_for_readiness(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Engangslinje Mangler Faktura AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0, 'cus_one_time_missing_invoice');
        $this->createStripeSubscription($customer);

        $product = $this->createProduct('one_time_charge', 'Engangstjeneste', BillingProduct::CATEGORY_ADDON);
        $price = $this->createPrice($product, 'one_time_charge_once', 'Engangstjeneste — Engang', BillingPrice::INTERVAL_ONE_TIME, 799000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Engangslinje',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'admin',
            'starts_at' => now(),
        ]);

        $report = $this->buildReport($customer);
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_ATTENTION, $readiness['status']);
        $this->assertContains('Interne engangslinjer finnes, men ingen faktura er registrert.', $readiness['follow_up_items']);
        $this->assertSame('Må følges opp', collect($readiness['checks'])->firstWhere('key', 'invoice_logs')['status_label']);
    }

    public function test_it_links_invoice_logs_by_stripe_invoice_id(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Fakturakunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0, 'cus_ready_123');
        $this->createStripeSubscription($customer);
        $product = $this->createProduct('base_plan_invoice', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_invoice_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Aktiv linje med faktura',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
            'stripe_invoice_id' => 'in_test_123',
        ]);

        $this->createInvoiceLog($customer, 'in_test_123', 'paid', 199000, 'nok', '2026-06-14 10:00:00');

        $report = $this->buildReport($customer);
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::RECONCILIATION_STATUS_CAN, $report['reconciliation']['reconciliation_status']);
        $this->assertTrue($report['reconciliation']['has_line_to_invoice_links']);
        $this->assertSame(1, $report['reconciliation']['matched_invoice_count']);
        $this->assertSame(199000, $report['invoices']['invoice_total_in_period_if_available']);
        $this->assertSame('Direkte kobling', $report['invoices']['recent_invoices'][0]['line_link_label']);
        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_READY, $readiness['status']);
        $this->assertSame('Klar for oppfølging', $readiness['status_label']);
        $this->assertContains('Kontroller fakturastatus og betaling i Stripe ved behov.', $readiness['follow_up_items']);
        $this->assertSame('OK', collect($readiness['checks'])->firstWhere('key', 'reconciliation')['status_label']);
    }

    public function test_it_warns_when_invoice_logs_do_not_have_matching_lines(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Faktura uten linje AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0, 'cus_invoice_missing_match');
        $this->createStripeSubscription($customer);
        $product = $this->createProduct('base_plan_invoice_missing', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_invoice_missing_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        $this->createLine($customer, $product, $price, [
            'description' => 'Aktiv linje uten faktura',
            'quantity' => 1,
            'status' => 'active',
            'source' => 'system',
        ]);

        $this->createInvoiceLog($customer, 'in_test_456', 'open', 199000, 'nok', '2026-06-14 10:00:00');

        $report = $this->buildReport($customer);
        $readiness = $report['billing_readiness'];

        $this->assertSame(CustomerBillingBasisService::RECONCILIATION_STATUS_CANNOT, $report['reconciliation']['reconciliation_status']);
        $this->assertFalse($report['reconciliation']['has_line_to_invoice_links']);
        $this->assertContains('invoice_logs finnes, men ingen interne linjer kan kobles direkte.', $report['reconciliation']['warnings']);
        $this->assertSame('Ingen linjekobling', $report['invoices']['recent_invoices'][0]['line_link_label']);
        $this->assertSame(CustomerBillingBasisService::BILLING_READINESS_STATUS_ATTENTION, $readiness['status']);
        $this->assertContains('Faktura finnes, men interne linjer kan ikke kobles direkte.', $readiness['follow_up_items']);
        $this->assertSame('Må følges opp', collect($readiness['checks'])->firstWhere('key', 'reconciliation')['status_label']);
    }

    private function buildReport(Customer $customer): array
    {
        return app(CustomerBillingBasisService::class)->build($customer);
    }

    private function createCustomer(
        string $name,
        string $plan,
        string $billingInterval,
        int $includedAiCredits,
        float $discountPercent,
        ?string $stripeId = null,
    ): Customer {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'stripe_id' => $stripeId,
            'subscription_plan' => $plan,
            'billing_interval' => $billingInterval,
            'included_users' => 0,
            'included_ai_credits' => $includedAiCredits,
            'billing_discount_percent' => $discountPercent,
        ]);
    }

    private function createStripeSubscription(Customer $customer, string $stripeStatus = 'active'): void
    {
        DB::table('subscriptions')->insert([
            'customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::lower(Str::random(12)),
            'stripe_status' => $stripeStatus,
            'stripe_price' => 'price_'.Str::lower(Str::random(12)),
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createProduct(string $key, string $name, string $category): BillingProduct
    {
        return BillingProduct::query()->create([
            'key' => $key,
            'name' => $name,
            'description' => $name.' beskrivelse',
            'category' => $category,
            'billing_scope' => match ($category) {
                BillingProduct::CATEGORY_USER_SEAT => BillingProduct::BILLING_SCOPE_QUANTITY,
                BillingProduct::CATEGORY_USER_SERVICE => BillingProduct::BILLING_SCOPE_USER,
                default => BillingProduct::BILLING_SCOPE_CUSTOMER,
            },
            'is_active' => true,
            'sort_order' => 1,
            'metadata' => [],
        ]);
    }

    private function createPrice(
        BillingProduct $product,
        string $key,
        string $name,
        string $interval,
        int $unitAmount,
        string $currency = 'nok',
    ): BillingPrice
    {
        return BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => $key,
            'name' => $name,
            'interval' => $interval,
            'currency' => $currency,
            'unit_amount' => $unitAmount,
            'stripe_price_id' => 'price_'.Str::lower(Str::random(12)),
            'tier_key' => Str::slug($name),
            'is_recurring' => $interval !== BillingPrice::INTERVAL_ONE_TIME,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);
    }

    private function createLine(Customer $customer, BillingProduct $product, BillingPrice $price, array $attributes = []): CustomerBillingLine
    {
        return CustomerBillingLine::query()->create(array_merge([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'user_id' => $attributes['user_id'] ?? null,
            'description' => $attributes['description'] ?? $price->name,
            'quantity' => $attributes['quantity'] ?? 1,
            'status' => $attributes['status'] ?? 'active',
            'starts_at' => $attributes['starts_at'] ?? now(),
            'ends_at' => $attributes['ends_at'] ?? null,
            'stripe_subscription_id' => $attributes['stripe_subscription_id'] ?? null,
            'stripe_subscription_item_id' => $attributes['stripe_subscription_item_id'] ?? null,
            'stripe_invoice_id' => $attributes['stripe_invoice_id'] ?? null,
            'source' => $attributes['source'] ?? 'admin',
            'metadata' => $attributes['metadata'] ?? [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]));
    }

    private function createInvoiceLog(Customer $customer, string $stripeInvoiceId, string $status, int $amountPaid, string $currency, string $invoiceDate): InvoiceLog
    {
        return InvoiceLog::query()->create([
            'customer_id' => $customer->id,
            'stripe_invoice_id' => $stripeInvoiceId,
            'status' => $status,
            'amount_paid' => $amountPaid,
            'currency' => $currency,
            'line_items' => [],
            'invoice_date' => Carbon::parse($invoiceDate),
        ]);
    }

    private function createUser(Customer $customer, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => Str::slug($name).'+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function useProjectPostgresConnection(): void
    {
        $connectionName = 'feature_pgsql';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'pgsql',
                'host' => $this->projectEnv('DB_HOST', '127.0.0.1'),
                'port' => $this->projectEnv('DB_PORT', '5432'),
                'database' => $this->projectEnv('DB_DATABASE', 'procynia'),
                'username' => $this->projectEnv('DB_USERNAME', 'gehard'),
                'password' => $this->projectEnv('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);
    }

    private function projectEnv(string $key, string $default): string
    {
        $value = env($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }
}
