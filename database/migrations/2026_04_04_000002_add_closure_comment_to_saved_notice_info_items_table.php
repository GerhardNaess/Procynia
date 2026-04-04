<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('saved_notice_info_items', 'closure_comment')) {
                $table->text('closure_comment')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            if (Schema::hasColumn('saved_notice_info_items', 'closure_comment')) {
                $table->dropColumn('closure_comment');
            }
        });
    }
};
