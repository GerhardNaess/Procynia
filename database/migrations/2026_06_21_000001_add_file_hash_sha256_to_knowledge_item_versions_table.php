<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_versions', function (Blueprint $table): void {
            $table->string('file_hash_sha256', 64)->nullable()->after('uploaded_at');
            $table->index(['customer_id', 'file_hash_sha256'], 'kiv_customer_hash_index');
        });

        $versions = DB::table('knowledge_item_versions')
            ->whereNull('file_hash_sha256')
            ->whereNotNull('storage_path')
            ->select(['id', 'storage_path'])
            ->get();

        foreach ($versions as $version) {
            $absolutePath = storage_path('app') . '/' . ltrim((string) $version->storage_path, '/');

            if (! file_exists($absolutePath)) {
                continue;
            }

            $hash = hash_file('sha256', $absolutePath);

            if ($hash === false) {
                continue;
            }

            DB::table('knowledge_item_versions')
                ->where('id', $version->id)
                ->update(['file_hash_sha256' => $hash]);
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_item_versions', function (Blueprint $table): void {
            $table->dropIndex('kiv_customer_hash_index');
            $table->dropColumn('file_hash_sha256');
        });
    }
};
