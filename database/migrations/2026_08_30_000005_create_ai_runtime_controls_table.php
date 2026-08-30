<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runtime_controls', function (Blueprint $table): void {
            $table->id();
            $table->boolean('global_ai_stop')->default(false);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });

        DB::table('ai_runtime_controls')->insert(['global_ai_stop' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { Schema::dropIfExists('ai_runtime_controls'); }
};
