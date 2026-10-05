import { useState } from 'react';
import { Link } from '@inertiajs/react';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

const fill = (text, values) => Object.entries(values)
    .reduce((result, [name, value]) => result.replace(`:${name}`, String(value ?? '')), text ?? '');

/**
 * «Trenger oppmerksomhet» on the objectives overview. The server has already applied the rules
 * (ObjectiveAttentionService) to the objectives this person may see, and only to those, and
 * written why each one is listed; this renders the counts, the categories with hits and the lists.
 * Objectives and KPIs are counted apart — never added up into one number.
 */
export default function ObjectiveAttention({ attention, tr }) {
    const ta = tr.attention ?? {};
    const objectiveTotal = attention.objective_total ?? 0;
    const kpiTotal = attention.kpi_total ?? 0;
    const categories = attention.categories ?? [];
    const scope = attention.scope === 'all'
        ? (ta.scope_all ?? 'Mål og KPI-er for hele virksomheten')
        : (ta.scope_areas ?? 'Mål og KPI-er i dine fagområder');

    const objectives = objectiveTotal === 1
        ? (ta.objectives_one ?? '1 mål')
        : fill(ta.objectives_many ?? ':count mål', { count: objectiveTotal });
    const kpis = kpiTotal === 1
        ? (ta.kpis_one ?? '1 KPI')
        : fill(ta.kpis_many ?? ':count KPI-er', { count: kpiTotal });
    const summary = objectiveTotal > 0 && kpiTotal > 0
        ? fill(ta.summary ?? ':objectives og :kpis trenger oppmerksomhet', { objectives, kpis })
        : objectiveTotal > 0
            ? fill(ta.summary_objectives ?? ':objectives trenger oppmerksomhet', { objectives })
            : fill(ta.summary_kpis ?? ':kpis trenger oppmerksomhet', { kpis });

    return (
        <section aria-labelledby="objective-attention-heading" className={CARD} data-testid="objective-attention">
            <p className="text-sm font-semibold text-slate-500">{scope}</p>
            <h2 id="objective-attention-heading" className="mt-1 text-xl font-semibold text-slate-950">
                {ta.heading ?? 'Trenger oppmerksomhet'}
            </h2>

            {categories.length === 0 ? (
                <p className="mt-3 text-base text-slate-600" data-testid="objective-attention-empty">
                    {ta.empty ?? 'Ingen mål eller KPI-er trenger oppmerksomhet akkurat nå.'}
                </p>
            ) : (
                <>
                    <p className="mt-3 text-2xl font-semibold text-amber-800" data-testid="objective-attention-summary">{summary}</p>
                    <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                        {ta.overlap_hint ?? 'Hvert mål og hver KPI telles én gang, selv om de har flere funn. Lukkede mål og avsluttede KPI-er er ikke med.'}
                    </p>

                    <ul className="mt-4 grid gap-3 lg:grid-cols-2">
                        {categories.map((category) => (
                            <AttentionCategory key={category.key} category={category} ta={ta} />
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

function AttentionCategory({ category, ta }) {
    const [open, setOpen] = useState(false);
    const listId = `objective-attention-${category.key}`;
    const label = ta.categories?.[category.key] ?? category.key;

    return (
        <li className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3" data-testid={`objective-attention-category-${category.key}`}>
            <div className="flex items-start gap-3">
                <p className="text-2xl font-semibold text-amber-800" data-testid="objective-attention-count">{category.count}</p>
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-semibold text-slate-950">{label}</h3>
                    <button
                        type="button"
                        className="mt-1 text-sm font-semibold text-violet-700 hover:text-violet-900"
                        aria-expanded={open}
                        aria-controls={listId}
                        onClick={() => setOpen((value) => ! value)}
                    >
                        {open ? (ta.hide ?? 'Skjul') : (ta.show ?? 'Vis')}
                    </button>
                </div>
            </div>

            {open && (
                <ul id={listId} className="mt-3 space-y-2">
                    {category.items.map((item) => (
                        <li key={`${category.subject}-${item.id}`} className="rounded-xl bg-white px-3 py-2" data-testid="objective-attention-item">
                            <Link href={item.url} className="text-sm font-semibold text-violet-700 hover:text-violet-900">
                                {item.title}
                            </Link>
                            {(item.objective_title || item.area_name) && (
                                <span className="ml-2 text-sm text-slate-500">
                                    {[item.objective_title, item.area_name].filter(Boolean).join(' · ')}
                                </span>
                            )}
                            <p className="mt-0.5 text-sm text-slate-600">{item.detail}</p>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * The objective page's note: its own findings and how many of its KPIs need attention. Not the
 * panel again — the KPI list and the KPI pages say which and why.
 */
export function ObjectiveAttentionNote({ attention, tr }) {
    if (! attention) {
        return null;
    }

    const ta = tr.attention ?? {};
    const count = attention.kpi_count ?? 0;

    return (
        <section
            aria-label={ta.heading ?? 'Trenger oppmerksomhet'}
            className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4"
            data-testid="objective-attention-note"
        >
            <h2 className="text-base font-semibold text-amber-900">{ta.heading ?? 'Trenger oppmerksomhet'}</h2>
            <ul className="mt-1 space-y-0.5 text-sm text-slate-800">
                {(attention.reasons ?? []).map((reason) => (
                    <li key={reason.key}>{reason.detail}</li>
                ))}
                {count > 0 && (
                    <li>
                        {count === 1
                            ? (ta.objective_kpis_one ?? '1 KPI trenger oppmerksomhet')
                            : fill(ta.objective_kpis_many ?? ':count KPI-er trenger oppmerksomhet', { count })}
                    </li>
                )}
            </ul>
        </section>
    );
}
