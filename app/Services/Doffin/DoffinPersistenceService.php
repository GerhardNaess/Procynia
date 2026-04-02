<?php

namespace App\Services\Doffin;

use App\Models\DoffinNotice;
use App\Models\DoffinNoticeSupplier;
use App\Models\DoffinSupplier;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Persists normalized Doffin notices and supplier links idempotently.
 */
class DoffinPersistenceService
{
    /**
     * Persist harvested notices and flattened supplier records.
     */
    public function persist(array $notices, array $records): array
    {
        Log::info('[DOFFIN][persistence] Persisting harvested data.', [
            'notice_count' => count($notices),
            'record_count' => count($records),
        ]);

        $summary = DB::transaction(function () use ($notices, $records): array {
            $summary = [
                'notices_created' => 0,
                'notices_updated' => 0,
                'suppliers_created' => 0,
                'suppliers_updated' => 0,
                'links_created' => 0,
                'links_updated' => 0,
                'notices_persisted' => 0,
                'suppliers_touched' => 0,
            ];
            $noticeMap = [];
            $supplierIdsTouched = [];

            foreach ($notices as $notice) {
                if (! is_array($notice)) {
                    continue;
                }

                [$model, $created, $updated] = $this->persistNotice($notice);

                if (! $model instanceof DoffinNotice) {
                    continue;
                }

                $noticeMap[$model->notice_id] = $model;
                $summary['notices_created'] += $created ? 1 : 0;
                $summary['notices_updated'] += $updated ? 1 : 0;
            }

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $noticeId = trim((string) ($record['notice_id'] ?? ''));

                if ($noticeId === '') {
                    continue;
                }

                $notice = $noticeMap[$noticeId] ?? null;

                if (! $notice instanceof DoffinNotice) {
                    [$notice, $created, $updated] = $this->persistNotice($record);

                    if (! $notice instanceof DoffinNotice) {
                        continue;
                    }

                    $noticeMap[$notice->notice_id] = $notice;
                    $summary['notices_created'] += $created ? 1 : 0;
                    $summary['notices_updated'] += $updated ? 1 : 0;
                }

                [$supplier, $created, $updated] = $this->persistSupplier($record);

                if (! $supplier instanceof DoffinSupplier) {
                    continue;
                }

                $supplierIdsTouched[$supplier->id] = true;
                $summary['suppliers_created'] += $created ? 1 : 0;
                $summary['suppliers_updated'] += $updated ? 1 : 0;

                [, $linkCreated, $linkUpdated] = $this->persistNoticeSupplierLink($notice, $supplier, $record);

                $summary['links_created'] += $linkCreated ? 1 : 0;
                $summary['links_updated'] += $linkUpdated ? 1 : 0;
            }

            $summary['notices_persisted'] = count($noticeMap);
            $summary['suppliers_touched'] = count($supplierIdsTouched);
            $summary['created_total'] = $summary['notices_created'] + $summary['suppliers_created'] + $summary['links_created'];
            $summary['updated_total'] = $summary['notices_updated'] + $summary['suppliers_updated'] + $summary['links_updated'];

            return $summary;
        });

        Log::info('[DOFFIN][persistence] Persisted harvested data.', $summary);

