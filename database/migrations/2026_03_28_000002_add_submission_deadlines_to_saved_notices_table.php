<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->timestamp('rfi_submission_deadline_at')->nullable()->after('archived_at');
            $table->timestamp('rfp_submission_deadline_at')->nullable()->after('rfi_submission_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropColumn([
                'rfi_submission_deadline_at',
                'rfp_submission_deadline_at',
            ]);
        });
    }
};
