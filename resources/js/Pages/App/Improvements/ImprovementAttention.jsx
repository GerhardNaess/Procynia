import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { attentionSummary } from './improvementStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

const fill = (text, values) => Object.entries(values)
    .reduce((result, [name, value]) => result.replace(`:${name}`, String(value ?? '')), text ?? '');

/**
 * «Trenger oppmerksomhet» on the register. The server has already applied the rules
 * (ImprovementAttentionService) to the cases this person may see, and only to those, and written why
 * each one is listed; this renders the counts, the categories with hits and their lists.
 */
export default function ImprovementAttention({ attention, tr }) {
    const ta = tr.attention ?? {};
    const categories = attention.categories ?? [];
    const scope = attention.scope === 'all'
        ? (ta.scope_all ?? 'Saker og tiltak for hele virksomheten')
        : (ta.scope_areas ?? 'Saker og tiltak i dine fagområder');

    return (
        <section aria-labelledby="improvement-attention-heading" className={CARD} data-testid="improvement-attention">
            <p className="text-base font-semibold text-slate-500">{scope}</p>
            <h2 id="improvement-attention-heading" className="mt-1 text-xl font-semibold text-slate-950">
                {ta.heading ?? 'Trenger oppmerksomhet'}
            </h2>

            {categories.length === 0 ? (
                <p className="mt-3 text-base text-slate-600" data-testid="improvement-attention-empty">
                    {ta.empty ?? 'Ingen saker eller tiltak trenger oppmerksomhet akkurat nå.'}
                </p>
            ) : (
                <>
                    <p className="mt-3 text-2xl font-semibold text-amber-800" data-testid="improvement-attention-summary">
                        {attentionSummary(attention.case_total ?? 0, attention.action_total ?? 0, ta)}
                    </p>
                    <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                        {ta.overlap_hint ?? 'Hver sak og hvert tiltak telles én gang, selv om de har flere funn. Lukkede og avbrutte saker er ikke med.'}
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
    const listId = `improvement-attention-${category.key}`;
    const label = ta.categories?.[category.key] ?? category.key;

    return (
        <li className="min-w-0 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3" data-testid={`improvement-attention-category-${category.key}`}>
            <div className="flex items-start gap-3">
                <p className="text-2xl font-semibold text-amber-800" data-testid="improvement-attention-count">{category.count}</p>
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-semibold text-slate-950">{label}</h3>
                    <button
                        type="button"
                        className="mt-1 inline-flex min-h-10 items-center text-base font-semibold text-violet-700 hover:text-violet-900"
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
                        <li key={`${category.subject}-${item.id}`} className="rounded-xl bg-white px-3 py-2" data-testid="improvement-attention-item">
                            <Link href={item.url} className="break-words text-base font-semibold text-violet-700 hover:text-violet-900">
                                {item.title}
                            </Link>
                            {(item.case_title || item.area_name) && (
                                <p className="break-words text-base text-slate-500">
                                    {[item.case_title, item.area_name].filter(Boolean).join(' · ')}
                                </p>
                            )}
                            <p className="mt-0.5 text-base text-slate-600">{item.detail}</p>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * The case page's note: the case's own findings and how many of its tiltak need attention. Not the
 * panel again — the tiltak cards say which and why.
 */
export function ImprovementAttentionNote({ attention, tr }) {
    if (! attention) {
        return null;
    }

    const ta = tr.attention ?? {};
    const count = attention.action_count ?? 0;

    return (
        <section
            aria-label={ta.heading ?? 'Trenger oppmerksomhet'}
            className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4"
            data-testid="improvement-attention-note"
        >
            <h2 className="text-base font-semibold text-amber-900">{ta.heading ?? 'Trenger oppmerksomhet'}</h2>
            <ul className="mt-1 space-y-0.5 text-base text-slate-800">
                {(attention.reasons ?? []).map((reason) => (
                    <li key={reason.key}>{reason.detail}</li>
                ))}
                {count > 0 && (
                    <li>
                        {count === 1
                            ? (ta.case_actions_one ?? '1 tiltak trenger oppmerksomhet')
                            : fill(ta.case_actions_many ?? ':count tiltak trenger oppmerksomhet', { count })}
                    </li>
                )}
            </ul>
        </section>
    );
}
