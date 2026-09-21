<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The data half of the Wiki approver migration, run against the shape a real customer was in:
 * approve_wiki_pages explicitly granted to the QA column, with a QA user publishing today.
 *
 * Separating the two capabilities could easily have been a silent revocation — the people who were
 * publishing did so through a column that no longer carries the permission. The migration moves the
 * grant instead of dropping it, and this runs the real migration file rather than a copy so the two
 * cannot drift.
 */
class WikiApproverMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_existing_qa_grant_is_moved_rather_than_dropped(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['permission_settings' => [
            'approve_wiki_claims' => ['system_owner', 'qa'],
            'approve_wiki_pages' => ['system_owner', 'qa'],
        ]])->save();

        $qa = $this->user($customer, true);
        $plain = $this->user($customer, false);

        $this->assertTrue($qa->fresh()->canApproveWikiPages(), 'publishes today via the QA column');

        // The real migration, on the real pre-migration shape. Postgres DDL is transactional, so
        // dropping the column here is undone with the rest of the test.
        Schema::table('users', fn ($table) => $table->dropColumn('is_wiki_approver'));

        $migration = require base_path('database/migrations/2026_09_21_000001_add_is_wiki_approver_to_users_table.php');
        $migration->up();

        $customer->refresh();
        $roles = $customer->permission_settings['approve_wiki_pages'];

        $this->assertNotContains('qa', $roles, 'the QA column no longer carries page publishing');
        $this->assertContains(Customer::ROLE_WIKI_APPROVER, $roles);
        $this->assertTrue((bool) $qa->fresh()->is_wiki_approver, 'the publisher became a Wiki approver');
        $this->assertTrue($qa->fresh()->canApproveWikiPages(), 'effective access preserved');
        $this->assertTrue($qa->fresh()->canApproveWikiClaims(), 'and QA is untouched');
        $this->assertFalse($plain->fresh()->canApproveWikiPages(), 'and nobody gained it');
    }

    private function user(Customer $customer, bool $isQa): User
    {
        return User::query()->create([
            'name' => 'Mig '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@mig.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_qa' => $isQa,
            'is_wiki_approver' => false,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Migrasjonstest AS',
            'slug' => 'mig-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