        return $summary;
    }

    /**
     * Upsert a normalized notice row by notice id.
     */
    public function persistNotice(array $notice): array
    {
        $noticeId = trim((string) ($notice['notice_id'] ?? ''));

        if ($noticeId === '') {
            return [null, false, false];
        }

        $estimatedValue = $this->extractEstimatedValueFields($notice['estimated_value'] ?? $notice);

        $attributes = [
            'notice_type' => $this->nullableString($notice['notice_type'] ?? null),
            'heading' => $this->nullableString($notice['heading'] ?? $notice['title'] ?? null),
            'publication_date' => $this->normalizeTimestamp($notice['publication_date'] ?? null),
            'issue_date' => $this->normalizeTimestamp($notice['issue_date'] ?? null),
            'buyer_name' => $this->nullableString($notice['buyer_name'] ?? $notice['buyer_names'][0] ?? null),
            'buyer_org_id' => $this->nullableString($notice['buyer_org_id'] ?? $notice['buyer_ids'][0] ?? null),
            'cpv_codes_json' => $this->normalizeArray($notice['cpv_codes'] ?? $notice['cpv_codes_json'] ?? []),
            'place_of_performance_json' => $this->normalizeArray($notice['place_of_performance'] ?? $notice['place_of_performance_json'] ?? []),
            'estimated_value_amount' => $estimatedValue['amount'],
            'estimated_value_currency_code' => $estimatedValue['currency_code'],
            'estimated_value_display' => $estimatedValue['display'],
            'awarded_names_json' => $this->normalizeArray($notice['awarded_names'] ?? $notice['awarded_names_json'] ?? []),
            'raw_payload_json' => $this->normalizeRawPayload($notice['raw_payload_json'] ?? null),
            'last_harvested_at' => now(),
        ];

        $model = DoffinNotice::query()->firstOrNew([
            'notice_id' => $noticeId,
        ]);
        $created = ! $model->exists;
        $updated = $this->fillAndSave($model, $attributes, $created);

        return [$model, $created, $updated];
    }

    /**
     * Find or create a supplier using organization number first, then normalized name.
     */
    public function persistSupplier(array $record): array
    {
        $supplierName = $this->nullableString($record['supplier_name'] ?? null);

        if ($supplierName === null) {
            return [null, false, false];
        }

        $organizationNumber = $this->normalizeOrganizationNumber($record['organization_number'] ?? null);
        $normalizedName = $this->normalizeSupplierName($supplierName);

        $model = null;

        if ($organizationNumber !== null) {
            $model = DoffinSupplier::query()
                ->where('organization_number', $organizationNumber)
                ->first();
        }

        if (! $model instanceof DoffinSupplier) {
            $model = DoffinSupplier::query()
                ->where('normalized_name', $normalizedName)
                ->orderBy('id')
                ->first();
        }

        if (! $model instanceof DoffinSupplier) {
            $model = new DoffinSupplier();
        }

        $created = ! $model->exists;
        $updated = $this->fillAndSave($model, [
            'supplier_name' => $supplierName,
            'organization_number' => $organizationNumber,
            'normalized_name' => $normalizedName,
        ], $created);

        return [$model, $created, $updated];
    }

    /**
     * Upsert the supplier link for a notice.
     */
    public function persistNoticeSupplierLink(DoffinNotice $notice, DoffinSupplier $supplier, array $record): array
    {
        $model = DoffinNoticeSupplier::query()->firstOrNew([
            'doffin_notice_id' => $notice->id,
            'doffin_supplier_id' => $supplier->id,
        ]);
        $created = ! $model->exists;
        $updated = $this->fillAndSave($model, [
            'winner_lots_json' => $this->normalizeArray($record['winner_lots'] ?? $record['winner_lots_json'] ?? []),
            'source' => $this->nullableString($record['source'] ?? null),
        ], $created);

        return [$model, $created, $updated];
    }

    /**
     * Fill a model and return whether an existing model was updated.
     */
    private function fillAndSave(object $model, array $attributes, bool $created): bool
    {
        $model->fill($attributes);
        $isDirty = $model->isDirty();

        if ($created || $isDirty) {
            $model->save();
        }

        return ! $created && $isDirty;
    }

    /**
     * Purpose:
     * Normalize estimated value input into persisted amount, currency, and display fields.
     *
     * Inputs:
     * Parsed estimated value payload or a notice-like array that already contains value fields.
     *
     * Returns:
     * array{amount:?string,currency_code:?string,display:?string}
     *
     * Side effects:
     * None.
     */
    public function extractEstimatedValueFields(mixed $value): array
    {
        $display = $this->estimatedValueDisplay($value);
        $currencyCode = $this->estimatedValueCurrencyCode($value);
        $amount = $this->estimatedValueAmount($value);

        if ($display !== null) {
            [$displayAmount, $displayCurrencyCode] = $this->parseEstimatedValueDisplay($display);

            $amount ??= $displayAmount;
            $currencyCode ??= $displayCurrencyCode;
        }

        return [
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'display' => $display,
        ];
    }

    /**
     * Normalize a mixed array payload to a JSON-storable array.
     */
    private function normalizeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== ''));
    }

    /**
     * Normalize a nullable string value.
     */
    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize timestamps for persistence.
     */
    private function normalizeTimestamp(mixed $value): ?CarbonInterface
    {
        $normalized = $this->nullableString($value);

        return $normalized === null ? null : Carbon::parse($normalized);
    }

    /**
     * Normalize the estimated amount field from parsed notice data.
     */
    private function estimatedValueAmount(mixed $value): ?string
    {
        $amount = is_array($value)
            ? ($value['amount'] ?? $value['estimated_value_amount'] ?? null)
            : $value;

        return $this->normalizeDecimalAmount($amount);
    }

    /**
     * Normalize the estimated value currency code.
     */
    private function estimatedValueCurrencyCode(mixed $value): ?string
    {
        $currencyCode = $this->nullableString(
            is_array($value)
                ? ($value['currency_code'] ?? $value['estimated_value_currency_code'] ?? $value['estimated_value_currency'] ?? null)
                : null,
        );

        if ($currencyCode === null) {
            return null;
        }

        $normalized = Str::upper($currencyCode);

        return preg_match('/^[A-Z]{3}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * Normalize the estimated value display string.
     */
    private function estimatedValueDisplay(mixed $value): ?string
    {
        return $this->nullableString(
            is_array($value)
                ? ($value['display'] ?? $value['estimated_value_display'] ?? null)
                : $value,
        );
    }

    /**
     * Purpose:
     * Parse a stored Doffin display string into amount and currency code.
     *
     * Inputs:
     * A display string such as "3.0E8 NOK" or "250000.0 NOK".
     *
     * Returns:
     * array{0:?string,1:?string}
     *
     * Side effects:
     * None.
     */
    private function parseEstimatedValueDisplay(string $display): array
    {
        if (
            preg_match(
                '/^\s*([+-]?[0-9]+(?:\.[0-9]+)?(?:E[+-]?[0-9]+)?)\s+([A-Z]{3})\s*$/i',
                $display,
                $matches,
            ) !== 1
        ) {
            return [null, null];
        }

        return [
            $this->normalizeDecimalAmount($matches[1]),
            Str::upper($matches[2]),
        ];
    }

    /**
     * Purpose:
     * Normalize a numeric input into a fixed-scale decimal string without float arithmetic.
     *
     * Inputs:
     * A scalar amount that may contain decimals or scientific notation.
     *
     * Returns:
     * ?string
     *
     * Side effects:
     * None.
     */
    private function normalizeDecimalAmount(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?(?:E[+-]?[0-9]+)?$/i', $normalized) !== 1) {
            return null;
        }

        return $this->formatDecimalScale(
            $this->expandScientificNotation($normalized),
            2,
        );
    }

    /**
     * Purpose:
     * Expand scientific notation into a plain decimal string.
     *
     * Inputs:
     * A numeric string that may contain an exponent.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    private function expandScientificNotation(string $value): string
    {
        $normalized = Str::upper(trim($value));

        if (! str_contains($normalized, 'E')) {
            return $normalized;
        }

        [$mantissa, $exponent] = explode('E', $normalized, 2);
        $exponentValue = (int) $exponent;
        $negative = str_starts_with($mantissa, '-');
        $unsignedMantissa = ltrim($mantissa, '+-');
        [$integerPart, $fractionPart] = array_pad(explode('.', $unsignedMantissa, 2), 2, '');
        $digits = ltrim($integerPart.$fractionPart, '0');

        if ($digits === '') {
            return '0';
        }

        $decimalPosition = strlen($integerPart) + $exponentValue;

        if ($decimalPosition <= 0) {
            $expanded = '0.'.str_repeat('0', abs($decimalPosition)).$digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $expanded = $digits.str_repeat('0', $decimalPosition - strlen($digits));
        } else {
            $expanded = substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
        }

        return $negative ? '-'.$expanded : $expanded;
    }

    /**
     * Purpose:
     * Format a decimal string to a fixed scale with deterministic half-up rounding.
     *
     * Inputs:
     * A plain decimal numeric string and the desired scale.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    private function formatDecimalScale(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $unsignedValue = ltrim($value, '+-');
        [$integerPart, $fractionPart] = array_pad(explode('.', $unsignedValue, 2), 2, '');
        $integerPart = ltrim($integerPart, '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;
        $fractionPart = preg_replace('/\D+/', '', $fractionPart) ?? '';

        $requiredLength = $scale + 1;
        $fractionPart = str_pad($fractionPart, $requiredLength, '0');
        $retainedFraction = substr($fractionPart, 0, $scale);
        $roundDigit = (int) substr($fractionPart, $scale, 1);
        $unscaledValue = $integerPart.$retainedFraction;
        $unscaledValue = ltrim($unscaledValue, '0');
        $unscaledValue = $unscaledValue === '' ? '0' : $unscaledValue;

        if ($roundDigit >= 5) {
            $unscaledValue = $this->incrementIntegerString($unscaledValue);
        }

        $minimumLength = $scale + 1;

        if (strlen($unscaledValue) < $minimumLength) {
            $unscaledValue = str_pad($unscaledValue, $minimumLength, '0', STR_PAD_LEFT);
        }

        $wholePart = substr($unscaledValue, 0, -$scale);
        $wholePart = $wholePart === '' ? '0' : $wholePart;
        $scaledFraction = substr($unscaledValue, -$scale);
        $result = $wholePart.'.'.$scaledFraction;

        if ($negative && $result !== '0.'.str_repeat('0', $scale)) {
            return '-'.$result;
        }

        return $result;
    }

    /**
     * Purpose:
     * Increment a positive integer string by one.
     *
     * Inputs:
     * A positive integer string without separators.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    private function incrementIntegerString(string $value): string
    {
        $digits = str_split($value);
        $carry = 1;

        for ($index = count($digits) - 1; $index >= 0; $index--) {
            $digit = ((int) $digits[$index]) + $carry;

            if ($digit >= 10) {
                $digits[$index] = '0';
                $carry = 1;

                continue;
            }

            $digits[$index] = (string) $digit;
            $carry = 0;
            break;
        }

        if ($carry === 1) {
            array_unshift($digits, '1');
        }

        return implode('', $digits);
    }

    /**
     * Normalize raw payload JSON for persistence.
     */
    private function normalizeRawPayload(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * Normalize supplier organization numbers to digits only.
     */
    private function normalizeOrganizationNumber(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Normalize supplier names for deterministic matching.
     */
    private function normalizeSupplierName(string $value): string
    {
        return Str::lower(Str::squish($value));
    }
}
