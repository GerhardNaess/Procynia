import { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION } from '../../../Support/actionStyles';
import ReviewForm from './ReviewForm';
import { JUDGEMENT_TONES, REVIEW_STATUS_TONES, fill, formatDay, judgementLabel, reviewHelp, sectionTitle, statusLabel } from './reviewSections';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Ledelsens gjennomgåelse → the register: when the next review is due, the tiltak still open from
 * earlier reviews, every review with its status, and the management's judgements over time. Every
 * row and number is already narrowed by the server to what this person may see.
 */
export default function ManagementReviewIndex() {
    const {
        translations = {},
        reviews = [],
        next_review: nextReview = null,
        open_actions: openActions = [],
        comparison = null,
        permissions = {},
        create_defaults: defaults = null,
        owner_options: ownerOptions = [],
        participant_options: participantOptions = [],
        area_options: areaOptions = [],
        framework_options: frameworkOptions = [],
    } = usePage().props;

    const t = translations?.management_review ?? {};
    const ti = t.index ?? {};
    const tf = t.fields ?? {};
    const [creating, setCreating] = useState(false);
    const form = useForm({
        title: defaults?.title ?? '',
        purpose: '',
        period_start: defaults?.period_start ?? '',
        period_end: defaults?.period_end ?? '',
        meeting_date: '',
        all_business_areas: true,
        business_area_ids: [],
        frameworks: defaults?.frameworks ?? [],
        owner_user_id: defaults?.owner_user_id ? String(defaults.owner_user_id) : '',
        participant_user_ids: [],
    });

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, meeting_date: data.meeting_date || null }));
        form.post('/app/management-reviews', { preserveScroll: true });
    };

    return (
        <CustomerAppLayout title={t.index_title ?? 'Ledelsens gjennomgåelse'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{t.index_heading ?? 'Ledelsens gjennomgåelse'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">{t.index_subtitle}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...reviewHelp(t, 'index')} />
                        {permissions.can_create && ! creating && (
                            <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION} data-testid="mr-create">{t.create ?? 'Ny gjennomgåelse'}</button>
                        )}
                    </div>
                </header>

                {creating && (
                    <section className={CARD} aria-labelledby="mr-create-heading">
                        <h2 id="mr-create-heading" className="text-xl font-semibold text-slate-950">{t.create_heading ?? 'Ny ledelsens gjennomgåelse'}</h2>
                        <p className="mb-4 mt-1 text-base text-slate-600">{t.create_hint}</p>
                        <ReviewForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { setCreating(false); form.reset(); form.clearErrors(); }}
                            t={t}
                            ownerOptions={ownerOptions}
                            areaOptions={areaOptions}
                            frameworkOptions={frameworkOptions}
                            participantOptions={participantOptions}
                            submitLabel={t.create_submit ?? 'Opprett gjennomgåelse'}
                        />
                    </section>
                )}

                {nextReview && (
                    <section className={`${CARD} ${nextReview.overdue && ! nextReview.has_draft ? 'border-amber-200 bg-amber-50' : ''}`} aria-labelledby="mr-next-heading" data-testid="mr-next-review">
                        <h2 id="mr-next-heading" className="text-xl font-semibold text-slate-950">{ti.next_heading ?? 'Neste gjennomgåelse'}</h2>
                        <p className="mt-1 text-base text-slate-800">
                            {fill(ti.next_due, { date: formatDay(nextReview.due_on) })}. {fill(ti.next_decided_in, { title: nextReview.decided_in })}
                            {nextReview.has_draft ? ` ${ti.next_has_draft}` : (nextReview.overdue ? ` ${ti.next_overdue}` : '')}
                        </p>
                    </section>
                )}

                <section className={CARD} aria-labelledby="mr-open-actions-heading" data-testid="mr-open-actions">
                    <h2 id="mr-open-actions-heading" className="text-xl font-semibold text-slate-950">{ti.open_actions_heading ?? 'Åpne tiltak fra ledelsens gjennomgåelser'}</h2>
                    {openActions.length === 0 ? (
                        <p className="mt-2 text-base text-slate-600">{ti.open_actions_empty ?? 'Ingen åpne tiltak.'}</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-slate-100">
                            {openActions.map((action) => (
                                <li key={action.id} className="space-y-1 py-3">
                                    <Link href={action.url} className="break-words text-base font-semibold text-violet-700 hover:text-violet-900">{action.text}</Link>
                                    <p className="break-words text-base text-slate-700">
                                        {action.review_title}
                                        {action.owner_name ? ` · ${action.owner_name}` : ''}
                                        {action.due_date ? ` · ${t.decisions?.due_date ?? 'Frist'} ${formatDay(action.due_date)}` : ''}
                                        {action.overdue && <span className="font-semibold text-rose-700"> · {t.decisions?.overdue ?? 'Frist passert'}</span>}
                                    </p>
                                    {action.case && ! action.case.hidden && (
                                        <p className="text-base text-slate-600">{fill(t.decisions?.in_case, { title: action.case.title })} · {t.values?.[`case_${action.case.status}`] ?? action.case.status}</p>
                                    )}
                                    {action.case?.hidden && <p className="text-base text-slate-600">{t.decisions?.case_hidden}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className={CARD} aria-labelledby="mr-reviews-heading">
                    <h2 id="mr-reviews-heading" className="text-xl font-semibold text-slate-950">{ti.reviews_heading ?? 'Gjennomgåelser'}</h2>
                    {reviews.length === 0 ? (
                        <EmptyStateBox className="mt-4" title={t.empty_title ?? 'Ingen gjennomgåelser ennå'} description={permissions.can_create ? t.empty_hint : null} />
                    ) : (
                        <>
                            <ul className="mt-4 divide-y divide-slate-100 md:hidden" data-testid="mr-review-list">
                                {reviews.map((review) => (
                                    <li key={review.id} className="space-y-2 py-4">
                                        <StatusBadge tone={REVIEW_STATUS_TONES[review.status] ?? 'slate'}>{statusLabel(review.status, t)}</StatusBadge>
                                        <Link href={review.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">{review.title}</Link>
                                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-base">
                                            <dt className="font-semibold text-slate-600">{tf.period ?? 'Periode'}</dt>
                                            <dd className="text-slate-800">{formatDay(review.period_start)}–{formatDay(review.period_end)}</dd>
                                            <dt className="font-semibold text-slate-600">{tf.meeting_date ?? 'Møtedato'}</dt>
                                            <dd className="text-slate-800">{formatDay(review.meeting_date)}</dd>
                                            <dt className="font-semibold text-slate-600">{tf.owner ?? 'Ansvarlig'}</dt>
                                            <dd className="min-w-0 break-words text-slate-800">{review.owner_name ?? '—'}</dd>
                                            <dt className="font-semibold text-slate-600">{tf.open_actions ?? 'Åpne tiltak'}</dt>
                                            <dd className="text-slate-800">{review.open_actions_count}</dd>
                                        </dl>
                                    </li>
                                ))}
                            </ul>
                            <div className="mt-4 hidden overflow-x-auto md:block">
                                <table className="w-full text-base" data-testid="mr-review-table">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left font-semibold text-slate-600">
                                            <th className="pb-3 pr-4">{tf.list_title ?? 'Gjennomgåelse'}</th>
                                            <th className="px-4 pb-3">{tf.period ?? 'Periode'}</th>
                                            <th className="px-4 pb-3">{tf.meeting_date ?? 'Møtedato'}</th>
                                            <th className="px-4 pb-3">{tf.owner ?? 'Ansvarlig'}</th>
                                            <th className="px-4 pb-3">{tf.decisions_count ?? 'Beslutninger'}</th>
                                            <th className="pb-3 pl-4">{tf.status ?? 'Status'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {reviews.map((review) => (
                                            <tr key={review.id}>
                                                <td className="py-3 pr-4 align-top"><Link href={review.url} className="font-semibold text-violet-700 hover:text-violet-900">{review.title}</Link></td>
                                                <td className="whitespace-nowrap px-4 py-3 align-top text-slate-700">{formatDay(review.period_start)}–{formatDay(review.period_end)}</td>
                                                <td className="whitespace-nowrap px-4 py-3 align-top text-slate-700">{formatDay(review.meeting_date)}</td>
                                                <td className="px-4 py-3 align-top text-slate-700">{review.owner_name ?? '—'}</td>
                                                <td className="px-4 py-3 align-top text-slate-700">{review.decisions_count}{review.open_actions_count > 0 ? ` (${review.open_actions_count} ${(tf.open_actions ?? 'Åpne tiltak').toLowerCase()})` : ''}</td>
                                                <td className="py-3 pl-4 align-top"><StatusBadge tone={REVIEW_STATUS_TONES[review.status] ?? 'slate'}>{statusLabel(review.status, t)}</StatusBadge></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>

                {comparison && (
                    <section className={CARD} aria-labelledby="mr-comparison-heading" data-testid="mr-comparison">
                        <h2 id="mr-comparison-heading" className="text-xl font-semibold text-slate-950">{ti.comparison_heading ?? 'Utvikling over tid'}</h2>
                        <p className="mt-1 text-base text-slate-600">{ti.comparison_intro}</p>
                        <ul className="mt-4 space-y-4">
                            {comparison.rows.map((row) => (
                                <li key={row.key}>
                                    <p className="text-base font-semibold text-slate-900">{sectionTitle(row.key, t)}</p>
                                    <div className="mt-1 flex flex-wrap gap-2">
                                        {row.cells.map((cell, index) => (
                                            <span key={`${row.key}-${comparison.reviews[index].id}`} className="flex flex-wrap items-center gap-2 text-base text-slate-700">
                                                <span>{comparison.reviews[index].title}:</span>
                                                {cell === 'hidden'
                                                    ? <span className="text-slate-500">{ti.comparison_hidden ?? 'Ingen tilgang'}</span>
                                                    : cell
                                                        ? <StatusBadge tone={JUDGEMENT_TONES[cell] ?? 'slate'}>{judgementLabel(cell, t)}</StatusBadge>
                                                        : <span className="text-slate-500">{t.section?.not_judged ?? 'Ikke vurdert'}</span>}
                                            </span>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </CustomerAppLayout>
    );
}
