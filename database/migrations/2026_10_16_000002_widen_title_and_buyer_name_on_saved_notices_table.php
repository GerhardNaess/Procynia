<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A case's title and buyer are copied from the register record (notices.title is text) or typed
 * by hand, and both save flows already accept up to 1000 characters. The columns were 255, so a long
 * Doffin/TED title passed validation and failed the insert as an HTTP 500. Widening is lossless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->string('title', 1000)->change();
            $table->string('buyer_name', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing would truncate or fail on any row longer than 255, so the down migration only
        // runs when no such row exists — it never cuts data.
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->string('title', 255)->change();
            $table->string('buyer_name', 255)->nullable()->change();
        });
    }
};
