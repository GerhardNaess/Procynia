<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The write operations a customer's System Owner has switched on for one grant (App\Support\
 * ApiOperationCatalog). Empty by default: a grant reads only, as before, until the System Owner
 * allows a named operation. Roles never grant an operation on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_client_customer_grants', function (Blueprint $table): void {
            $table->jsonb('allowed_operations')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('api_client_customer_grants', function (Blueprint $table): void {
            $table->dropColumn('allowed_operations');
        });
    }
};
