<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            if (! Schema::hasColumn('saved_notices', 'source_type')) {
                $table->string('source_type', 40)->default('public_notice')->after('customer_id');
            }

            if (! Schema::hasColumn('saved_notices', 'reference_number')) {
                $table->string('reference_number')->nullable()->after('external_url');
            }

            if (! Schema::hasColumn('saved_notices', 'contact_person_name')) {
                $table->string('contact_person_name')->nullable()->after('reference_number');
            }

            if (! Schema::hasColumn('saved_notices', 'contact_person_email')) {
                $table->string('contact_person_email')->nullable()->after('contact_person_name');
            }

            if (! Schema::hasColumn('saved_notices', 'notes')) {
                $table->text('notes')->nullable()->after('contact_person_email');
            }
        });

        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->index('source_type');
        });

        DB::table('saved_notices')
            ->whereNull('source_type')
            ->update([
                'source_type' => 'public_notice',
            ]);
    }

    public function down(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            if (Schema::hasColumn('saved_notices', 'source_type')) {
                $table->dropIndex(['source_type']);
                $table->dropColumn('source_type');
            }

            if (Schema::hasColumn('saved_notices', 'reference_number')) {
                $table->dropColumn('reference_number');
            }

            if (Schema::hasColumn('saved_notices', 'contact_person_name')) {
                $table->dropColumn('contact_person_name');
            }

            if (Schema::hasColumn('saved_notices', 'contact_person_email')) {
                $table->dropColumn('contact_person_email');
            }

            if (Schema::hasColumn('saved_notices', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
