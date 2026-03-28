<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_attentions', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('notice_id')->constrained()->cascadeOnDelete()->index();
        });

        $defaultCustomerId = DB::table('customers')
            ->where('slug', 'default-customer')
            ->value('id');

        DB::statement(sprintf(
            <<<'SQL'
            UPDATE notice_attentions AS attentions
            SET customer_id = COALESCE(
                (SELECT departments.customer_id FROM departments WHERE departments.id = attentions.department_id),
                %d
            )
            WHERE attentions.customer_id IS NULL
            SQL,
            (int) $defaultCustomerId,
        ));

        DB::statement('ALTER TABLE notice_attentions ALTER COLUMN customer_id SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notice_attentions ALTER COLUMN customer_id DROP NOT NULL');

        Schema::table('notice_attentions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
