<?php

namespace Tests\Unit\MyTasks;

use App\Services\MyTasks\MyTask;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: «Mine oppgaver» groups — Forfalt, Denne uken, Senere, Uten frist — decided the same way
 * for every module, and a source's details can never override the common fields.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class MyTaskTest extends TestCase
{
    // Thursday.
    private const TODAY = '2026-10-08';

    public function test_a_date_before_today_is_overdue_and_today_is_this_week(): void
    {
        $today = CarbonImmutable::parse(self::TODAY);

        $this->assertSame(MyTask::GROUP_OVERDUE, $this->task('2026-10-07')->group($today));
        $this->assertSame(MyTask::GROUP_THIS_WEEK, $this->task('2026-10-08')->group($today));
    }

    public function test_this_week_ends_on_sunday_whatever_the_locale(): void
    {
        $today = CarbonImmutable::parse(self::TODAY);

        $this->assertSame(MyTask::GROUP_THIS_WEEK, $this->task('2026-10-11')->group($today));
        $this->assertSame(MyTask::GROUP_LATER, $this->task('2026-10-12')->group($today));
    }

    public function test_without_a_date_it_has_no_deadline_unless_the_source_says_overdue(): void
    {
        $today = CarbonImmutable::parse(self::TODAY);

        $this->assertSame(MyTask::GROUP_NO_DUE, $this->task(null)->group($today));
        // A replaced document behind a control is overdue with no date to show.
        $this->assertSame(MyTask::GROUP_OVERDUE, $this->task(null, overdue: true)->group($today));
        // And an overdue reason wins over a later date elsewhere on the same task.
        $this->assertSame(MyTask::GROUP_OVERDUE, $this->task('2026-12-01', overdue: true)->group($today));
    }

    public function test_details_never_override_the_common_fields(): void
    {
        $task = new MyTask(
            id: 'supplier-7',
            module: 'supplier',
            type: 'supplier_follow_up',
            title: 'Drift AS',
            subjectTitle: 'Drift AS',
            assigneeUserId: 3,
            actionUrl: '/app/supplier-management/7',
            details: ['id' => 99, 'module' => 'wiki', 'group' => 'later', 'extra' => 'kept'],
        );

        $row = $task->toArray(CarbonImmutable::parse(self::TODAY));

        $this->assertSame('supplier-7', $row['id']);
        $this->assertSame('supplier', $row['module']);
        $this->assertSame(MyTask::GROUP_NO_DUE, $row['group']);
        $this->assertSame('kept', $row['extra']);
    }

    private function task(?string $dueOn, bool $overdue = false): MyTask
    {
        return new MyTask(
            id: 'x-1',
            module: 'tender',
            type: 'action',
            title: 'Oppgave',
            subjectTitle: null,
            assigneeUserId: 1,
            actionUrl: null,
            dueOn: $dueOn !== null ? CarbonImmutable::parse($dueOn) : null,
            overdue: $overdue,
        );
    }
}
