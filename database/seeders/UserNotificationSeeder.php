<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserNotificationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'development'])) {
            return;
        }

        $customer = Customer::query()->orderBy('id')->first();

        if (! $customer) {
            $language = Language::query()->firstOrCreate(
                ['code' => 'no'],
                ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
            );

            $nationality = Nationality::query()->firstOrCreate(
                ['code' => 'NO'],
                ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
            );

            $customer = Customer::query()->create([
                'name' => 'Demo Customer AS',
                'slug' => 'demo-customer-as',
                'nationality_id' => $nationality->id,
                'language_id' => $language->id,
                'is_active' => true,
            ]);
        }

        $primaryUser = User::query()->firstOrCreate(
            ['email' => 'demo.notifications.owner@procynia.test'],
            [
                'name' => 'Demo Notifications Owner',
                'password' => Hash::make('password'),
                'role' => User::ROLE_CUSTOMER_ADMIN,
                'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
                'bid_manager_scope' => null,
                'primary_affiliation_scope' => null,
                'customer_id' => $customer->id,
                'is_active' => true,
            ],
        );

        $secondaryUser = User::query()->firstOrCreate(
            ['email' => 'demo.notifications.user@procynia.test'],
            [
                'name' => 'Demo Notifications User',
                'password' => Hash::make('password'),
                'role' => User::ROLE_USER,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'bid_manager_scope' => null,
                'primary_affiliation_scope' => null,
                'customer_id' => $customer->id,
                'is_active' => true,
            ],
        );

        $notice = SavedNotice::query()
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->first();

        $this->seedNotification(
            $primaryUser,
            $customer->id,
            'info_center_case_assigned',
            UserNotification::SEVERITY_WARNING,
            'Ny aksjon trenger oppfølging',
            'En ny aksjon er registrert i Infosenter. Åpne oppgaven for å følge den opp.',
            $notice ? route('app.notices.saved.show', ['savedNotice' => $notice->id]) : route('app.info-center.index'),
            $notice?->id,
        );

        $this->seedNotification(
            $primaryUser,
            $customer->id,
            'info_center_response_due',
            UserNotification::SEVERITY_INFO,
            'Svarfrist nærmer seg',
            'En oppfølging du eier har frist i løpet av de neste dagene.',
            route('app.info-center.index', ['view' => 'awaiting_response']),
            null,
        );

        $this->seedNotification(
            $secondaryUser,
            $customer->id,
            'info_center_follow_up',
            UserNotification::SEVERITY_CRITICAL,
            'Viktig avklaring mangler',
            'Du venter fortsatt på et svar som påvirker videre fremdrift.',
            route('app.info-center.index', ['view' => 'my_tasks']),
            null,
        );
    }

    private function seedNotification(
        User $user,
        int $customerId,
        string $eventType,
        string $severity,
        string $title,
        string $message,
        string $targetUrl,
        ?int $savedNoticeId,
    ): void {
        UserNotification::query()->firstOrCreate(
            [
                'customer_id' => $customerId,
                'user_id' => $user->id,
                'event_type' => $eventType,
                'title' => $title,
            ],
            [
                'saved_notice_id' => $savedNoticeId,
                'severity' => $severity,
                'message' => $message,
                'target_url' => $targetUrl,
                'is_read' => false,
                'metadata' => [
                    'seeded' => true,
                    'seed_key' => Str::slug($eventType),
                ],
            ],
        );
    }
}
