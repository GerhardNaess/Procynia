<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Customer extends Model
{
    use Billable;

    public const PERMISSION_CREATE_DEPARTMENTS = 'create_departments';

    public const PERMISSION_CREATE_USERS = 'create_users';

    public const PERMISSION_VIEW_ALL_CASES = 'view_all_cases';

    public const PERMISSION_APPROVE_WIKI_CLAIMS = 'approve_wiki_claims';

    public const PERMISSION_BE_ENTERPRISE_WIKI_DOCUMENT_OWNER = 'be_enterprise_wiki_document_owner';

    public const PERMISSION_ASSIGN_ENTERPRISE_WIKI_DOCUMENT_OWNER = 'assign_enterprise_wiki_document_owner';

    public const DEFAULT_PERMISSION_SETTINGS = [
        self::PERMISSION_CREATE_DEPARTMENTS => ['system_owner'],
        self::PERMISSION_CREATE_USERS => ['system_owner', 'bid_manager', 'contributor'],
        self::PERMISSION_VIEW_ALL_CASES => ['system_owner', 'bid_manager', 'contributor'],
        self::PERMISSION_APPROVE_WIKI_CLAIMS => ['system_owner', 'qa'],
        self::PERMISSION_BE_ENTERPRISE_WIKI_DOCUMENT_OWNER => ['system_owner', 'bid_manager', 'contributor'],
        self::PERMISSION_ASSIGN_ENTERPRISE_WIKI_DOCUMENT_OWNER => ['system_owner', 'bid_manager'],
    ];

    public const PLAN_FREE = 'free';

    public const PLAN_PRO = 'pro';

    public const PLAN_MAX = 'max';

    public const PLAN_ULTRA = 'ultra';

    public const PLAN_ENTERPRISE = 'enterprise';

    public const BILLING_MONTHLY = 'monthly';

    public const BILLING_YEARLY = 'yearly';

    protected $fillable = [
        'name',
        'slug',
        'nationality_id',
        'language_id',
        'is_active',
        'permission_settings',
        'ai_instructions',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'subscription_plan',
        'billing_interval',
        'included_users',
        'included_ai_credits',
        'billing_discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'permission_settings' => 'array',
            'trial_ends_at' => 'datetime',
            'included_users' => 'integer',
            'included_ai_credits' => 'integer',
            'billing_discount_percent' => 'decimal:2',
        ];
    }

    public function planConfig(): array
    {
        $key = $this->subscription_plan ?? self::PLAN_FREE;

        return config("procynia_plans.{$key}", config('procynia_plans.free'));
    }

    public function planName(): string
    {
        return $this->planConfig()['name'] ?? 'Gratis';
    }

    public function hasActivePaidPlan(): bool
    {
        return $this->subscribed('default') && $this->subscription_plan !== self::PLAN_FREE;
    }

    public function invoiceLogs(): HasMany
    {
        return $this->hasMany(InvoiceLog::class);
    }

    public function billingLines(): HasMany
    {
        return $this->hasMany(CustomerBillingLine::class);
    }

    public function userServiceLevels(): HasMany
    {
        return $this->hasMany(CustomerUserServiceLevel::class);
    }

    public function billingEvents(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    /**
     * Purpose: Return the customer's shared AI instruction, or null when none is set.
     * Inputs: None.
     * Returns: The normalized instruction text, or null when empty/unset.
     * Side effects: None.
     *
     * The AI instruction is owned by the customer and applies to every case that belongs to it. It
     * governs tone, terminology, style and capitalization only — it is always subordinate to
     * grounded facts, selected sources and the anti-fabrication rules in the AI prompts.
     */
    public function resolvedAiInstructions(): ?string
    {
        $instructions = trim(str_replace(["\r\n", "\r"], "\n", (string) ($this->ai_instructions ?? '')));

        return $instructions !== '' ? $instructions : null;
    }

    public function resolvedPermissionSettings(): array
    {
        $stored = $this->permission_settings ?? [];

        return array_merge(self::DEFAULT_PERMISSION_SETTINGS, $stored);
    }

    /**
     * QA is not a mutually-exclusive bid_role — it is an additive capability a user can hold
     * alongside their ordinary role (see User::isQa()). $isQa therefore is a separate flag, not
     * a value $bidRole can take, and only matters when the permission's roles list contains the
     * "qa" column.
     */
    public function roleHasPermission(string $bidRole, string $permission, bool $isQa = false): bool
    {
        if ($bidRole === 'system_owner') {
            return true;
        }

        $roles = $this->resolvedPermissionSettings()[$permission] ?? [];

        return in_array($bidRole, $roles, true)
            || in_array('all', $roles, true)
            || ($isQa && in_array('qa', $roles, true));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Nationality::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function watchProfiles(): HasMany
    {
        return $this->hasMany(WatchProfile::class);
    }

    public function watchProfileInboxRecords(): HasMany
    {
        return $this->hasMany(WatchProfileInboxRecord::class);
    }

    public function noticeDecisions(): HasMany
    {
        return $this->hasMany(NoticeDecision::class);
    }

    public function noticeAttentions(): HasMany
    {
        return $this->hasMany(NoticeAttention::class);
    }

    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }

    public function knowledgeDocumentCategories(): HasMany
    {
        return $this->hasMany(KnowledgeDocumentCategory::class);
    }

    public function knowledgeDocumentTopics(): HasMany
    {
        return $this->hasMany(KnowledgeDocumentTopic::class);
    }

    /**
     * Purpose: Resolve the AI case usage ledger rows for this customer.
     * Inputs: None.
     * Returns: The related AI case usage rows.
     * Side effects: None.
     */
    public function aiCaseUsages(): HasMany
    {
        return $this->hasMany(CustomerAiCaseUsage::class);
    }
}
