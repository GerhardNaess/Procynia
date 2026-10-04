import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { formatDay } from './RiskReviewSchedule';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * «Trenger oppmerksomhet» on the register. The server has already applied the rules
 * (RiskAttentionService) to the risks this person may see, and only to those; this renders the
 * total, the categories with hits and why each risk is listed.
 */
export default function RiskAttention({ attention, tr }) {
    const ta = tr.attention ?? {};
    const total = attention.total ?? 0;
    const categories = attention.categories ?? [];
    const scope = attention.scope === 'all'
        ? (ta.scope_all ?? 'Risikobildet for hele virksomheten')
        : (ta.scope_areas ?? 'Risikobildet for dine fagområder');

    return (
        <section aria-labelledby="risk-attention-heading" className={CARD}>
            <p className="text-sm font-semibold text-slate-500">{scope}</p>
            <h2 id="risk-attention-heading" className="mt-1 text-xl font-semibold text-slate-950">
                {ta.heading ?? 'Trenger oppmerksomhet'}
            </h2>

            {total === 0 ? (
                <p className="mt-3 text-base text-slate-600" data-testid="risk-attention-empty">
                    {ta.empty ?? 'Ingen risikoer krever oppmerksomhet akkurat nå.'}
                </p>
            ) : (
                <>
                    <p className="mt-3 text-2xl font-semibold text-amber-800" data-testid="risk-attention-total">
                        {total === 1
                            ? (ta.total_one ?? '1 risiko krever oppmerksomhet')
                            : (ta.total_many ?? ':count risikoer krever oppmerksomhet').replace(':count', String(total))}
                    </p>
                    <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                        {ta.overlap_hint ?? 'En risiko kan ha flere funn samtidig, så kategoriene kan overlappe.'}
                    </p>

                    <ul className="mt-4 grid gap-3 lg:grid-cols-2">
                        {categories.map((category) => (
                            <AttentionCategory key={category.key} category={category} ta={ta} tr={tr} />
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

function AttentionCategory({ category, ta, tr }) {
    const [open, setOpen] = useState(false);
    const listId = `risk-attention-${category.key}`;

    return (
        <li className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3" data-testid={`risk-attention-category-${category.key}`}>
            <div className="flex items-start gap-3">
                <p className="text-2xl font-semibold text-amber-800" data-testid="risk-attention-count">{category.count}</p>
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-semibold text-slate-950">{ta.categories?.[category.key] ?? category.key}</h3>
                    <button
                        type="button"
                        className="mt-1 text-sm font-semibold text-violet-700 hover:text-violet-900"
                        aria-expanded={open}
                        aria-controls={listId}
                        onClick={() => setOpen((value) => ! value)}
                    >
                        {open ? (ta.hide ?? 'Skjul') : (ta.show ?? 'Vis risikoer')}
                    </button>
                </div>
            </div>

            {open && (
                <ul id={listId} className="mt-3 space-y-2">
                    {category.risks.map((risk) => (
                        <li key={risk.id} className="rounded-xl bg-white px-3 py-2">
                            <Link href={risk.url} className="text-sm font-semibold text-violet-700 hover:text-violet-900">
                                {risk.title}
                            </Link>
                            {risk.area_name && <span className="ml-2 text-sm text-slate-500">{risk.area_name}</span>}
                            <p className="mt-0.5 text-sm text-slate-600">{reason(category.key, risk.detail ?? {}, ta, tr)}</p>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/** Why the risk is listed, in one sentence, from the facts the server sent. */
function reason(key, detail, ta, tr) {
    const reasons = ta.reasons ?? {};
    const fill = (text, values) => Object.entries(values)
        .reduce((result, [name, value]) => result.replace(`:${name}`, String(value ?? '')), text ?? '');

    switch (key) {
        case 'high_residual':
            return fill(reasons.high_residual ?? 'Restrisiko i siste vurdering er :level (score :score).', {
                level: (tr.assessment?.levels?.[detail.level] ?? detail.level ?? '').toLowerCase(),
                score: detail.score,
            });
        case 'residual_not_assessed':
            return fill(reasons.residual_not_assessed ?? 'Siste vurdering (:date) har ikke restrisiko.', { date: formatDay(detail.assessed_on) });
        case 'review_overdue':
            return fill(reasons.review_overdue ?? 'Ny vurdering skulle vært gjort innen :date.', { date: formatDay(detail.next_review_on) });
        case 'acceptance_expired':
            return fill(reasons.acceptance_expired ?? 'Aksepten av restrisikoen gjaldt til og med :date.', { date: formatDay(detail.valid_until) });
        case 'actions_overdue':
            return detail.count === 1
                ? fill(reasons.actions_overdue_one ?? 'Ett åpent tiltak har passert fristen :date.', { date: formatDay(detail.earliest_due_on) })
                : fill(reasons.actions_overdue_many ?? ':count åpne tiltak har passert fristen, det eldste :date.', {
                    count: detail.count,
                    date: formatDay(detail.earliest_due_on),
                });
        default:
            return reasons.not_assessed ?? 'Risikoen er aktiv, men er ikke vurdert.';
    }
}
