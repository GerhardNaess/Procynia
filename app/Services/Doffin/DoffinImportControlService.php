<?php

namespace App\Services\Doffin;

use App\Models\DoffinImportSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DoffinImportControlService
{
    public function getSetting(): DoffinImportSetting
    {
        $setting = DoffinImportSetting::first();

        if ($setting === null) {
            $setting = DoffinImportSetting::create(['scheduled_import_enabled' => false]);
        }

        return $setting;
    }

    public function isEnvironmentEnabled(): bool
    {
        return (bool) config('doffin.scheduled_import_enabled', false);
    }

    public function isAdminEnabled(): bool
    {
        return $this->getSetting()->scheduled_import_enabled;
    }

    public function hasRequiredApiConfiguration(): bool
    {
        return filled(config('doffin.base_url')) && filled(config('doffin.api_key'));
    }

    public function scheduledImportSkipReason(): ?string
    {
        if (! $this->isEnvironmentEnabled()) {
            return 'environment_disabled';
        }

        if (! $this->isAdminEnabled()) {
            return 'admin_disabled';
        }

        if (! $this->hasRequiredApiConfiguration()) {
            return 'api_missing';
        }

        return null;
    }

    public function scheduledImportSkipReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'environment_disabled' => 'Miljøbryteren er av.',
            'admin_disabled' => 'Admin-bryteren er av.',
            'api_missing' => 'Doffin API-konfigurasjonen er ufullstendig.',
            default => null,
        };
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function statusSummary(): array
    {
        $setting = $this->getSetting();
        $environmentEnabled = $this->isEnvironmentEnabled();
        $adminEnabled = $setting->scheduled_import_enabled;
        $apiConfigured = $this->hasRequiredApiConfiguration();
        $skipReason = $this->scheduledImportSkipReason();

        return [
            'environment_enabled' => $environmentEnabled,
            'admin_enabled' => $adminEnabled,
            'api_configured' => $apiConfigured,
            'enabled' => $skipReason === null,
            'skip_reason' => $skipReason,
            'skip_reason_label' => $this->scheduledImportSkipReasonLabel($skipReason),
        ];
    }

    public function setScheduledImportEnabled(bool $enabled, ?User $user = null): DoffinImportSetting
    {
        $setting = $this->getSetting();
        $setting->scheduled_import_enabled = $enabled;
        $setting->updated_by = $user?->id;
        $setting->save();

        Log::info('[Procynia][Doffin] Scheduled import toggle updated.', [
            'scheduled_import_enabled' => $enabled,
            'user_id' => $user?->id,
        ]);

        return $setting->refresh();
    }
}
