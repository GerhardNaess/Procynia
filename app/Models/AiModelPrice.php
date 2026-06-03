<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Purpose: Store versioned AI model prices for internal cost estimation.
 * Inputs: Provider, model, optional deployment/region, prices and validity period.
 * Returns: Eloquent rows used by AiTokenCostEstimator to price historical token events.
 * Side effects: None — read-only from the estimator's perspective.
 */
class AiModelPrice extends Model
{
    public const SYNC_STATUS_OK      = 'ok';
    public const SYNC_STATUS_CHANGED = 'changed';
    public const SYNC_STATUS_NEW     = 'new';
    public const SYNC_STATUS_STALE   = 'stale';

    protected $fillable = [
        'provider',
        'model',
        'deployment_name',
        'provider_region',
        'currency',
        'input_price_per_1m_tokens',
        'cached_input_price_per_1m_tokens',
        'output_price_per_1m_tokens',
        'valid_from',
        'valid_to',
        'is_active',
        'source_url',
        'source_hash',
        'last_seen_at',
        'last_verified_at',
        'sync_status',
        'sync_note',
    ];

    protected function casts(): array
    {
        return [
            'input_price_per_1m_tokens'        => 'decimal:6',
            'cached_input_price_per_1m_tokens' => 'decimal:6',
            'output_price_per_1m_tokens'       => 'decimal:6',
            'valid_from'                        => 'date',
            'valid_to'                          => 'date',
            'is_active'                         => 'boolean',
            'last_seen_at'                      => 'datetime',
            'last_verified_at'                  => 'datetime',
        ];
    }

    /**
     * Purpose: Find the active price for a provider/model/deployment at a specific point in time.
     * Inputs: Provider key, model name, optional deployment name and region, and the reference timestamp.
     * Returns: The matching AiModelPrice row or null when no price is registered.
     * Side effects: None.
     */
    public static function findForEvent(
        string $provider,
        string $model,
        ?string $deploymentName,
        ?string $providerRegion,
        \DateTimeInterface $at,
    ): ?self {
        return static::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where(fn ($q) => $deploymentName !== null
                ? $q->where('deployment_name', $deploymentName)
                : $q->whereNull('deployment_name'))
            ->where(fn ($q) => $providerRegion !== null
                ? $q->where('provider_region', $providerRegion)
                : $q->whereNull('provider_region'))
            ->where('valid_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $at->format('Y-m-d')))
            ->orderByDesc('valid_from')
            ->first();
    }
}
