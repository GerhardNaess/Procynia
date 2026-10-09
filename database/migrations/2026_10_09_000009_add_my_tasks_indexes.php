<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Mine oppgaver» reads two assignment columns on every visit to Oppfølging that had no index of their
 * own (docs/notifications-and-tasks-plan.md §7):
 *
 *  - suppliers.owner_user_id — the supplier source asks for one customer's suppliers with this person
 *    as intern ansvarlig. Postgres does not index a foreign key by itself.
 *  - saved_notice_info_items.owner_user_id + status — the Anbud source asks for this person's open
 *    aksjoner; the existing indexes all start with saved_notice_id.
 *
 * The Wiki assignment columns already have theirs (ewpv_reviewer_submitted_index,
 * ewpv_qa_assigned_index), and the bell's queries are served by user_notifications_scope_index.
 * Nothing is added for modules that do not read tasks yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->index(['customer_id', 'owner_user_id'], 'suppliers_customer_owner_index');
        });

        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            $table->index(['owner_user_id', 'status'], 'saved_notice_info_items_owner_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            $table->dropIndex('saved_notice_info_items_owner_status_index');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex('suppliers_customer_owner_index');
        });
    }
};
