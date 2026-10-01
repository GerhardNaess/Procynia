<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per commercial package a customer has asked for or holds.
     *
     * Entitlements are stored per package, not per technical module: the package -> module mapping
     * lives in config/procynia_modules.php and is resolved at read time, so a package can gain a
     * module later without rewriting anyone's rows.
     *
     * The mandatory core package is never stored here. Its absence is not a lack of access.
     */
    public function up(): void
    {
        Schema::create('customer_package_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('package_key');
            $table->string('status')->default('requested');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'package_key']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_package_entitlements');
    }
};
