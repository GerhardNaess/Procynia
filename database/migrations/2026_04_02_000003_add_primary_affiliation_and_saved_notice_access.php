<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'primary_affiliation_scope')) {
                    $table->string('primary_affiliation_scope')->nullable()->after('bid_manager_scope');
                }

                if (! Schema::hasColumn('users', 'primary_department_id')) {
                    $table->unsignedBigInteger('primary_department_id')->nullable()->after('primary_affiliation_scope');
                }
            });

            DB::table('users')
                ->select(['id', 'department_id', 'primary_affiliation_scope', 'primary_department_id'])
                ->orderBy('id')
                ->chunk(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $scope = $row->primary_affiliation_scope;
                        $primaryDepartmentId = $row->primary_department_id;

                        if ($scope !== null || $primaryDepartmentId !== null) {
                            continue;
                        }

                        DB::table('users')
                            ->where('id', $row->id)
                            ->update([
                                'primary_affiliation_scope' => $row->department_id !== null
                                    ? User::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT
                                    : User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
                                'primary_department_id' => $row->department_id !== null
                                    ? (int) $row->department_id
                                    : null,
                            ]);
                    }
                });
        }

        if (Schema::hasTable('saved_notices')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                if (! Schema::hasColumn('saved_notices', 'organizational_department_id')) {
                    $table->unsignedBigInteger('organizational_department_id')->nullable()->after('customer_id');
                }
            });

            DB::table('saved_notices')
                ->select(['id', 'saved_by_user_id', 'organizational_department_id'])
                ->whereNull('organizational_department_id')
                ->whereNotNull('saved_by_user_id')
                ->orderBy('id')
                ->chunk(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $primaryDepartmentId = DB::table('users')
                            ->where('id', $row->saved_by_user_id)
                            ->value('primary_department_id');

                        if ($primaryDepartmentId === null) {
                            $primaryDepartmentId = DB::table('users')
                                ->where('id', $row->saved_by_user_id)
                                ->value('department_id');
                        }

                        DB::table('saved_notices')
                            ->where('id', $row->id)
                            ->update([
                                'organizational_department_id' => $primaryDepartmentId !== null
                                    ? (int) $primaryDepartmentId
                                    : null,
                            ]);
                    }
                });
        }

        if (! Schema::hasTable('saved_notice_user_access')) {
            Schema::create('saved_notice_user_access', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('access_role')->default('contributor');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['saved_notice_id', 'user_id']);
                $table->index(['user_id', 'revoked_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('saved_notice_user_access')) {
            Schema::drop('saved_notice_user_access');
        }

        if (Schema::hasTable('saved_notices') && Schema::hasColumn('saved_notices', 'organizational_department_id')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->dropColumn('organizational_department_id');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (Schema::hasColumn('users', 'primary_department_id')) {
                    $table->dropColumn('primary_department_id');
                }

                if (Schema::hasColumn('users', 'primary_affiliation_scope')) {
                    $table->dropColumn('primary_affiliation_scope');
                }
            });
        }
    }
};
