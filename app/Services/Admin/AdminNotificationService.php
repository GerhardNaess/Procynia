<?php

namespace App\Services\Admin;

use App\Models\AdminNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purpose: Create internal Super Admin notifications without ever throwing to the caller.
 * Inputs: Type, severity, title, message, optional data array and optional dedupe key.
 * Returns: The created AdminNotification or null on failure.
 * Side effects: Writes one admin_notifications row; logs a warning on failure.
 */
class AdminNotificationService
{
    public function create(
        string $type,
        string $severity,
        string $title,
        string $message,
        ?array $data = null,
        ?string $dedupeKey = null,
    ): ?AdminNotification {
        try {
            if ($dedupeKey !== null) {
                $existing = AdminNotification::query()
                    ->where('dedupe_key', $dedupeKey)
                    ->exists();

                if ($existing) {
                    return null;
                }
            }

            return AdminNotification::query()->create([
                'type'       => $type,
                'severity'   => $severity,
                'title'      => $title,
                'message'    => $message,
                'data'       => $data,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][ADMIN_NOTIFICATION] Failed to create admin notification.', [
                'type'    => $type,
                'title'   => $title,
                'error'   => $throwable->getMessage(),
            ]);

            return null;
        }
    }
}
