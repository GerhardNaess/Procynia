<?php

use App\Support\LocaleReferenceCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->seedReferenceTables();

        DB::statement('ALTER TABLE customers ADD COLUMN IF NOT EXISTS nationality_id BIGINT NULL');
        DB::statement('ALTER TABLE customers ADD COLUMN IF NOT EXISTS language_id BIGINT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS customers_nationality_id_index ON customers (nationality_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS customers_language_id_index ON customers (language_id)');
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'customers_nationality_id_foreign') THEN
                    ALTER TABLE customers
                        ADD CONSTRAINT customers_nationality_id_foreign
                        FOREIGN KEY (nationality_id) REFERENCES nationalities (id);
                END IF;

                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'customers_language_id_foreign') THEN
                    ALTER TABLE customers
                        ADD CONSTRAINT customers_language_id_foreign
                        FOREIGN KEY (language_id) REFERENCES languages (id);
                END IF;
            END
            $$;
        ");

        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS nationality_id BIGINT NULL');
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS preferred_language_id BIGINT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS users_nationality_id_index ON users (nationality_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_preferred_language_id_index ON users (preferred_language_id)');
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_nationality_id_foreign') THEN
                    ALTER TABLE users
                        ADD CONSTRAINT users_nationality_id_foreign
                        FOREIGN KEY (nationality_id) REFERENCES nationalities (id) ON DELETE SET NULL;
                END IF;

                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_preferred_language_id_foreign') THEN
                    ALTER TABLE users
                        ADD CONSTRAINT users_preferred_language_id_foreign
                        FOREIGN KEY (preferred_language_id) REFERENCES languages (id) ON DELETE SET NULL;
                END IF;
            END
            $$;
        ");

        $nationalityMap = DB::table('nationalities')->pluck('id', 'code');
        $languageMap = DB::table('languages')->pluck('id', 'code');

        foreach (DB::table('customers')->select('id', 'nationality_code', 'default_language_code')->get() as $customer) {
            $nationalityCode = strtoupper(trim((string) $customer->nationality_code));
            $languageCode = strtolower(trim((string) $customer->default_language_code));
            $nationalityId = $nationalityMap[$nationalityCode] ?? null;
            $languageId = $languageMap[$languageCode] ?? null;

            if ($nationalityId === null) {
                throw new RuntimeException("Missing nationality mapping for customer {$customer->id} with code {$nationalityCode}.");
            }

            if ($languageId === null) {
                throw new RuntimeException("Missing language mapping for customer {$customer->id} with code {$languageCode}.");
            }

            DB::table('customers')
                ->where('id', $customer->id)
                ->update([
                    'nationality_id' => $nationalityId,
                    'language_id' => $languageId,
                    'updated_at' => now(),
                ]);
        }

        foreach (DB::table('users')->select('id', 'nationality_code', 'preferred_language_code')->get() as $user) {
            $nationalityId = null;
            $preferredLanguageId = null;

            if ($user->nationality_code !== null && trim((string) $user->nationality_code) !== '') {
                $nationalityCode = strtoupper(trim((string) $user->nationality_code));
                $nationalityId = $nationalityMap[$nationalityCode] ?? null;

                if ($nationalityId === null) {
                    throw new RuntimeException("Missing nationality mapping for user {$user->id} with code {$nationalityCode}.");
                }
            }

            if ($user->preferred_language_code !== null && trim((string) $user->preferred_language_code) !== '') {
                $preferredLanguageCode = strtolower(trim((string) $user->preferred_language_code));
                $preferredLanguageId = $languageMap[$preferredLanguageCode] ?? null;

                if ($preferredLanguageId === null) {
                    throw new RuntimeException("Missing preferred language mapping for user {$user->id} with code {$preferredLanguageCode}.");
                }
            }

            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'nationality_id' => $nationalityId,
                    'preferred_language_id' => $preferredLanguageId,
                    'updated_at' => now(),
                ]);
        }

        DB::statement('ALTER TABLE customers ALTER COLUMN nationality_id SET NOT NULL');
        DB::statement('ALTER TABLE customers ALTER COLUMN language_id SET NOT NULL');

        if (Schema::hasColumn('customers', 'nationality_code') && Schema::hasColumn('customers', 'default_language_code')) {
            DB::statement('ALTER TABLE customers DROP COLUMN nationality_code, DROP COLUMN default_language_code');
        }

        if (Schema::hasColumn('users', 'nationality_code') && Schema::hasColumn('users', 'preferred_language_code')) {
            DB::statement('ALTER TABLE users DROP COLUMN nationality_code, DROP COLUMN preferred_language_code');
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('nationality_code', 8)->nullable()->after('slug');
            $table->string('default_language_code', 8)->nullable()->after('nationality_code');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('nationality_code', 8)->nullable()->after('department_id');
            $table->string('preferred_language_code', 8)->nullable()->after('nationality_code');
        });

        $nationalityMap = DB::table('nationalities')->pluck('code', 'id');
        $languageMap = DB::table('languages')->pluck('code', 'id');

        foreach (DB::table('customers')->select('id', 'nationality_id', 'language_id')->get() as $customer) {
            $nationalityCode = $nationalityMap[$customer->nationality_id] ?? null;
            $languageCode = $languageMap[$customer->language_id] ?? null;

            if ($nationalityCode === null || $languageCode === null) {
                throw new RuntimeException("Missing reference reverse mapping for customer {$customer->id}.");
            }

            DB::table('customers')
                ->where('id', $customer->id)
                ->update([
                    'nationality_code' => $nationalityCode,
                    'default_language_code' => $languageCode,
                    'updated_at' => now(),
                ]);
        }

        foreach (DB::table('users')->select('id', 'nationality_id', 'preferred_language_id')->get() as $user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'nationality_code' => $user->nationality_id !== null ? ($nationalityMap[$user->nationality_id] ?? null) : null,
                    'preferred_language_code' => $user->preferred_language_id !== null ? ($languageMap[$user->preferred_language_id] ?? null) : null,
                    'updated_at' => now(),
                ]);
        }

        DB::statement('ALTER TABLE customers ALTER COLUMN nationality_code SET NOT NULL');
        DB::statement('ALTER TABLE customers ALTER COLUMN default_language_code SET NOT NULL');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('nationality_id');
            $table->dropConstrainedForeignId('language_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('nationality_id');
            $table->dropConstrainedForeignId('preferred_language_id');
        });
    }

    private function seedReferenceTables(): void
    {
        if (DB::table('nationalities')->count() === 0) {
            DB::table('nationalities')->upsert(
                LocaleReferenceCatalog::nationalities(),
                ['code'],
                ['name_en', 'name_no', 'flag_emoji', 'updated_at'],
            );
        }

        if (DB::table('languages')->count() === 0) {
            DB::table('languages')->upsert(
                LocaleReferenceCatalog::languages(),
                ['code'],
                ['name_en', 'name_no', 'updated_at'],
            );
        }
    }
};
