<?php

namespace App\Services\MyTasks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One piece of work that is still on one person, as «Mine oppgaver» shows it.
 *
 * Never stored. A source builds it on every read from the module's own rows — a Wiki assignment, an
 * Anbud aksjon, a supplier's intern ansvarlig and its open follow-up — so it disappears the moment
 * the module's own state says the work is done, and moves the moment the module's own assignment
 * moves. There is no row anybody has to close.
 *
 * The id is stable for as long as the same work stands (module prefix + the module's own id), so a
 * list can key on it across reads; it is never a database key.
 *
 * `reasons` says why it needs follow-up, in the module's own vocabulary — a supplier with an expired
 * document and an overdue control is one task with two reasons, not two tasks. `can_act` is false
 * when the person may read the object but lacks the permission at least one reason asks for: the task
 * is still theirs, and the list says so rather than hiding it or widening their rights.
 *
 * `details` is what the module's own card needs beyond the common fields. It is merged under the
 * common keys, so a source can never override the id, module, group or due date.
 *
 * For fristpåminnelser (TaskDeadlineReminderService):
 *  - `dueSoonDays` is the module's own «nærmer seg frist» window — Leverandører's 60 days for
 *    documentation, Anbud's «Frister innen 7 dager». Null means the module has none of its own and
 *    the shared default (DEFAULT_DUE_SOON_DAYS, the same 7 days Oppfølging has always counted) applies.
 *  - `subject` names the object the task is about — the notification prefix of its module, and the
 *    ids UserNotificationAccessScope checks again every time the reminder is shown.
 */
final class MyTask
{
    public const GROUP_OVERDUE = 'overdue';

    public const GROUP_THIS_WEEK = 'this_week';

    public const GROUP_LATER = 'later';

    public const GROUP_NO_DUE = 'no_due';

    /** «Frister innen 7 dager», the window Oppfølging has always counted as close. */
    public const DEFAULT_DUE_SOON_DAYS = 7;

    /** Display order. */
    public const GROUPS = [
        self::GROUP_OVERDUE,
        self::GROUP_THIS_WEEK,
        self::GROUP_LATER,
        self::GROUP_NO_DUE,
    ];

    /**
     * @param  list<array<string, mixed>>  $reasons  each at least {key: string}, optionally due_on, overdue, can_act
     * @param  array<string, mixed>  $details
     * @param  array{prefix?: string, metadata?: array<string, int>, saved_notice_id?: int|null}  $subject
     */
    public function __construct(
        public readonly string $id,
        public readonly string $module,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $subjectTitle,
        public readonly int $assigneeUserId,
        public readonly ?string $actionUrl,
        public readonly ?CarbonImmutable $dueOn = null,
        public readonly bool $overdue = false,
        public readonly array $reasons = [],
        public readonly bool $canAct = true,
        public readonly array $details = [],
        public readonly ?int $dueSoonDays = null,
        public readonly array $subject = [],
    ) {}

    /** The window before the due date in which a «nærmer seg frist» reminder is due. */
    public function dueSoonWindow(): int
    {
        return $this->dueSoonDays ?? self::DEFAULT_DUE_SOON_DAYS;
    }

    /**
     * Forfalt, Denne uken, Senere or Uten frist. Overdue is the source's word or a due date before
     * today — due today is this week, not overdue. «Denne uken» is the calendar week, Monday to
     * Sunday, whatever the locale says a week is.
     */
    public function group(CarbonInterface $today): string
    {
        $today = CarbonImmutable::parse($today->toDateString());

        if ($this->overdue || ($this->dueOn !== null && $this->dueOn->lt($today))) {
            return self::GROUP_OVERDUE;
        }

        if ($this->dueOn === null) {
            return self::GROUP_NO_DUE;
        }

        return $this->dueOn->lte($today->endOfWeek(CarbonInterface::SUNDAY))
            ? self::GROUP_THIS_WEEK
            : self::GROUP_LATER;
    }

    /** @return array<string, mixed> */
    public function toArray(CarbonInterface $today): array
    {
        return array_merge($this->details, [
            'id' => $this->id,
            'module' => $this->module,
            'type' => $this->type,
            'title' => $this->title,
            'subject_title' => $this->subjectTitle,
            'action_url' => $this->actionUrl,
            'due_on' => $this->dueOn?->toDateString(),
            'overdue' => $this->group($today) === self::GROUP_OVERDUE,
            'group' => $this->group($today),
            'reasons' => $this->reasons,
            'can_act' => $this->canAct,
        ]);
    }
}
