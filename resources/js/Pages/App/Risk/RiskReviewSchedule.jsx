import StatusBadge from '../../../Components/App/StatusBadge';

/** "2026-10-04" as "4. oktober 2026", read as a calendar day so no time zone can shift it. */
function formatDay(day) {
    if (! day) {
        return null;
    }

    const [year, month, date] = day.split('-').map(Number);

    return new Date(year, month - 1, date).toLocaleDateString('nb-NO', { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * Periodisk vurdering on the risk page. Every value comes from the server, which derives the next
 * review from the latest assessment and the interval; the page only words it. A review is done by
 * registering a new risk assessment, so there is no action here.
 */
export default function RiskReviewSchedule({ schedule, status, tr }) {
    const trReview = tr.review ?? {};
    const interval = schedule.interval_months;
    const intervalLabel = interval ? (trReview.intervals?.[interval] ?? String(interval)) : (trReview.no_interval ?? 'Ingen fast intervall');

    let next;
    if (! interval) {
        next = <span className="text-slate-500">{trReview.no_next_without_interval ?? 'Ingen fast vurdering'}</span>;
    } else if (! schedule.next_review_on) {
        next = <span className="text-slate-500">{trReview.pending_first ?? 'Neste vurdering beregnes etter første risikovurdering.'}</span>;
    } else {
        next = (
            <span className="inline-flex flex-wrap items-center gap-2">
                <span>{formatDay(schedule.next_review_on)}</span>
                {schedule.is_overdue && <StatusBadge tone="rose">{trReview.overdue ?? 'Forfalt'}</StatusBadge>}
            </span>
        );
    }

    return (
        <div className="mt-6 border-t border-slate-100 pt-5">
            <dl className="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt className="text-sm font-semibold text-slate-600">{trReview.field_interval ?? 'Vurderingsintervall'}</dt>
                    <dd className="mt-1 text-base text-slate-900">{intervalLabel}</dd>
                </div>
                <div>
                    <dt className="text-sm font-semibold text-slate-600">{trReview.last_assessed ?? 'Sist vurdert'}</dt>
                    <dd className="mt-1 text-base text-slate-900">
                        {formatDay(schedule.last_assessed_on) ?? <span className="text-slate-500">{trReview.never_assessed ?? 'Ikke vurdert'}</span>}
                    </dd>
                </div>
                <div>
                    <dt className="text-sm font-semibold text-slate-600">{trReview.next_review ?? 'Neste vurdering'}</dt>
                    <dd className="mt-1 text-base text-slate-900">{next}</dd>
                </div>
            </dl>
            {interval && status === 'closed' && (
                <p className="mt-3 text-sm text-slate-500">{trReview.closed_note ?? 'Lukket risiko følges ikke opp periodisk.'}</p>
            )}
        </div>
    );
}
