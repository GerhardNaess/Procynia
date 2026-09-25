<?php

namespace App\Services;

use App\Models\SavedNotice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a bid case needs attention, defined once.
 *
 * The cockpit has shown "frister innen 5 dager" and "uten aktivitet siste 7 dager" for a while, and
 * the notifications now say the same things out loud. Two implementations of the same sentence would
 * drift apart within a release — a case the dashboard calls stale and the notification does not is
 * worse than either behaviour alone — so both read from here.
 *
 * Deliberately only the domain question. Building the cockpit's display payloads stays in
 * DashboardController, where it belongs; what moved is the part that decides whether a case counts.
 */
class SavedNoticeWorkflowSignals
{
    public const DEADLINE_SOON_DAYS = 5;

    public const INACTIVE_DAYS = 7;

    /**
     * The dated commitments a case carries, and what each is called.
     *
     * The official deadline IS the RFP submission deadline, so it is listed once, as "Frist". The
     * legacy rfp_submission_deadline_at column is deliberately absent: it would produce a second
     * entry for the same deadline, and nothing can edit it any more.
     *
     * @var array<string, string>
     */
    public const DEADLINE_DEFINITIONS = [
        'deadline' => 'Frist',
        'questions_deadline_at' => 'Spørsmålsfrist',
        'questions_rfi_deadline_at' => 'Spørsmål / RFI',
        'rfi_submission_deadline_at' => 'RFI-innlevering',
        'questions_rfp_deadline_at' => 'Spørsmål / RFP',
        'award_date_at' => 'Tildeling',
    ];

    /**
     * Dates the cockpit shows but nobody has to act on.
     *
     * A tildelingsdato is when the buyer intends to decide. It belongs on the timeline — it tells
     * you when to expect an answer — but warning someone five days before it would be asking them
     * to do something about a date they do not control. Notifications are for work; the cockpit is
     * for the whole picture, and the two are allowed to differ.
     *
     * @var list<string>
     */
    public const MILESTONE_ONLY_DEADLINES = [
        'award_date_at',
    ];

    /**
     * Statuses where the case is still being worked on.
     *
     * A won, lost, no-go, withdrawn or archived case has nothing left to chase, and telling its bid
     * manager that a deadline is approaching would be telling them about a decision already made.
     *
     * @var list<string>
     */
    public const ACTIVE_BID_STATUSES = [
        SavedNotice::BID_STATUS_DISCOVERED,
        SavedNotice::BID_STATUS_QUALIFYING,
        SavedNotice::BID_STATUS_GO_NO_GO,
        SavedNotice::BID_STATUS_IN_PROGRESS,
        SavedNotice::BID_STATUS_SUBMITTED,
        SavedNotice::BID_STATUS_NEGOTIATION,
    ];

    /**
     * Whether the case is still live work.
     *
     * Archived is checked separately from status: a case can be archived while its bid_status still
     * reads "Under arbeid", and the archive is the stronger statement.
     */
    public function isActive(SavedNotice $notice): bool
    {
        return $notice->archived_at === null
            && in_array((string) $notice->bid_status, self::ACTIVE_BID_STATUSES, true);
    }

    /**
     * The last time anything happened on this case, or null when nothing ever has.
     *
     * Three signals, because each means something a user would call activity: the case row itself
     * changing, someone writing a phase comment, and a submission being registered.
     */
    public function latestActivityAt(SavedNotice $notice): ?CarbonInterface
    {
        $activityDates = collect([
            $notice->updated_at,
            $notice->phaseComments->max('created_at'),
            $notice->submissions->max('submitted_at'),
        ])->filter();

        if ($activityDates->isEmpty()) {
            return null;
        }

        return $activityDates->sortDesc()->first();
    }

    /** Whether the case has gone quiet for at least the given number of days. */
    public function isInactive(SavedNotice $notice, ?int $days = null): bool
    {
        $latestActivityAt = $this->latestActivityAt($notice);
        $cutoff = now()->subDays($days ?? self::INACTIVE_DAYS)->startOfDay();

        return $latestActivityAt === null || $latestActivityAt->lessThan($cutoff);
    }

    /**
     * The case's dated commitments falling inside the window, soonest first.
     *
     * Today counts: a deadline that is today has not passed, and is the one most worth saying.
     *
     * @return list<array{type: string, label: string, date: CarbonInterface}>
     */
    public function deadlinesWithin(SavedNotice $notice, ?int $days = null): array
    {
        $from = now()->startOfDay();
        $until = now()->addDays($days ?? self::DEADLINE_SOON_DAYS)->endOfDay();
        $entries = [];

        foreach (self::DEADLINE_DEFINITIONS as $attribute => $label) {
            $value = $notice->{$attribute};

            if ($value === null) {
                continue;
            }

            $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

            if ($date->lessThan($from) || $date->greaterThan($until)) {
                continue;
            }

            $entries[] = ['type' => $attribute, 'label' => $label, 'date' => $date];
        }

        usort($entries, static fn (array $a, array $b): int => $a['date']->getTimestamp() <=> $b['date']->getTimestamp());

        return $entries;
    }

    /**
     * The same window, narrowed to the dates that are somebody's work.
     *
     * Kept separate from deadlinesWithin() rather than narrowing it, because the cockpit is right
     * to list the milestones too: a timeline that quietly dropped the award date would be missing
     * the thing people ask about most.
     *
     * @return list<array{type: string, label: string, date: CarbonInterface}>
     */
    public function actionableDeadlinesWithin(SavedNotice $notice, ?int $days = null): array
    {
        return array_values(array_filter(
            $this->deadlinesWithin($notice, $days),
            static fn (array $entry): bool => ! in_array($entry['type'], self::MILESTONE_ONLY_DEADLINES, true),
        ));
    }
}
