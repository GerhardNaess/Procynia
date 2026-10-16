<?php

namespace Tests\Support;

use App\Models\AdminNotification;
use App\Models\AdminPageHelp;
use App\Models\OperationalRunbook;
use App\Models\OperationalRunbookAttachment;
use Illuminate\Support\Facades\DB;

/**
 * The admin data tests/e2e/a11y-admin.spec.js (WCAG Fase 6, Filament) works on — rows of its own, so the
 * spec never marks a real admin notification as read or touches a real runbook:
 *
 *   - «E2E UU driftsrutine», an inactive runbook with two attachments: one with a description (the
 *     disclosure button, A11Y-053) and one without;
 *   - two unread admin notifications (critical, warning), dated a minute ahead so they are listed first;
 *   - a «Hjelp» text for Varsler (admin.notifications) — only when the environment has none, so the
 *     header action and its modal exist; an existing help text is never touched.
 *
 * Test-only (autoload-dev), invoked through tinker; seed() starts from a clean slate and returns the
 * runbook id. No files are written: the attachments' stored paths do not exist, and the spec never
 * downloads them.
 */
class A11yAdminE2EFixture
{
    public const RUNBOOK_TITLE = 'E2E UU driftsrutine';

    public const DEDUPE_PREFIX = 'e2e-a11y-admin-';

    public const HELP_TITLE = 'E2E UU hjelp';

    public const NOTIFICATION_TITLES = ['E2E UU kritisk varsel', 'E2E UU advarsel'];

    public static function seed(): int
    {
        self::cleanup();

        return DB::transaction(function (): int {
            $runbook = OperationalRunbook::query()->create([
                'title' => self::RUNBOOK_TITLE,
                'category' => 'general',
                'summary' => 'Rutine brukt av den automatiske UU-testen av administrasjonspanelet.',
                'is_active' => false,
                'sort_order' => 9999,
            ]);

            OperationalRunbookAttachment::query()->create([
                'operational_runbook_id' => $runbook->id,
                'original_name' => 'e2e-uu-gjenoppretting.pdf',
                'stored_path' => 'e2e-a11y-admin/missing-1.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
                'description' => 'Trinnvis gjenoppretting av databasen fra siste backup.',
                'sort_order' => 1,
            ]);

            OperationalRunbookAttachment::query()->create([
                'operational_runbook_id' => $runbook->id,
                'original_name' => 'e2e-uu-sjekkliste.docx',
                'stored_path' => 'e2e-a11y-admin/missing-2.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'size_bytes' => 1024,
                'description' => null,
                'sort_order' => 2,
            ]);

            foreach ([AdminNotification::SEVERITY_CRITICAL, AdminNotification::SEVERITY_WARNING] as $index => $severity) {
                $notification = AdminNotification::query()->create([
                    'type' => 'e2e_a11y_admin',
                    'severity' => $severity,
                    'title' => self::NOTIFICATION_TITLES[$index],
                    'message' => 'Opprettet av den automatiske UU-testen.',
                    'data' => [],
                    'dedupe_key' => self::DEDUPE_PREFIX.$index,
                ]);

                // Newest first in the list: the critical one on top, the warning right below it.
                $notification->forceFill(['created_at' => now()->addMinutes(2 - $index)])->save();
            }

            $hasHelp = AdminPageHelp::query()
                ->where('page_key', 'admin.notifications')
                ->exists();

            if (! $hasHelp) {
                AdminPageHelp::query()->create([
                    'page_key' => 'admin.notifications',
                    'title' => self::HELP_TITLE,
                    'description' => 'Hjelpetekst brukt av den automatiske UU-testen.',
                    'intro' => 'Varsler viser hendelser som krever oppfølging.',
                    'sections' => [['title' => 'Slik gjør du det', 'items' => [['title' => '', 'text' => '1. Les varselet. 2. Velg «Merk lest».']]]],
                    'is_active' => true,
                ]);
            }

            return (int) $runbook->id;
        });
    }

    public static function cleanup(): void
    {
        OperationalRunbook::query()->where('title', self::RUNBOOK_TITLE)->get()->each->delete();
        AdminNotification::query()->where('dedupe_key', 'like', self::DEDUPE_PREFIX.'%')->delete();
        AdminPageHelp::query()->where('title', self::HELP_TITLE)->delete();
    }
}
