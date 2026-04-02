<?php

namespace Tests\Unit;

use App\Models\BidSubmission;
use App\Models\SavedNotice;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BidSubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connectionName = 'bid_submission_sqlite';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);

        Schema::dropIfExists('bid_submissions');
        Schema::dropIfExists('saved_notices');

        Schema::create('saved_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('saved_by_user_id')->nullable();
            $table->string('external_id')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('bid_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('label');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['saved_notice_id', 'sequence_number']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('bid_submissions');
        Schema::dropIfExists('saved_notices');

        parent::tearDown();
    }

    public function test_first_submission_gets_sequence_one_and_initial_submission_label(): void
    {
        $notice = $this->createSavedNotice('2026-2001');
        $submittedAt = Carbon::parse('2026-04-01 10:00:00');

        $submission = $notice->createNextSubmission($submittedAt);

        $this->assertSame(1, $submission->sequence_number);
        $this->assertSame('Initial Submission', $submission->label);
        $this->assertTrue($submission->submitted_at->equalTo($submittedAt));
    }

    public function test_next_submissions_increment_sequence_and_use_revised_labels(): void
    {
        $notice = $this->createSavedNotice('2026-2002');

        $firstSubmission = $notice->createNextSubmission();
        $secondSubmission = $notice->createNextSubmission();
        $thirdSubmission = $notice->createNextSubmission();

        $this->assertSame(1, $firstSubmission->sequence_number);
        $this->assertSame('Initial Submission', $firstSubmission->label);
        $this->assertSame(2, $secondSubmission->sequence_number);
        $this->assertSame('Revised Submission 1', $secondSubmission->label);
        $this->assertSame(3, $thirdSubmission->sequence_number);
        $this->assertSame('Revised Submission 2', $thirdSubmission->label);
    }

    public function test_sequence_is_unique_per_saved_notice(): void
    {
        $firstNotice = $this->createSavedNotice('2026-2003');
        $secondNotice = $this->createSavedNotice('2026-2004');

        $firstNotice->createNextSubmission();
        $secondSubmission = $secondNotice->createNextSubmission();

        $this->assertSame(1, $secondSubmission->sequence_number);

        $this->expectException(QueryException::class);

        BidSubmission::query()->create([
            'saved_notice_id' => $firstNotice->id,
            'sequence_number' => 1,
            'label' => 'Duplicate Sequence',
        ]);
    }

    public function test_submissions_relation_returns_results_in_sequence_order(): void
    {
        $notice = $this->createSavedNotice('2026-2005');

        BidSubmission::query()->create([
            'saved_notice_id' => $notice->id,
            'sequence_number' => 2,
            'label' => 'Revised Submission 1',
        ]);

        BidSubmission::query()->create([
            'saved_notice_id' => $notice->id,
            'sequence_number' => 1,
            'label' => 'Initial Submission',
        ]);

        $this->assertSame(
            ['Initial Submission', 'Revised Submission 1'],
            $notice->fresh()->submissions->pluck('label')->all(),
        );
    }

    private function createSavedNotice(string $externalId): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => 1,
            'external_id' => $externalId,
            'title' => 'Test notice',
        ]);
    }
}
