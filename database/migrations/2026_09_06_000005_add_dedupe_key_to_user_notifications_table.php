<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stable identity for "this exact notification, to this exact person, about this exact thing".
 *
 * Wiki review notifications are produced from operations that legitimately run more than once — a
 * queued job retries, syncForPageVersion() is called again, someone double-clicks submit. Without an
 * identity the same person is told the same thing several times, so the key is what makes the write
 * idempotent rather than each caller having to remember to check first.
 *
 * Nullable and unique: existing notification types (AI quota, billing) carry no key and are
 * unaffected, and Postgres treats NULLs as distinct in a unique index, so any number of them coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->string('dedupe_key')->nullable()->after('event_type');
            $table->unique('dedupe_key', 'user_notifications_dedupe_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->dropUnique('user_notifications_dedupe_key_unique');
            $table->dropColumn('dedupe_key');
        });
    }
};
