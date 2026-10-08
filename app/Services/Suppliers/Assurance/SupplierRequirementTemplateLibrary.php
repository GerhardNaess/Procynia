<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Customer;
use App\Models\SupplierControlRequirement;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\Suppliers\RequirementTemplates\RequirementLibrary;
use App\Support\Suppliers\RequirementTemplates\RequirementTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ta i bruk kravmal (docs/supplier-assurance-v2-plan.md §16.1, §21): what a template would add to the
 * customer's Kontrollkrav, and adding it.
 *
 * WHAT IS CREATED. Every item of the template the customer does not already have becomes an ordinary
 * catalogue requirement (supplier_id null) owned by the customer: title in the person's language and
 * the item's theme, level, control point, interval, rule and document types, with provenance
 * template_key, template_item_key and template_version. Nothing else — no supplier, no override, no
 * control, no decision. Which suppliers it applies to is the rule against the profile, computed on
 * read by SupplierRequirementApplicability like every other requirement.
 *
 * WHAT IS ALREADY THERE is decided by template_item_key alone, never by title: an item the customer
 * has — from this template or another, active or retired, edited or not — is left exactly as it is.
 * A requirement the customer wrote by hand is never matched, merged or touched. Applying the same
 * template twice adds nothing the second time.
 *
 * NO UPDATES. A requirement, once created, is the customer's: it may be edited, retired or deleted
 * like any other, keeps its provenance, and a later template version never changes it (§16.1).
 *
 * WHO. supplier.assure, and only that (§13.2). One transaction with the customer row locked, so two
 * clicks cannot create an item twice; the partial unique index on (customer_id, template_item_key)
 * refuses it as well.
 */
class SupplierRequirementTemplateLibrary
{
    public function __construct(
        private readonly SupplierAccessService $access,
    ) {}

    /**
     * The templates, each with its items and — per item — whether the customer already has it.
     * Reads only the person's own customer.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(User $user): array
    {
        $existing = $this->existing((int) $user->customer_id);

        return array_map(function (string $key) use ($existing): array {
            $template = RequirementTemplates::find($key);
            $items = array_map(function (string $itemKey) use ($existing, $template): array {
                $item = RequirementLibrary::item($itemKey);
                $have = $existing[$itemKey] ?? null;

                return [
                    'key' => $itemKey,
                    'title' => RequirementLibrary::title($itemKey),
                    'theme' => $item['theme'],
                    'level' => $item['level'],
                    // A stricter level the template suggests; shown, never applied (§16.3).
                    'recommended_level' => $template['recommended_levels'][$itemKey] ?? null,
                    'existing' => $have === null ? null : [
                        'title' => $have->title,
                        'status' => $have->status,
                    ],
                ];
            }, $template['items']);

            return [
                'key' => $key,
                'version' => $template['version'],
                'name' => RequirementTemplates::name($key),
                'purpose' => (string) __("procynia.supplier_management.templates.list.{$key}.purpose"),
                'suited_for' => (string) __("procynia.supplier_management.templates.list.{$key}.suited_for"),
                'items' => $items,
                'mandatory_count' => count(array_filter($items, fn (array $item): bool => $item['level'] === SupplierControlRequirement::LEVEL_MANDATORY)),
                'to_create_count' => count(array_filter($items, fn (array $item): bool => $item['existing'] === null)),
            ];
        }, RequirementTemplates::keys());
    }

    /**
     * Adds the template's missing items to the customer's catalogue.
     *
     * @return array{created: int, existing: int}
     */
    public function apply(User $actor, string $templateKey): array
    {
        if (! $this->access->canAssure($actor)) {
            throw new AuthorizationException;
        }

        $template = RequirementTemplates::find($templateKey) ?? throw new \InvalidArgumentException("Unknown requirement template [{$templateKey}].");
        $customerId = (int) $actor->customer_id;

        return DB::transaction(function () use ($actor, $template, $templateKey, $customerId): array {
            Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
            $existing = $this->existing($customerId);
            $created = 0;

            foreach ($template['items'] as $itemKey) {
                if (isset($existing[$itemKey])) {
                    continue;
                }

                $item = RequirementLibrary::item($itemKey);
                (new SupplierControlRequirement)->forceFill([
                    'customer_id' => $customerId,
                    'supplier_id' => null,
                    'title' => RequirementLibrary::title($itemKey),
                    'theme' => $item['theme'],
                    'level' => $item['level'],
                    'control_point' => $item['control_point'],
                    'control_interval_months' => $item['control_interval_months'],
                    'applies_when' => $item['applies_when'],
                    'accepted_document_types' => $item['accepted_document_types'],
                    'status' => SupplierControlRequirement::STATUS_ACTIVE,
                    'template_key' => $templateKey,
                    'template_item_key' => $itemKey,
                    'template_version' => $template['version'],
                    'created_by' => (int) $actor->id,
                    'updated_by' => (int) $actor->id,
                ])->save();
                $created++;
            }

            return ['created' => $created, 'existing' => count($template['items']) - $created];
        });
    }

    /**
     * The customer's requirements that came from the library, by item key.
     *
     * @return array<string, SupplierControlRequirement>
     */
    private function existing(int $customerId): array
    {
        return SupplierControlRequirement::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('template_item_key')
            ->get(['id', 'title', 'status', 'template_item_key'])
            ->keyBy('template_item_key')
            ->all();
    }
}
