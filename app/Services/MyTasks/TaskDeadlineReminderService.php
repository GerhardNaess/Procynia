<?php

namespace App\Services\MyTasks;

use App\Models\Customer;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\UserNotificationWriter;
use App\Support\CustomerContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fristpåminnelser: one daily look at «Mine oppgaver» for every person, and a bell notification
 * when a task with a deadline is coming up or has passed.
 *
 * ONE MECHANISM FOR EVERY MODULE. The tasks are MyTasksService's — the same list the person sees,
 * from every source, each source applying its module's access rules first. So a reminder is only
 * ever about a task the person still has: completed, reassigned, closed and no-longer-visible work
 * is simply not in the list any more, and access is decided again on every run. Nothing about a
 * deadline is decided here either; the due date and its window are the module's (MyTask::dueOn,
 * MyTask::dueSoonWindow()).
 *
 * TWO REMINDERS PER DEADLINE, AT MOST:
 *  - «Frist nærmer seg» once the due date is within the module's window (Leverandørers 60 days for
 *    documentation, otherwise Oppfølging's 7 days, a KPI's own grace days);
 *  - «Frist passert» once the task is overdue.
 *
 * The dedupe_key is the task's stable id, the kind and the due date (and the recipient). Running the
 * job again — the same day, after a failure, twice by mistake — writes nothing new; a moved deadline
 * is a new situation and is announced once more; an unchanged one never is. A task without a due
 * date gets no «nærmer seg», and an overdue one without a date (a document replaced under a control)
 * one «passert» keyed on the task alone.
 *
 * The notification carries the module's prefix and the task's object, so UserNotificationAccessScope
 * hides it again the moment the person loses access. No mail.
 */
class TaskDeadlineReminderService
{
    public const KIND_DUE_SOON = 'due_soon';

    public const KIND_OVERDUE = 'overdue';

    public function __construct(
        private readonly MyTasksService $tasks,
        private readonly UserNotificationWriter $writer,
        private readonly CustomerContext $customerContext,
    ) {}

    /**
     * Remind every active person of one customer. One person's failure never stops the others.
     *
     * @return array{users: int, due_soon: int, overdue: int, failed: int}
     */
    public function remindCustomer(Customer $customer, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $counts = ['users' => 0, 'due_soon' => 0, 'overdue' => 0, 'failed' => 0];

        User::query()
            ->where('customer_id', $customer->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (User $user) use ($customer, $today, &$counts): void {
                if (! $user->canAccessCustomerFrontend()) {
                    return;
                }

                try {
                    $sent = $this->remindUser($user, (int) $customer->id, $today);
                    $counts['users']++;
                    $counts['due_soon'] += $sent['due_soon'];
                    $counts['overdue'] += $sent['overdue'];
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    Log::warning('Task deadline reminders failed for a user.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);
                }
            });

        return $counts;
    }

    /** @return array{due_soon: int, overdue: int} */
    public function remindUser(User $user, int $customerId, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $sent = ['due_soon' => 0, 'overdue' => 0];
        $locale = $this->customerContext->resolveLanguageCode($user);

        $due = [];

        foreach ($this->tasks->tasksFor($user, $customerId, $today) as $task) {
            $kind = $this->kindFor($task, $today);

            if ($kind !== null && ($task->subject['prefix'] ?? null) !== null) {
                $due[$this->dedupeKey($task, $kind, $user)] = [$task, $kind];
            }
        }

        // What was already sent, in one query: a rerun then writes — and reports — nothing.
        $already = $due === [] ? [] : array_flip(UserNotification::query()
            ->whereIn('dedupe_key', array_keys($due))
            ->pluck('dedupe_key')
            ->all());

        foreach ($due as $key => [$task, $kind]) {
            if (! isset($already[$key]) && $this->send($task, $kind, $user, $customerId, $locale, $key)) {
                $sent[$kind]++;
            }
        }

        return $sent;
    }

    /** The task, the kind of reminder, the deadline it is about, and who it is for. */
    private function dedupeKey(MyTask $task, string $kind, User $user): string
    {
        return sprintf('task.%s:%s:%s:%d', $kind, $task->id, $task->dueOn?->toDateString() ?? 'undated', $user->id);
    }

    /** Which reminder the task is due, if any — the same groups the page shows. */
    public function kindFor(MyTask $task, CarbonImmutable $today): ?string
    {
        if ($task->group($today) === MyTask::GROUP_OVERDUE) {
            return self::KIND_OVERDUE;
        }

        if ($task->dueOn !== null && $task->dueOn->lte($today->addDays($task->dueSoonWindow()))) {
            return self::KIND_DUE_SOON;
        }

        return null;
    }

    private function send(MyTask $task, string $kind, User $user, int $customerId, string $locale, string $dedupeKey): bool
    {
        $dueOn = $task->dueOn?->toDateString();
        $date = $task->dueOn !== null ? $this->formatDate($task->dueOn, $locale) : null;
        $messageKey = $kind === self::KIND_OVERDUE && $date === null ? 'overdue_undated' : $kind;

        return $this->writer->notify(
            $customerId,
            $user,
            $task->subject['prefix'].'.task_'.$kind,
            $dedupeKey,
            __('procynia.task_notifications.reminders.'.$kind.'.title', [], $locale),
            __('procynia.task_notifications.reminders.'.$messageKey.'.message', ['title' => $task->title, 'date' => (string) $date], $locale),
            (string) $task->actionUrl,
            ($task->subject['metadata'] ?? []) + ['task_id' => $task->id, 'module' => $task->module, 'due_on' => $dueOn, 'reminder' => $kind],
            $kind === self::KIND_OVERDUE ? UserNotification::SEVERITY_WARNING : UserNotification::SEVERITY_INFO,
            savedNoticeId: $task->subject['saved_notice_id'] ?? null,
        );
    }

    /** «5. oktober 2026» / «5 October 2026». */
    private function formatDate(CarbonImmutable $date, string $locale): string
    {
        $english = str_starts_with($locale, 'en');

        return $date->locale($english ? 'en' : 'nb')->translatedFormat($english ? 'j F Y' : 'j. F Y');
    }
}
