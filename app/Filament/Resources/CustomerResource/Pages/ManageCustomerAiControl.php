<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\AiOperationalBudgetPeriod;
use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\CustomerAiOperationalLimit;
use App\Models\CustomerAiUsageReservation;
use App\Models\User;
use App\Services\Ai\Commercial\AiCreditAdjustmentService;
use App\Services\Ai\Commercial\AiQuotaStatusService;
use App\Services\Ai\Commercial\AiRuntimeControlService;
use App\Services\Ai\Operational\AiOperationalBudgetService;
use App\Services\Ai\Operational\AiPaymentPolicyService;
use App\Support\CustomerContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Operational AI cost control for one customer.
 *
 * Everything shown here is read from the same services the runtime guards use, and every change is
 * made through those services rather than by writing to the model: an administrative act that does
 * not pass through AiRuntimeControlService or AiCreditAdjustmentService would skip the audit trail
 * and the customer notification, which is exactly what this page must never allow.
 */
class ManageCustomerAiControl extends Page
{
    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.pages.manage-customer-ai-control';

    public Customer $record;

    /** @var array<string, mixed> */
    public array $quota = [];

    /** @var array<int, array<string, mixed>> */
    public array $auditEvents = [];

    /** @var array<int, array<string, mixed>> */
    public array $adjustments = [];

    public int $uncertainReservations = 0;

    /** @var array<string, mixed> */
    public array $operationalBudget = [];

    /** @var array<string, mixed> */
    public array $paymentState = [];

    public bool $globalStopActive = false;

    public static function canAccess(array $parameters = []): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public function mount(Customer $record): void
    {
        abort_unless(app(CustomerContext::class)->isInternalAdmin(), 403);

        $this->record = $record;
        $this->loadState();
    }

    public function getTitle(): string
    {
        return __('procynia.ai_admin.customer_title', ['customer' => $this->record->name]);
    }

