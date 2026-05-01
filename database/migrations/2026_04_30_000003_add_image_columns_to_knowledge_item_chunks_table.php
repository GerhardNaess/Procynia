<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('table_metadata');
            $table->string('image_disk')->nullable()->after('image_path');
            $table->string('image_mime_type', 191)->nullable()->after('image_disk');
            $table->string('image_original_filename')->nullable()->after('image_mime_type');
            $table->unsignedInteger('image_width')->nullable()->after('image_original_filename');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
            $table->string('image_hash', 128)->nullable()->after('image_height');
            $table->json('image_metadata')->nullable()->after('image_hash');
            $table->longText('image_alt_text')->nullable()->after('image_metadata');
            $table->longText('image_caption')->nullable()->after('image_alt_text');
            $table->longText('ocr_text')->nullable()->after('image_caption');
            $table->longText('image_description')->nullable()->after('ocr_text');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->dropColumn([
                'image_path',
                'image_disk',
                'image_mime_type',
                'image_original_filename',
                'image_width',
                'image_height',
                'image_hash',
                'image_metadata',
                'image_alt_text',
                'image_caption',
                'ocr_text',
                'image_description',
            ]);
        });
    }
};
