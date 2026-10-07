<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_decisions', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('notice_id')->constrained()->cascadeOnDelete()->index();
        });

        $defaultCustomerId = DB::table('customers')
            ->where('slug', 'default-customer')
            ->value('id');

        DB::statement(sprintf(
            <<<'SQL'
            UPDATE notice_decisions AS decisions
            SET customer_id = COALESCE(
                (SELECT departments.customer_id FROM departments WHERE departments.id = decisions.department_id),
                (SELECT users.customer_id FROM users WHERE users.id = decisions.user_id),
                %d
            )
            WHERE decisions.customer_id IS NULL
            SQL,
            (int) $defaultCustomerId,
        ));

        DB::statement('ALTER TABLE notice_decisions ALTER COLUMN customer_id SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notice_decisions ALTER COLUMN customer_id DROP NOT NULL');

        // up() chains ->index() onto the foreign key definition, where `index` is the constraint's
        // name — so the key was created as "1", not notice_decisions_customer_id_foreign. Both names are
        // dropped if present, so this rolls back whichever one a database holds.
        DB::statement('ALTER TABLE notice_decisions DROP CONSTRAINT IF EXISTS "1"');
        DB::statement('ALTER TABLE notice_decisions DROP CONSTRAINT IF EXISTS notice_decisions_customer_id_foreign');

        Schema::table('notice_decisions', function (Blueprint $table): void {
            $table->dropColumn('customer_id');
        });
    }
};
