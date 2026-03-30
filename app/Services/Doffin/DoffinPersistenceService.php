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

        $attributes = [
            'notice_type' => $this->nullableString($notice['notice_type'] ?? null),
            'heading' => $this->nullableString($notice['heading'] ?? $notice['title'] ?? null),
            'publication_date' => $this->normalizeTimestamp($notice['publication_date'] ?? null),
            'issue_date' => $this->normalizeTimestamp($notice['issue_date'] ?? null),
            'buyer_name' => $this->nullableString($notice['buyer_name'] ?? $notice['buyer_names'][0] ?? null),
            'buyer_org_id' => $this->nullableString($notice['buyer_org_id'] ?? $notice['buyer_ids'][0] ?? null),
            'cpv_codes_json' => $this->normalizeArray($notice['cpv_codes'] ?? $notice['cpv_codes_json'] ?? []),
            'place_of_performance_json' => $this->normalizeArray($notice['place_of_performance'] ?? $notice['place_of_performance_json'] ?? []),
            'estimated_value_amount' => $this->estimatedValueAmount($notice['estimated_value'] ?? $notice),
            'estimated_value_currency_code' => $this->estimatedValueCurrencyCode($notice['estimated_value'] ?? $notice),
            'estimated_value_display' => $this->estimatedValueDisplay($notice['estimated_value'] ?? $notice),
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
        $amount = is_array($value) ? ($value['amount'] ?? null) : ($value['estimated_value_amount'] ?? null);

        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Normalize the estimated value currency code.
     */
    private function estimatedValueCurrencyCode(mixed $value): ?string
    {
        return $this->nullableString(
            is_array($value)
                ? ($value['currency_code'] ?? $value['estimated_value_currency_code'] ?? null)
                : null,
        );
    }

    /**
     * Normalize the estimated value display string.
     */
    private function estimatedValueDisplay(mixed $value): ?string
    {
        return $this->nullableString(
            is_array($value)
                ? ($value['display'] ?? $value['estimated_value_display'] ?? null)
                : null,
        );
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
