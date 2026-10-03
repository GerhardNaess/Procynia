<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer access tables every customer-frontend request reads, for tests that build their own
 * SQLite schema instead of migrating.
 *
 * HandleInertiaRequests resolves the customer's packages (ModuleEntitlementService) and the user's
 * customer roles (CustomerPermissionService) on every page, so a hand-built schema without these
 * tables turns every request into a 500. Kept in one place so the next access table is added once,
 * not once per test file. Columns mirror the 2026_10_01_000001 and 2026_10_03_000002 migrations.
 */
trait CreatesCustomerAccessSqliteSchema
{
    protected function createCustomerAccessTables(): void
    {
        Schema::create('customer_package_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('package_key');
            $table->string('status')->default('requested');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'package_key']);
        });

        Schema::create('customer_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'name']);
        });

        Schema::create('customer_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_role_id');
            $table->string('permission_key');
            $table->timestamps();

            $table->unique(['customer_role_id', 'permission_key']);
        });

        Schema::create('customer_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_role_id');
            $table->timestamps();

            $table->unique(['user_id', 'customer_role_id']);
        });
    }
}
