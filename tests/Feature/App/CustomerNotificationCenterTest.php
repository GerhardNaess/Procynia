<?php

namespace Tests\Feature\App;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerNotificationCenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
        ]);

        $this->app['db']->purge('sqlite');
        $this->app['db']->reconnect('sqlite');
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->createSchema();
    }

    public function test_customer_frontend_shares_only_current_users_notifications_with_unread_first_ordering(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 10:00:00'));

        try {
            $customer = $this->createCustomer('Procynia AS');
            $otherCustomer = $this->createCustomer('Other Customer AS');
            $department = $this->createDepartment($customer->id, 'Salg');
            $actor = $this->createUser($customer->id, $department->id, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER, 'varsler.aktor@procynia.test');
            $sameCustomerUser = $this->createUser($customer->id, null, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR, 'varsler.kollega@procynia.test');
            $foreignUser = $this->createUser($otherCustomer->id, null, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR, 'varsler.fremmed@procynia.test');

            $this->createNotification(
                $actor,
                $customer->id,
                'Demo varsel 1',
                'Første uleste varsel.',
                false,
                UserNotification::SEVERITY_WARNING,
                '/app/info-center',
                null,
                now()->subHours(3),
            );

            $this->createNotification(
                $actor,
                $customer->id,
                'Demo varsel 2',
                'Andre uleste varsel som skal komme først.',
                false,
                UserNotification::SEVERITY_CRITICAL,
                '/app/notices?mode=saved',
                null,
                now()->subHour(),
            );

            $this->createNotification(
                $actor,
                $customer->id,
                'Demo varsel 3',
                'Et varsel som allerede er lest.',
                true,
                UserNotification::SEVERITY_INFO,
                '/app/info-center?view=my_tasks',
                null,
                now()->subMinutes(30),
                now()->subMinutes(25),
            );

            $this->createNotification(
                $sameCustomerUser,
                $customer->id,
                'Skjult varsel',
                'Dette skal ikke være synlig for aktøren.',
                false,
                UserNotification::SEVERITY_INFO,
                '/app/info-center',
                null,
                now()->subMinutes(15),
            );

            $this->createNotification(
                $foreignUser,
                $otherCustomer->id,
                'Fremmed varsel',
                'Dette skal ikke lekke mellom kunder.',
                false,
                UserNotification::SEVERITY_INFO,
                '/app/info-center',
                null,
                now()->subMinutes(10),
            );

            $response = $this->actingAs($actor)->get('/app/customer-environment');

            $response->assertOk();
            $response->assertViewHas('page', function ($page): bool {
                $notifications = data_get($page, 'props.notifications', []);
                $titles = collect(data_get($notifications, 'items', []))->pluck('title')->all();

                return data_get($notifications, 'unread_count') === 2
                    && data_get($notifications, 'limit') === 10
                    && $titles === ['Demo varsel 2', 'Demo varsel 1', 'Demo varsel 3']
                    && ! in_array('Skjult varsel', $titles, true)
                    && ! in_array('Fremmed varsel', $titles, true);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mark_single_notification_as_read_updates_count_and_preserves_other_users_notifications(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $actor = $this->createUser($customer->id, $department->id, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER, 'varsler.lesing@procynia.test');
        $otherUser = $this->createUser($customer->id, null, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR, 'varsler.kollega.lesing@procynia.test');
        $foreignUser = $this->createUser($customer->id, null, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR, 'varsler.fremmed.lesing@procynia.test');

        $first = $this->createNotification(
            $actor,
            $customer->id,
            'Første varsel',
            'Skal bli markert som lest.',
            false,
            UserNotification::SEVERITY_WARNING,
            '/app/info-center',
            null,
            now()->subMinutes(20),
        );

        $second = $this->createNotification(
            $actor,
            $customer->id,
            'Andre varsel',
            'Skal fortsatt være ulest.',
            false,
            UserNotification::SEVERITY_INFO,
            '/app/info-center',
            null,
            now()->subMinutes(10),
        );

        $this->createNotification(
            $otherUser,
            $customer->id,
            'Skjult for andre',
            'Må ikke påvirkes.',
            false,
            UserNotification::SEVERITY_INFO,
            '/app/info-center',
            null,
            now()->subMinutes(5),
        );

        $foreign = $this->createNotification(
            $foreignUser,
            $customer->id,
            'Fremmed varsel',
            'Skal ikke påvirkes.',
            false,
            UserNotification::SEVERITY_INFO,
            '/app/info-center',
            null,
            now()->subMinutes(2),
        );

        $response = $this->actingAs($actor)->patchJson(route('app.notifications.read', ['userNotification' => $first->id]));

        $response->assertOk();
        $response->assertJsonPath('notifications.unread_count', 1);
        $response->assertJsonPath('notifications.items.0.id', $second->id);
        $response->assertJsonPath('notifications.items.0.is_read', false);
        $response->assertJsonPath('notifications.items.1.id', $first->id);
        $response->assertJsonPath('notifications.items.1.is_read', true);

        $this->assertDatabaseHas('user_notifications', [
            'id' => $first->id,
            'is_read' => true,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'id' => $foreign->id,
            'is_read' => false,
        ]);
    }

    public function test_mark_all_notifications_as_read_only_updates_current_users_scope(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $actor = $this->createUser($customer->id, $department->id, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER, 'varsler.alle@procynia.test');
        $otherCustomer = $this->createCustomer('Other Customer AS');
        $foreignUser = $this->createUser($otherCustomer->id, null, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR, 'varsler.fremmed.alle@procynia.test');

        $first = $this->createNotification(
            $actor,
            $customer->id,
            'Første varsel',
            'Skal markeres som lest.',
            false,
            UserNotification::SEVERITY_INFO,
            '/app/info-center',
            null,
            now()->subMinutes(20),
        );

        $second = $this->createNotification(
            $actor,
            $customer->id,
            'Andre varsel',
            'Skal også markeres som lest.',
            false,
            UserNotification::SEVERITY_WARNING,
            '/app/info-center',
            null,
            now()->subMinutes(10),
        );

        $foreign = $this->createNotification(
            $foreignUser,
            $otherCustomer->id,
            'Fremmed varsel',
            'Må ikke påvirkes av aktørens massemarkering.',
            false,
            UserNotification::SEVERITY_CRITICAL,
            '/app/info-center',
            null,
            now()->subMinutes(5),
        );

        $response = $this->actingAs($actor)->patchJson(route('app.notifications.read-all'));

        $response->assertOk();
        $response->assertJsonPath('notifications.unread_count', 0);
        $response->assertJsonCount(2, 'notifications.items');

        $this->assertDatabaseHas('user_notifications', [
            'id' => $first->id,
            'is_read' => true,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'id' => $second->id,
            'is_read' => true,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'id' => $foreign->id,
            'is_read' => false,
        ]);
    }

    public function test_foreign_notification_cannot_be_marked_read(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $actor = $this->createUser($customer->id, $department->id, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER, 'varsler.foreign.block@procynia.test');
        $otherUser = $this->createUser($customer->id, null, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR, 'varsler.foreign.block.other@procynia.test');

        $notification = $this->createNotification(
            $otherUser,
            $customer->id,
            'Ikke min',
            'Denne skal ikke kunne markeres av andre brukere.',
            false,
            UserNotification::SEVERITY_INFO,
            '/app/info-center',
            null,
            now()->subMinutes(10),
        );

        $this->actingAs($actor)
            ->patchJson(route('app.notifications.read', ['userNotification' => $notification->id]))
            ->assertNotFound();
    }

    private function createSchema(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('nationality_code', 8)->nullable();
            $table->string('default_language_code', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role');
            $table->string('bid_role')->nullable();
            $table->string('bid_manager_scope')->nullable();
            $table->string('primary_affiliation_scope')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('primary_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('department_user', function (Blueprint $table): void {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['department_id', 'user_id']);
        });

        Schema::create('bid_manager_departments', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'department_id']);
        });

        Schema::create('saved_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable();
            $table->string('external_id')->nullable();
            $table->string('title');
            $table->string('reference_number')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_notice_id')->nullable()->constrained('saved_notices')->nullOnDelete();
            $table->string('event_type')->nullable();
            $table->string('severity', 32)->default('info');
            $table->string('title');
            $table->text('message');
            $table->string('target_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createCustomer(string $name): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
    }

    private function createDepartment(int $customerId, string $name): Department
    {
        return Department::query()->create([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function createUser(
        int $customerId,
        ?int $departmentId,
        string $role,
        ?string $bidRole,
        string $email,
    ): User {
        return User::query()->create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'bid_manager_scope' => null,
            'primary_affiliation_scope' => null,
            'customer_id' => $customerId,
            'department_id' => $departmentId,
            'primary_department_id' => $departmentId,
            'is_active' => true,
        ]);
    }

    private function createNotification(
        User $user,
        int $customerId,
        string $title,
        string $message,
        bool $isRead,
        string $severity,
        ?string $targetUrl,
        ?int $savedNoticeId,
        Carbon $createdAt,
        ?Carbon $readAt = null,
    ): UserNotification {
        return UserNotification::query()->create([
            'customer_id' => $customerId,
            'user_id' => $user->id,
            'saved_notice_id' => $savedNoticeId,
            'event_type' => Str::slug($title),
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'target_url' => $targetUrl,
            'is_read' => $isRead,
            'read_at' => $readAt,
            'metadata' => [
                'created_for_test' => true,
            ],
            'created_at' => $createdAt,
            'updated_at' => $readAt ?? $createdAt,
        ]);
    }
}