    public function getSubheading(): ?string
    {
        return __('procynia.ai_admin.customer_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend_ai')
                ->label(__('procynia.ai_admin.actions.suspend'))
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('procynia.ai_admin.actions.suspend_confirm'))
                ->form([
                    Textarea::make('reason')
                        ->label(__('procynia.ai_admin.fields.reason'))
                        ->helperText(__('procynia.ai_admin.fields.reason_help'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(fn (array $data) => $this->changeAccess(Customer::AI_ACCESS_SUSPENDED, (string) $data['reason']))
                ->visible(fn (): bool => ($this->record->ai_access_status ?? Customer::AI_ACCESS_ENABLED) !== Customer::AI_ACCESS_SUSPENDED),

            Action::make('resume_ai')
                ->label(__('procynia.ai_admin.actions.resume'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('reason')
                        ->label(__('procynia.ai_admin.fields.reason'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(fn (array $data) => $this->changeAccess(Customer::AI_ACCESS_ENABLED, (string) $data['reason']))
                ->visible(fn (): bool => ($this->record->ai_access_status ?? Customer::AI_ACCESS_ENABLED) === Customer::AI_ACCESS_SUSPENDED),

            Action::make('adjust_credits')
                ->label(__('procynia.ai_admin.actions.adjust_credits'))
                ->icon('heroicon-o-plus-circle')
                ->requiresConfirmation()
                ->modalDescription(__('procynia.ai_admin.actions.adjust_credits_confirm'))
                ->form([
                    TextInput::make('amount')
                        ->label(__('procynia.ai_admin.fields.amount'))
                        ->helperText(__('procynia.ai_admin.fields.amount_help'))
                        ->numeric()
                        ->required()
                        ->rule('integer')
                        ->rule('not_in:0'),
                    Textarea::make('reason')
                        ->label(__('procynia.ai_admin.fields.reason'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(fn (array $data) => $this->adjustCredits((int) $data['amount'], (string) $data['reason'])),

            Action::make('set_operational_limits')
                ->label(__('procynia.ai_admin.actions.set_operational_limits'))
                ->icon('heroicon-o-banknotes')
                ->requiresConfirmation()
                ->modalDescription(__('procynia.ai_admin.actions.set_operational_limits_confirm'))
                ->fillForm(fn (): array => [
                    'is_enabled' => (bool) ($this->operationalBudget['is_enabled'] ?? false),
                    'daily_nok_limit' => $this->operationalBudget['daily']['limit'] ?? null,
                    'monthly_nok_limit' => $this->operationalBudget['monthly']['limit'] ?? null,
                ])
                ->form([
                    Toggle::make('is_enabled')
                        ->label(__('procynia.ai_admin.fields.operational_enabled'))
                        ->helperText(__('procynia.ai_admin.fields.operational_enabled_help')),
                    TextInput::make('daily_nok_limit')
                        ->label(__('procynia.ai_admin.fields.daily_nok_limit'))
                        ->numeric()->minValue(0)->nullable(),
                    TextInput::make('monthly_nok_limit')
                        ->label(__('procynia.ai_admin.fields.monthly_nok_limit'))
                        ->numeric()->minValue(0)->nullable(),
                    Textarea::make('reason')
                        ->label(__('procynia.ai_admin.fields.reason'))
                        ->required()->minLength(3)->maxLength(500),
                ])
                ->action(fn (array $data) => $this->saveOperationalLimits($data)),

            Action::make('back_to_billing')
                ->label(__('procynia.ai_admin.actions.back_to_billing'))
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->url(fn (): string => CustomerResource::getUrl('billing', ['record' => $this->record])),
        ];
    }

    private function changeAccess(string $status, string $reason): void
    {
        $actor = $this->internalActor();

        if (! $actor instanceof User) {
            return;
        }

        try {
            // The service owns the transaction, the billing event and the single customer
            // notification. Calling it is what makes this action auditable at all.
            app(AiRuntimeControlService::class)->setCustomerAccess($this->record, $status, $actor, trim($reason));
        } catch (Throwable $throwable) {
            $this->failure($throwable->getMessage());

            return;
        }

        $this->record = $this->record->fresh();
        $this->loadState();

        Notification::make()
            ->title($status === Customer::AI_ACCESS_SUSPENDED
                ? __('procynia.ai_admin.notifications.suspended')
                : __('procynia.ai_admin.notifications.resumed'))
            ->success()
            ->send();
    }

    private function adjustCredits(int $amount, string $reason): void
    {
        $actor = $this->internalActor();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $status = app(AiCreditAdjustmentService::class)->adjust($this->record, $amount, trim($reason), $actor);
        } catch (InvalidArgumentException $exception) {
            $this->failure($exception->getMessage());

            return;
        } catch (Throwable $throwable) {
            $this->failure($throwable->getMessage());

            return;
        }

        $this->record = $this->record->fresh();
        $this->loadState();

        Notification::make()
            ->title(__('procynia.ai_admin.notifications.credits_adjusted', [
                'amount' => $amount > 0 ? '+'.$amount : (string) $amount,
                'allowance' => $status->allowance(),
            ]))
            ->success()
            ->send();
    }

    /** Re-checked at action time, not only at mount: authorisation is not a rendering concern. */
    private function internalActor(): ?User
    {
        $context = app(CustomerContext::class);
        $actor = $context->currentUser();

        if (! $context->isInternalAdmin($actor instanceof User ? $actor : null) || ! $actor instanceof User) {
            $this->failure(__('procynia.ai_admin.notifications.not_authorised'));

            return null;
        }

        return $actor;
    }

    private function failure(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }

    /**
     * Persist this customer's NOK safety ceiling.
     *
     * A limit is a runtime setting, not a plan entitlement: it takes effect on the next provider
     * call with no deploy, and it is recorded with actor, reason and before/after like every other
     * cost-control change.
     */
    private function saveOperationalLimits(array $data): void
    {
        $actor = $this->internalActor();

        if (! $actor instanceof User) {
            return;
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            $this->failure(__('procynia.ai_admin.notifications.reason_required'));

            return;
        }

        $daily = $this->positiveOrNull($data['daily_nok_limit'] ?? null);
        $monthly = $this->positiveOrNull($data['monthly_nok_limit'] ?? null);

        try {
            DB::transaction(function () use ($daily, $monthly, $data, $reason, $actor): void {
                $limit = CustomerAiOperationalLimit::query()->firstOrNew(['customer_id' => $this->record->id]);
                $before = [
                    'is_enabled' => (bool) ($limit->is_enabled ?? false),
                    'daily_nok_limit' => $limit->daily_nok_limit,
                    'monthly_nok_limit' => $limit->monthly_nok_limit,
                ];

                $limit->forceFill([
                    'customer_id' => $this->record->id,
                    'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                    'daily_nok_limit' => $daily,
                    'monthly_nok_limit' => $monthly,
                    'changed_by_user_id' => $actor->id,
                    'reason' => $reason,
                ])->save();

                BillingEvent::query()->create([
                    'customer_id' => $this->record->id,
                    'user_id' => $actor->id,
                    'event_type' => 'ai_customer_operational_limits_updated',
                    'source' => 'ai_cost_control',
                    'description' => $reason,
                    'before' => $before,
                    'after' => [
                        'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                        'daily_nok_limit' => $daily,
                        'monthly_nok_limit' => $monthly,
                    ],
                ]);
            });
        } catch (Throwable $throwable) {
            $this->failure($throwable->getMessage());

            return;
        }

        $this->loadState();

        Notification::make()->title(__('procynia.ai_admin.notifications.operational_limits_updated'))->success()->send();
    }

    private function positiveOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0.0, (float) $value);
    }

    private function loadState(): void
    {
        $status = app(AiQuotaStatusService::class)->forCustomer($this->record);
        $this->quota = $status->toArray();
        $this->loadOperationalState();
        $this->globalStopActive = app(AiRuntimeControlService::class)->globalStopEnabled();

        // Surfaced because an uncertain reservation still holds a credit: an admin looking at a
        // customer who "should" have capacity needs to see that a doubtful provider call has it.
        $this->uncertainReservations = CustomerAiUsageReservation::query()
            ->where('customer_id', $this->record->id)
            ->where('period_start', $status->periodStart)
            ->where('status', CustomerAiUsageReservation::STATUS_UNCERTAIN)
            ->count();

        $this->auditEvents = BillingEvent::query()
            ->where('customer_id', $this->record->id)
            ->where('source', 'ai_cost_control')
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (BillingEvent $event): array => [
                'id' => $event->id,
                'created_at' => $event->created_at?->format('Y-m-d H:i'),
                'event_type' => $event->event_type,
                'event_label' => __('procynia.ai_admin.event_types.'.$event->event_type),
                'actor' => $event->user?->name ?? __('procynia.ai_admin.actor_system'),
                'reason' => $event->description,
                'before' => $this->readablePayload($event->before),
                'after' => $this->readablePayload($event->after),
            ])
            ->all();

        $this->adjustments = app(AiCreditAdjustmentService::class)
            ->historyFor($this->record)
            ->map(fn ($adjustment): array => [
                'id' => $adjustment->id,
                'created_at' => $adjustment->created_at?->format('Y-m-d H:i'),
                'period' => $adjustment->period_start?->format('Y-m-d').' – '.$adjustment->period_end?->format('Y-m-d'),
                'amount' => $adjustment->amount > 0 ? '+'.$adjustment->amount : (string) $adjustment->amount,
                'reason' => $adjustment->reason,
                'actor' => $adjustment->actor?->name ?? __('procynia.ai_admin.actor_system'),
            ])
            ->all();
    }

    /**
     * The operational picture: NOK spent against Procynia's own safety ceilings, kept visually and
     * conceptually separate from the commercial quota above it. The two answer different questions.
     */
    private function loadOperationalState(): void
    {
        $budgets = app(AiOperationalBudgetService::class);
        $limit = CustomerAiOperationalLimit::query()->where('customer_id', $this->record->id)->first();

        $this->operationalBudget = [
            'is_enabled' => (bool) ($limit->is_enabled ?? false),
            'daily' => $budgets->status(AiOperationalBudgetPeriod::SCOPE_CUSTOMER, (int) $this->record->id, AiOperationalBudgetPeriod::WINDOW_DAILY),
            'monthly' => $budgets->status(AiOperationalBudgetPeriod::SCOPE_CUSTOMER, (int) $this->record->id, AiOperationalBudgetPeriod::WINDOW_MONTHLY),
        ];

        $this->paymentState = app(AiPaymentPolicyService::class)->evaluate($this->record);
    }

    /**
     * Render a before/after payload as readable key: value lines rather than raw JSON.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<int, string>
     */
    private function readablePayload(?array $payload): array
    {
        if (! is_array($payload) || $payload === []) {
            return [];
        }

        $lines = [];

        foreach ($payload as $key => $value) {
            $lines[] = sprintf(
                '%s: %s',
                __('procynia.ai_admin.payload_keys.'.$key) === 'procynia.ai_admin.payload_keys.'.$key
                    ? str_replace('_', ' ', (string) $key)
                    : __('procynia.ai_admin.payload_keys.'.$key),
                is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE),
            );
        }

        return $lines;
    }
}
