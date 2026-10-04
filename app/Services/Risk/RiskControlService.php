<?php

namespace App\Services\Risk;

use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\RiskControl;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Risiko → håndteres av → Kontroll, from the risk's side only.
 *
 * The control is the existing `control` QualityItem. This service stores which risk it handles and
 * nothing more: its title, criterion and status are read from Kvalitet whenever the risk page is
 * shown, never copied.
 *
 * TWO ACCESS QUESTIONS, NEITHER IMPLIES THE OTHER.
 *
 *  - The risk: the caller has already reached it through RiskAccessService::visibleRisks(), and
 *    linking or unlinking also takes risk.edit in its area. A link never gives anybody the risk.
 *  - The control: whatever the risk page says about a control, the person must be able to read
 *    in Kvalitet today — the `quality` module for the customer and quality.view for them. Without
 *    it the page shows no control information at all, and cannot link or unlink.
 *
 * A link has no effect on the risk's assessment. Residual risk stays a human judgement in
 * RiskAssessment; nothing here reads or writes likelihood, consequence or score.
 */
class RiskControlService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /**
     * Whether the user may read Kvalitet — its controls, processes and activities — by the rules
     * Kvalitet itself applies. Shared by every Risiko → Kvalitet link.
     */
    public function canReadQuality(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null
            && $this->entitlements->hasModule($customer, 'quality')
            && $this->permissions->has($user, CustomerPermissionCatalog::QUALITY_VIEW);
    }

    /**
     * The controls linked to the risk, read live from Kvalitet.
     *
     * @return list<array{id: int, title: string, code: ?string, status: string, criterion: ?string, url: string}>
     */
    public function linkedControls(Risk $risk): array
    {
        return $this->controlsQuery((int) $risk->customer_id)
            ->whereIn('quality_items.id', RiskControl::query()
                ->where('risk_id', $risk->id)
                ->where('customer_id', $risk->customer_id)
                ->select('quality_item_id'))
            ->with('controlDetail:id,quality_item_id,criterion')
            ->get()
            ->map(fn (QualityItem $control): array => $this->controlRow($control))
            ->all();
    }

    /**
     * Controls of the risk's customer not yet linked to it, to choose from.
     *
     * @return list<array{id: int, title: string, code: ?string, status: string}>
     */
    public function controlOptions(Risk $risk): array
    {
        return $this->controlsQuery((int) $risk->customer_id)
            ->whereNotIn('quality_items.id', RiskControl::query()->where('risk_id', $risk->id)->select('quality_item_id'))
            ->get()
            ->map(fn (QualityItem $control): array => [
                'id' => (int) $control->id,
                'title' => $control->title,
                'code' => $control->code,
                'status' => $control->status,
            ])
            ->all();
    }

    /**
     * Link an existing control. Anything that is not a control of the risk's own customer is
     * refused with the same answer, so the form cannot be used to probe other tenants' ids.
     */
    public function link(User $user, Risk $risk, int $controlId): void
    {
        $control = $this->controlsQuery((int) $risk->customer_id)->whereKey($controlId)->first();

        if ($control === null) {
            throw ValidationException::withMessages([
                'quality_item_id' => __('procynia.risk.validation.control_not_allowed'),
            ]);
        }

        RiskControl::query()->firstOrCreate(
            ['risk_id' => $risk->id, 'quality_item_id' => $control->id],
            ['customer_id' => $risk->customer_id, 'created_by' => $user->id],
        );
    }

    /**
     * Remove the link. The control is left exactly as it was.
     */
    public function unlink(Risk $risk, int $controlId): bool
    {
        return RiskControl::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $risk->customer_id)
            ->where('quality_item_id', $controlId)
            ->delete() > 0;
    }

    /** @return Builder<QualityItem> */
    private function controlsQuery(int $customerId): Builder
    {
        return QualityItem::query()
            ->where('quality_items.customer_id', $customerId)
            ->where('quality_items.quality_type', QualityItem::TYPE_CONTROL)
            ->orderBy('quality_items.title')
            ->orderBy('quality_items.id');
    }

    /** @return array{id: int, title: string, code: ?string, status: string, criterion: ?string, url: string} */
    private function controlRow(QualityItem $control): array
    {
        return [
            'id' => (int) $control->id,
            'title' => $control->title,
            'code' => $control->code,
            'status' => $control->status,
            'criterion' => $control->controlDetail?->criterion,
            'url' => route('app.quality.items.show', ['item' => $control->id]),
        ];
    }
}
