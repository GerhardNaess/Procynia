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
            $setting = DoffinImportSetting::create([
                'scheduled_import_enabled' => false,
                'watch_inbox_discovery_enabled' => false,
            ]);
        }

        return $setting;
    }

    public function isEnvironmentEnabled(): bool
    {
        return (bool) config('doffin.scheduled_import_enabled', false);
    }

    public function isWatchInboxDiscoveryEnvironmentEnabled(): bool
    {
        return (bool) config('doffin.watch_inbox_discovery_enabled', false);
    }

    public function isAdminEnabled(): bool
    {
        return $this->getSetting()->scheduled_import_enabled;
    }

    public function isWatchInboxDiscoveryAdminEnabled(): bool
    {
        return $this->getSetting()->watch_inbox_discovery_enabled;
    }

    public function hasRequiredApiConfiguration(): bool
    {
        return filled(config('doffin.base_url')) && filled(config('doffin.api_key'));
    }

    public function hasRequiredWatchInboxDiscoveryApiConfiguration(): bool
    {
        return filled(config('doffin.live_search_base_url')) && filled(config('doffin.api_key'));
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

    public function watchInboxDiscoverySkipReason(): ?string
    {
        if (! $this->isWatchInboxDiscoveryEnvironmentEnabled()) {
            return 'environment_disabled';
        }

        if (! $this->isWatchInboxDiscoveryAdminEnabled()) {
            return 'admin_disabled';
        }

        if (! $this->hasRequiredWatchInboxDiscoveryApiConfiguration()) {
            return 'api_missing';
        }

        return null;
    }

    public function scheduledImportSkipReasonLabel(?string $reason): ?string
    {
        return $this->skipReasonLabel($reason);
    }

    public function watchInboxDiscoverySkipReasonLabel(?string $reason): ?string
    {
        return $this->skipReasonLabel($reason);
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function scheduledImportStatusSummary(): array
    {
        $environmentEnabled = $this->isEnvironmentEnabled();
        $adminEnabled = $this->isAdminEnabled();
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

    /**
     * @return array<string, bool|string|null>
     */
    public function watchInboxDiscoveryStatusSummary(): array
    {
        $environmentEnabled = $this->isWatchInboxDiscoveryEnvironmentEnabled();
        $adminEnabled = $this->isWatchInboxDiscoveryAdminEnabled();
        $apiConfigured = $this->hasRequiredWatchInboxDiscoveryApiConfiguration();
        $skipReason = $this->watchInboxDiscoverySkipReason();

        return [
            'environment_enabled' => $environmentEnabled,
            'admin_enabled' => $adminEnabled,
            'api_configured' => $apiConfigured,
            'enabled' => $skipReason === null,
            'skip_reason' => $skipReason,
            'skip_reason_label' => $this->watchInboxDiscoverySkipReasonLabel($skipReason),
        ];
    }

    /**
     * @return array<string, array<string, bool|string|null>>
     */
    public function automationStatusSummary(): array
    {
        return [
            'scheduled_import' => $this->scheduledImportStatusSummary(),
            'watch_inbox_discovery' => $this->watchInboxDiscoveryStatusSummary(),
        ];
    }

    /**
     * Backwards-compatible alias for the scheduled import summary.
     *
     * @return array<string, bool|string|null>
     */
    public function statusSummary(): array
    {
        return $this->scheduledImportStatusSummary();
    }

    public function setScheduledImportEnabled(bool $enabled, ?User $user = null): DoffinImportSetting
    {
        return $this->updateSetting(
            'scheduled_import_enabled',
            $enabled,
            $user,
            '[Procynia][Doffin] Scheduled import toggle updated.',
        );
    }

    public function setWatchInboxDiscoveryEnabled(bool $enabled, ?User $user = null): DoffinImportSetting
    {
        return $this->updateSetting(
            'watch_inbox_discovery_enabled',
            $enabled,
            $user,
            '[Procynia][Doffin] Watch inbox discovery toggle updated.',
        );
    }

    private function updateSetting(string $column, bool $enabled, ?User $user, string $message): DoffinImportSetting
    {
        $setting = $this->getSetting();
        $setting->{$column} = $enabled;
        $setting->updated_by = $user?->id;
        $setting->save();

        Log::info($message, [
            $column => $enabled,
            'user_id' => $user?->id,
        ]);

        return $setting->refresh();
    }

    private function skipReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'environment_disabled' => 'Miljøbryteren er av.',
            'admin_disabled' => 'Admin-bryteren er av.',
            'api_missing' => 'Doffin API-konfigurasjonen er ufullstendig.',
            default => null,
        };
    }
}
