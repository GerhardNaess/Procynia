import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import ControlHint from '../../../Components/App/ControlHint';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import RequiredMark from '../Risk/RequiredMark';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import ReviewForm from './ReviewForm';
import SectionBasis from './SectionBasis';
import { DecisionList } from './ReviewDecisions';
import {
    ATTENTION_METRICS, DECISIONS, HISTORY, JUDGEMENT_TONES, MODULE_LINKS, OVERVIEW, REVIEW_STATUS_TONES,
    attentionItems, fill, formatDay, formatMoment, judgementLabel, markerLabel, neighbours, paneFromSearch, reviewHelp,
    sectionOptionLabel, sectionStatus, sectionTitle, statusDescription, statusLabel,
} from './reviewSections';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const ERROR = 'mt-1 text-base text-rose-700';
const ICON = 'inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-sm font-bold leading-none';

/** Fremdrift: an empty ring until a judgement is saved, then a filled green tick. */
function ProgressIcon({ progress }) {
    if (progress === 'judged') {
        return (
            <span className={`${ICON} bg-emerald-600 text-white`} data-icon="judged">
                <svg viewBox="0 0 16 16" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" /></svg>
            </span>
        );
    }

    if (progress === 'unavailable') {
        return <span className={`${ICON} text-slate-500`} data-icon="unavailable">–</span>;
    }

    return <span className={`${ICON} border-2 border-slate-400`} data-icon="open" />;
}

/** Oppmerksomhet: a shape of its own (not just a colour) beside, never instead of, the progress. */
function AttentionIcon() {
    return <span className={`${ICON} border border-amber-300 bg-amber-100 text-amber-800`} data-icon="attention">!</span>;
}

/**
 * Ledelsens gjennomgåelse — one review, worked section by section (plan §4.2).
 *
 * Oversikt carries the checklist, participants, what needs attention, the conclusion and the next
 * review. Each section carries its basis (as the server narrowed it for this reader), the
 * management's judgement and the decisions made in it. A finalized review reads the same, frozen,
 * with corrections and the follow-up of each tiltak beside it.
 */
export default function ManagementReviewShow() {
    const props = usePage().props;
    const {
        translations = {},
        review,
        sections = [],
        participants = [],
        decisions = [],
        readiness = null,
        frameworks = [],
        amendments = [],
        history = [],
        permissions = {},
        owner_options: ownerOptions = [],
        participant_options: participantOptions = [],
        area_options: areaOptions = [],
        framework_options: frameworkOptions = [],
        handoff_options: handoffOptions = null,
        errors = {},
    } = props;

    const t = translations?.management_review ?? {};
    const finalized = review.status === 'finalized';
    const [pane, setPane] = useState(() => paneFromSearch(typeof window !== 'undefined' ? window.location.search : '', sections));
    const [panel, setPanel] = useState(null);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);
        if (pane === OVERVIEW) {
            url.searchParams.delete('section');
        } else {
            url.searchParams.set('section', pane);
        }
        window.history.replaceState(window.history.state, '', url.toString());
    }, [pane]);

    // A link to #decision-N opens the decisions pane.
    useEffect(() => {
        if (typeof window !== 'undefined' && window.location.hash.startsWith('#decision-')) {
            setPane(DECISIONS);
        }
    }, []);

    const open = (key) => {
        setPane(key);
        if (typeof window !== 'undefined') {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const destroy = () => {
        if (window.confirm(t.delete_confirm ?? 'Slett utkastet? Dette kan ikke angres.')) {
            router.delete(`/app/management-reviews/${review.id}`);
        }
    };

    const current = sections.find((section) => section.key === pane);
    const ready = Boolean(readiness?.ready);

    return (
        <CustomerAppLayout title={review.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/management-reviews" className="inline-block text-base font-semibold text-violet-700 hover:text-violet-900">← {t.back ?? 'Til ledelsens gjennomgåelse'}</Link>

                <header className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center gap-2" data-testid="mr-status">
                                <StatusBadge tone={REVIEW_STATUS_TONES[review.status] ?? 'slate'}>{statusLabel(review.status, t)}</StatusBadge>
                                {! finalized && ready && <StatusBadge tone="emerald">{t.ready ?? 'Klar for ferdigstilling'}</StatusBadge>}
                            </div>
                            <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{review.title}</h1>
                            <p className="text-base text-slate-700">
                                {formatDay(review.period_start)}–{formatDay(review.period_end)}
                                {' · '}{review.all_business_areas ? (t.scope_all ?? 'Hele virksomheten') : review.business_areas.map((area) => area.name).join(', ')}
                                {review.framework_labels.length > 0 && ` · ${review.framework_labels.join(', ')}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <PageHelpButton {...reviewHelp(t, 'review')} />
                            {permissions.can_edit && panel === null && (
                                <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>{t.edit ?? 'Rediger'}</button>
                            )}
                            <Link href={`/app/management-reviews/${review.id}/report`} className={SECONDARY_ACTION} data-testid="mr-report-link">{t.report_link ?? 'Rapport'}</Link>
                            {permissions.can_delete && (
                                <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{t.delete ?? 'Slett utkast'}</button>
                            )}
                            {permissions.can_finalize && panel === null && (
                                <button
                                    type="button"
                                    onClick={() => setPanel('finalize')}
                                    disabled={! ready}
                                    title={ready ? undefined : t.finalize?.not_ready}
                                    className={PRIMARY_ACTION}
                                    data-testid="mr-finalize"
                                >
                                    {t.finalize?.button ?? 'Ferdigstill'}
                                </button>
                            )}
                        </div>
                    </div>

                    {errors.review && <p role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800">{errors.review}</p>}

                    {finalized && (
                        <p className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-900" data-testid="mr-locked">
                            {fill(t.finalize?.locked ?? 'Gjennomgåelsen ble ferdigstilt :date av :name.', { date: formatMoment(review.finalized_at), name: review.finalized_by_name })}
                        </p>
                    )}
                </header>

                {panel === 'edit' && <EditPanel review={review} participants={participants} t={t} ownerOptions={ownerOptions} areaOptions={areaOptions} frameworkOptions={frameworkOptions} participantOptions={participantOptions} onDone={() => setPanel(null)} />}
                {panel === 'finalize' && <FinalizePanel review={review} readiness={readiness} t={t} onDone={() => setPanel(null)} />}

                <div className="lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-6">
                    <SectionNav sections={sections} decisions={decisions} readiness={readiness} pane={pane} onOpen={open} t={t} />

                    <div className="mt-4 min-w-0 space-y-6 lg:mt-0">
                        {pane === OVERVIEW && (
                            <Overview
                                review={review}
                                sections={sections}
                                participants={participants}
                                readiness={readiness}
                                frameworks={frameworks}
                                amendments={amendments}
                                permissions={permissions}
                                ownerOptions={ownerOptions}
                                participantOptions={participantOptions}
                                finalized={finalized}
                                onOpen={open}
                                t={t}
                            />
                        )}

                        {current && (
                            <SectionPane
                                review={review}
                                section={current}
                                sections={sections}
                                readiness={readiness}
                                decisions={decisions.filter((decision) => decision.section_key === current.key)}
                                ownerOptions={ownerOptions}
                                handoffOptions={handoffOptions}
                                canEdit={Boolean(permissions.can_edit)}
                                finalized={finalized}
                                onOpen={open}
                                t={t}
                            />
                        )}

                        {pane === DECISIONS && (
                            <section className={CARD} aria-labelledby="mr-decisions-heading" data-testid="mr-decisions-pane">
                                <h2 id="mr-decisions-heading" className="text-xl font-semibold text-slate-950">{t.decisions?.heading ?? 'Beslutninger og tiltak'}</h2>
                                <p className="mb-2 mt-1 text-base text-slate-600">{t.decisions?.intro}</p>
                                <DecisionList review={review} decisions={decisions} sections={sections} ownerOptions={ownerOptions} handoffOptions={handoffOptions} canEdit={Boolean(permissions.can_edit)} finalized={finalized} t={t} />
                            </section>
                        )}

                        {pane === HISTORY && (
                            <section className={CARD} aria-labelledby="mr-history-heading">
                                <h2 id="mr-history-heading" className="text-xl font-semibold text-slate-950">{t.history?.heading ?? 'Historikk'}</h2>
                                {history.length === 0 ? (
                                    <p className="mt-2 text-base text-slate-600">{t.history?.empty ?? 'Ingen hendelser.'}</p>
                                ) : (
                                    <ol className="mt-3 divide-y divide-slate-100">
                                        {history.map((event) => (
                                            <li key={event.id} className="flex flex-wrap justify-between gap-2 py-3 text-base">
                                                <span className="text-slate-900">{t.events?.[event.event] ?? event.event}</span>
                                                <span className="text-slate-600">{event.actor_name} · {formatMoment(event.occurred_at)}</span>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>
                        )}
                    </div>
                </div>
            </div>
        </CustomerAppLayout>
    );
}

function SectionNav({ sections, decisions, readiness, pane, onOpen, t }) {
    const ts = t.section ?? {};
    const item = (key, label, status = null) => {
        const active = pane === key;
        const description = status ? statusDescription(status, t) : null;
        const attention = status ? status.attention.length > 0 : false;
        const button = (
            <button
                type="button"
                onClick={() => onOpen(key)}
                aria-current={active ? 'page' : undefined}
                className={`flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left text-base transition ${active ? 'bg-violet-50 font-semibold text-violet-800' : 'text-slate-700 hover:bg-slate-50'}`}
                data-testid={`mr-nav-${key}`}
                data-progress={status?.progress}
                data-attention={status ? String(attention) : undefined}
            >
                <span className="min-w-0 break-words">
                    {label}
                    {description && <span className="sr-only">{`(${description})`}</span>}
                </span>
                {status && (
                    // Both slots always take their room, so a marker coming or going moves nothing.
                    <span className="flex shrink-0 items-center gap-1" aria-hidden="true">
                        <span className="inline-flex h-5 w-5">{attention && <AttentionIcon />}</span>
                        <ProgressIcon progress={status.progress} />
                    </span>
                )}
            </button>
        );

        return (
            <li key={key}>
                {description ? <ControlHint text={description} align="left" className="w-full">{button}</ControlHint> : button}
            </li>
        );
    };

    return (
        <nav aria-label={ts.nav_label ?? 'Seksjoner i gjennomgåelsen'}>
            {/* Phones: one select, so nothing has to scroll sideways. */}
            <label className="block lg:hidden">
                <span className={LABEL}>{ts.nav_select ?? 'Gå til seksjon'}</span>
                <select value={pane} onChange={(event) => onOpen(event.target.value)} className={`mt-1 ${INPUT}`} data-testid="mr-nav-select">
                    <option value={OVERVIEW}>{t.tabs?.overview ?? 'Oversikt'}</option>
                    {sections.map((section) => <option key={section.key} value={section.key}>{sectionOptionLabel(section, t, readiness)}</option>)}
                    <option value={DECISIONS}>{`${t.tabs?.decisions ?? 'Beslutninger og tiltak'} (${decisions.length})`}</option>
                    <option value={HISTORY}>{t.tabs?.history ?? 'Historikk'}</option>
                </select>
            </label>
            <ul className="hidden space-y-1 rounded-[24px] border border-slate-200 bg-white p-3 shadow-sm lg:sticky lg:top-4 lg:block">
                {item(OVERVIEW, t.tabs?.overview ?? 'Oversikt')}
                {sections.map((section) => item(section.key, sectionTitle(section.key, t), sectionStatus(section, readiness)))}
                {item(DECISIONS, `${t.tabs?.decisions ?? 'Beslutninger og tiltak'} (${decisions.length})`)}
                {item(HISTORY, t.tabs?.history ?? 'Historikk')}
            </ul>
        </nav>
    );
}

function Overview({ review, sections, participants, readiness, frameworks, amendments, permissions, ownerOptions, participantOptions, finalized, onOpen, t }) {
    const to = t.overview ?? {};
    const flagged = sections.map((section) => ({ section, items: attentionItems(section) })).filter((row) => row.items.length > 0);

    return (
        <>
            {readiness && (
                <section className={CARD} aria-labelledby="mr-readiness-heading" data-testid="mr-readiness">
                    <h2 id="mr-readiness-heading" className="text-xl font-semibold text-slate-950">{to.readiness_heading ?? 'Klar for ferdigstilling?'}</h2>
                    {readiness.ready && <p className="mt-2 text-base font-semibold text-emerald-800">{to.readiness_ready}</p>}
                    <ul className="mt-3 space-y-2">
                        {readiness.items.map((item) => (
                            <li key={item.key} className="flex gap-2 text-base" data-testid={`mr-readiness-${item.key}`} data-done={item.done ? 'true' : 'false'}>
                                <span aria-hidden="true" className={item.done ? 'text-emerald-700' : 'text-slate-500'}>{item.done ? '✓' : '○'}</span>
                                <span className={item.done ? 'text-slate-800' : 'font-semibold text-slate-950'}>
                                    {to.readiness_items?.[item.key] ?? item.key}
                                    {item.key === 'judgements' && item.sections?.length > 0 && (
                                        <span className="block font-normal text-slate-700">
                                            {to.readiness_missing_sections?.split(':sections')[0]}
                                            {item.sections.map((key, index) => (
                                                <span key={key}>
                                                    {index > 0 && ', '}
                                                    <button type="button" onClick={() => onOpen(key)} className="font-semibold text-violet-700 underline hover:text-violet-900">{sectionTitle(key, t)}</button>
                                                </span>
                                            ))}
                                        </span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {readiness.unavailable.length > 0 && (
                        <p className="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900">
                            {fill(to.readiness_unavailable, { sections: readiness.unavailable.map((key) => sectionTitle(key, t)).join(', ') })}
                        </p>
                    )}
                </section>
            )}

            <section className={CARD} aria-labelledby="mr-attention-heading" data-testid="mr-attention">
                <h2 id="mr-attention-heading" className="text-xl font-semibold text-slate-950">{to.attention_heading ?? 'Hva krever oppmerksomhet'}</h2>
                {flagged.length === 0 ? (
                    <p className="mt-2 text-base text-slate-600">{to.attention_none}</p>
                ) : (
                    <ul className="mt-3 space-y-3">
                        {flagged.map(({ section, items }) => (
                            <li key={section.key}>
                                <button type="button" onClick={() => onOpen(section.key)} className="text-base font-semibold text-violet-700 hover:text-violet-900">{sectionTitle(section.key, t)}</button>
                                <p className="text-base text-slate-800">{items.map((item) => `${item.label}: ${item.value}`).join(' · ')}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <Participants review={review} participants={participants} canEdit={Boolean(permissions.can_edit)} participantOptions={participantOptions} t={t} />
            <Conclusion review={review} canEdit={Boolean(permissions.can_edit)} t={t} />
            <NextReview review={review} canPlan={Boolean(permissions.can_plan_next)} ownerOptions={ownerOptions} t={t} />
            {frameworks.length > 0 && <Frameworks frameworks={frameworks} t={t} />}
            {finalized && <Amendments review={review} amendments={amendments} canAmend={Boolean(permissions.can_amend)} t={t} />}
        </>
    );
}

function SectionPane({ review, section, sections, readiness, decisions, ownerOptions, handoffOptions, canEdit, finalized, onOpen, t }) {
    const ts = t.section ?? {};
    const { previous, next } = neighbours(sections, section.key);
    const intro = t.sections?.[section.key]?.intro;
    const moduleLink = MODULE_LINKS[section.key];

    return (
        <>
            <section className={CARD} aria-labelledby={`mr-section-${section.key}`} data-testid={`mr-section-${section.key}`} data-state={section.state}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id={`mr-section-${section.key}`} className="text-2xl font-semibold text-slate-950">{sectionTitle(section.key, t)}</h2>
                        {intro && <p className="mt-1 text-base text-slate-600">{intro}</p>}
                        <SectionStatus section={section} readiness={readiness} t={t} />
                    </div>
                    {section.state === 'available' && moduleLink && section.has_basis && (
                        <Link href={moduleLink} className="text-base font-semibold text-violet-700 hover:text-violet-900">{ts.open_module ?? 'Se nåsituasjonen i modulen'} →</Link>
                    )}
                </div>

                {section.has_basis && (
                    <div className="mt-5 space-y-4">
                        <h3 className="text-lg font-semibold text-slate-900">{ts.basis ?? 'Grunnlag'}</h3>
                        {section.state !== 'available' ? (
                            <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="mr-section-state">{t.states?.[section.state]}</p>
                        ) : (
                            <>
                                <p className="text-base text-slate-600">
                                    {finalized ? fill(ts.captured_at, { date: formatMoment(section.captured_at) }) : fill(ts.live_at, { date: formatMoment(section.captured_at) })}
                                    {section.basis?.area_scoped && section.basis.areas.length > 0 && ` ${fill(ts.areas, { areas: section.basis.areas.join(', ') })}`}
                                </p>
                                {section.coverage && section.coverage !== 'complete' && (
                                    <p className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900">{t.coverage?.[section.coverage]}</p>
                                )}
                                <SectionBasis basis={section.basis} t={t} attentionKeys={ATTENTION_METRICS[section.key] ?? []} />
                            </>
                        )}
                    </div>
                )}

                <Assessment review={review} section={section} canEdit={canEdit && section.can_assess} t={t} />
            </section>

            <section className={CARD} aria-labelledby={`mr-section-decisions-${section.key}`}>
                <h3 id={`mr-section-decisions-${section.key}`} className="text-lg font-semibold text-slate-900">{ts.decisions_here ?? 'Beslutninger og tiltak i denne seksjonen'}</h3>
                <div className="mt-2">
                    <DecisionList review={review} decisions={decisions} sections={sections} ownerOptions={ownerOptions} handoffOptions={handoffOptions} canEdit={canEdit} finalized={finalized} sectionKey={section.key} t={t} />
                </div>
            </section>

            <div className="flex flex-wrap justify-between gap-2">
                {previous ? <button type="button" onClick={() => onOpen(previous)} className={SECONDARY_ACTION}>← {ts.previous_section ?? 'Forrige seksjon'}</button> : <span />}
                {next ? <button type="button" onClick={() => onOpen(next)} className={SECONDARY_ACTION} data-testid="mr-next-section">{ts.next_section ?? 'Neste seksjon'} →</button> : <span />}
            </div>
        </>
    );
}

/**
 * The same status as the navigation, in words, where the section is open — on a phone the only
 * place it shows, since the section select has no room for icons or tooltips.
 */
function SectionStatus({ section, readiness, t }) {
    const status = sectionStatus(section, readiness);

    return (
        <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-base text-slate-800" data-testid="mr-section-status" data-progress={status.progress} data-attention={String(status.attention.length > 0)}>
            <span className="inline-flex items-center gap-2"><span aria-hidden="true"><ProgressIcon progress={status.progress} /></span>{markerLabel(status.progress, t)}{status.optional && <span className="text-slate-600">{` · ${markerLabel('optional', t)}`}</span>}</span>
            {status.attention.length > 0 && (
                <span className="inline-flex min-w-0 items-start gap-2" data-testid="mr-section-attention">
                    <span aria-hidden="true" className="mt-0.5"><AttentionIcon /></span>
                    <span className="min-w-0 break-words">{markerLabel('attention', t)}: {status.attention.map((item) => `${item.label} ${item.value}`).join(', ')}</span>
                </span>
            )}
        </div>
    );
}

function Assessment({ review, section, canEdit, t }) {
    const ts = t.section ?? {};
    const manual = section.type !== 'module';
    const form = useForm({ judgement: section.judgement ?? '', comment: section.comment ?? '', notes: section.notes ?? '' });

    useEffect(() => {
        form.setData({ judgement: section.judgement ?? '', comment: section.comment ?? '', notes: section.notes ?? '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [section.key, section.judgement, section.comment, section.notes]);

    if (! section.judgement_visible) {
        return <p className="mt-6 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-700">{ts.judgement_hidden}</p>;
    }

    if (! canEdit) {
        return (
            <div className="mt-6 space-y-2 border-t border-slate-100 pt-5" data-testid="mr-assessment-readonly">
                <h3 className="text-lg font-semibold text-slate-900">{ts.assessment ?? 'Ledelsens vurdering'}</h3>
                {manual && section.notes && <p className="whitespace-pre-line break-words text-base text-slate-800">{section.notes}</p>}
                {section.judgement
                    ? <StatusBadge tone={JUDGEMENT_TONES[section.judgement] ?? 'slate'}>{judgementLabel(section.judgement, t)}</StatusBadge>
                    : <p className="text-base text-slate-600">{ts.not_judged ?? 'Ikke vurdert'}</p>}
                {section.comment && <p className="whitespace-pre-line break-words text-base text-slate-800">{section.comment}</p>}
            </div>
        );
    }

    const submit = (event) => {
        event.preventDefault();
        form.put(`/app/management-reviews/${review.id}/sections/${section.key}`, { preserveScroll: true });
    };

    // Takes back only the judgement; the saved comment and description stay as they are.
    const removeJudgement = () => router.put(
        `/app/management-reviews/${review.id}/sections/${section.key}`,
        { judgement: null, comment: section.comment ?? '', notes: section.notes ?? '' },
        { preserveScroll: true },
    );

    return (
        <form onSubmit={submit} className="mt-6 space-y-4 border-t border-slate-100 pt-5" data-testid="mr-assessment-form">
            <h3 className="text-lg font-semibold text-slate-900">{ts.assessment ?? 'Ledelsens vurdering'}</h3>
            {manual && (
                <div>
                    <label htmlFor={`mr-notes-${section.key}`} className={LABEL}>{ts.notes ?? 'Ledelsens beskrivelse'}</label>
                    <textarea id={`mr-notes-${section.key}`} rows={4} maxLength={20000} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className={`mt-1 ${INPUT}`} />
                </div>
            )}
            <fieldset>
                <legend className={LABEL}>{ts.judgement ?? 'Vurdering'}</legend>
                <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1">
                    {['satisfactory', 'needs_improvement', 'not_satisfactory'].map((value) => (
                        <label key={value} className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                            <input type="radio" name={`mr-judgement-${section.key}`} value={value} checked={form.data.judgement === value} onChange={() => form.setData('judgement', value)} className="h-5 w-5" data-testid={`mr-judgement-${value}`} />
                            {judgementLabel(value, t)}
                        </label>
                    ))}
                </div>
                {form.errors.judgement && <p className={ERROR}>{form.errors.judgement}</p>}
            </fieldset>
            <div>
                <label htmlFor={`mr-comment-${section.key}`} className={LABEL}>{ts.comment ?? 'Kommentar'} <span className="font-normal text-slate-600">({ts.comment_hint ?? 'Valgfritt.'})</span></label>
                <textarea id={`mr-comment-${section.key}`} rows={3} maxLength={10000} value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} className={`mt-1 ${INPUT}`} />
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION} data-testid="mr-save-assessment">{ts.save_assessment ?? 'Lagre vurdering'}</button>
                {section.judgement && (
                    <button type="button" onClick={removeJudgement} disabled={form.processing} className={SECONDARY_ACTION} data-testid="mr-remove-assessment">{ts.remove_assessment ?? 'Fjern vurdering'}</button>
                )}
            </div>
        </form>
    );
}

function Participants({ review, participants, canEdit, participantOptions, t }) {
    const to = t.overview ?? {};
    const [adding, setAdding] = useState(false);
    const form = useForm({ user_id: '', name: '', role_label: '' });
    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, user_id: data.user_id || null }));
        form.post(`/app/management-reviews/${review.id}/participants`, { preserveScroll: true, onSuccess: () => { form.reset(); setAdding(false); } });
    };
    const remove = (participant) => router.delete(`/app/management-reviews/${review.id}/participants/${participant.id}`, { preserveScroll: true });

    return (
        <section className={CARD} aria-labelledby="mr-participants-heading" data-testid="mr-participants">
            <h2 id="mr-participants-heading" className="text-xl font-semibold text-slate-950">{to.participants_heading ?? 'Deltakere'}</h2>
            {participants.length === 0 ? (
                <p className="mt-2 text-base text-slate-600">{to.participants_empty}</p>
            ) : (
                <ul className="mt-3 divide-y divide-slate-100">
                    {participants.map((participant) => (
                        <li key={participant.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span className="text-base text-slate-900">{participant.name}{participant.role_label && <span className="text-slate-600"> – {participant.role_label}</span>}</span>
                            {canEdit && <button type="button" onClick={() => remove(participant)} className="text-base font-semibold text-rose-700 hover:text-rose-900">{to.remove ?? 'Fjern'}</button>}
                        </li>
                    ))}
                </ul>
            )}
            {canEdit && (adding ? (
                <form onSubmit={submit} className="mt-3 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <label htmlFor="mr-participant-user" className={LABEL}>{to.participant_user}</label>
                            <select id="mr-participant-user" value={form.data.user_id} onChange={(event) => form.setData('user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{to.participant_choose}</option>
                                {participantOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                            </select>
                            {form.errors.user_id && <p className={ERROR}>{form.errors.user_id}</p>}
                        </div>
                        <div>
                            <label htmlFor="mr-participant-name" className={LABEL}>{to.participant_name}</label>
                            <input id="mr-participant-name" maxLength={255} value={form.data.name} disabled={Boolean(form.data.user_id)} onChange={(event) => form.setData('name', event.target.value)} className={`mt-1 ${INPUT}`} />
                            {form.errors.name && <p className={ERROR}>{form.errors.name}</p>}
                        </div>
                        <div>
                            <label htmlFor="mr-participant-role" className={LABEL}>{to.participant_role}</label>
                            <input id="mr-participant-role" maxLength={255} value={form.data.role_label} onChange={(event) => form.setData('role_label', event.target.value)} className={`mt-1 ${INPUT}`} />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" disabled={form.processing} className={PRIMARY_ACTION} data-testid="mr-participant-save">{to.add_participant ?? 'Legg til deltaker'}</button>
                        <button type="button" onClick={() => setAdding(false)} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
                    </div>
                </form>
            ) : (
                <button type="button" onClick={() => setAdding(true)} className={`mt-3 ${SECONDARY_ACTION}`} data-testid="mr-add-participant">{to.add_participant ?? 'Legg til deltaker'}</button>
            ))}
        </section>
    );
}

function Conclusion({ review, canEdit, t }) {
    const to = t.overview ?? {};
    const form = useForm({ conclusion: review.conclusion ?? '' });
    const submit = (event) => {
        event.preventDefault();
        form.put(`/app/management-reviews/${review.id}/conclusion`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="mr-conclusion-heading" data-testid="mr-conclusion">
            <h2 id="mr-conclusion-heading" className="text-xl font-semibold text-slate-950">{to.conclusion_heading ?? 'Ledelsens samlede konklusjon'}</h2>
            {canEdit ? (
                <form onSubmit={submit} className="mt-2 space-y-3">
                    <label htmlFor="mr-conclusion-text" className="block text-base text-slate-600">{to.conclusion_hint}</label>
                    <textarea id="mr-conclusion-text" rows={5} maxLength={20000} value={form.data.conclusion} onChange={(event) => form.setData('conclusion', event.target.value)} className={INPUT} />
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION} data-testid="mr-save-conclusion">{t.save ?? 'Lagre'}</button>
                </form>
            ) : (
                <p className="mt-2 whitespace-pre-line break-words text-base text-slate-800">{review.conclusion || to.conclusion_empty}</p>
            )}
        </section>
    );
}

function NextReview({ review, canPlan, ownerOptions, t }) {
    const to = t.overview ?? {};
    const form = useForm({ next_review_due_on: review.next_review_due_on ?? '', owner_user_id: review.owner_user_id ? String(review.owner_user_id) : '' });
    const submit = (event) => {
        event.preventDefault();
        form.put(`/app/management-reviews/${review.id}/next-review`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="mr-next-heading">
            <h2 id="mr-next-heading" className="text-xl font-semibold text-slate-950">{to.next_heading ?? 'Neste gjennomgåelse'}</h2>
            <p className="mt-1 text-base text-slate-600">{to.next_hint}</p>
            {canPlan ? (
                <form onSubmit={submit} className="mt-3 grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                    <div>
                        <label htmlFor="mr-next-date" className={LABEL}>{t.fields?.next_review_due_on ?? 'Neste gjennomgåelse innen'}</label>
                        <input id="mr-next-date" type="date" value={form.data.next_review_due_on} onChange={(event) => form.setData('next_review_due_on', event.target.value)} className={`mt-1 ${INPUT}`} />
                    </div>
                    <div>
                        <label htmlFor="mr-next-owner" className={LABEL}>{t.fields?.owner ?? 'Ansvarlig'}</label>
                        <select id="mr-next-owner" value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                            {ownerOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                        </select>
                        {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                    </div>
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{t.save ?? 'Lagre'}</button>
                </form>
            ) : (
                <p className="mt-2 text-base text-slate-800">{review.next_review_due_on ? formatDay(review.next_review_due_on) : to.next_none} · {review.owner_name ?? t.no_owner}</p>
            )}
        </section>
    );
}

function Frameworks({ frameworks, t }) {
    const tf = t.frameworks ?? {};
    const tone = { assessed: 'emerald', not_assessed: 'amber', not_available: 'slate' };

    return (
        <section className={CARD} aria-labelledby="mr-frameworks-heading" data-testid="mr-frameworks">
            <h2 id="mr-frameworks-heading" className="text-xl font-semibold text-slate-950">{tf.heading ?? 'Dekning av rammeverk'}</h2>
            {frameworks.some((framework) => framework.coverage !== 'none') && (
                <p className="mt-2 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-base text-sky-900" data-testid="mr-framework-disclaimer">{tf.disclaimer}</p>
            )}
            {frameworks.map((framework) => (
                <div key={framework.key} className="mt-4" data-testid={`mr-framework-${framework.key}`} data-coverage={framework.coverage}>
                    <h3 className="break-words text-lg font-semibold text-slate-900">{framework.label}{framework.version && ` (${framework.version})`}</h3>
                    {framework.coverage === 'none' && <p className="mt-1 text-base text-slate-600">{tf.no_coverage ?? 'Valgt i gjennomgåelsens omfang. Automatisk dekningsanalyse er ikke tilgjengelig for dette rammeverket.'}</p>}
                    {framework.inputs.length > 0 && <ul className="mt-2 divide-y divide-slate-100">
                        {framework.inputs.map((input) => (
                            <li key={`${framework.key}-${input.key}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span className="min-w-0 text-base text-slate-800"><span className="text-slate-600">{input.clause}</span> {tf[framework.key]?.inputs?.[input.key] ?? input.key}</span>
                                <StatusBadge tone={tone[input.state] ?? 'slate'}>{t.framework_states?.[input.state] ?? input.state}</StatusBadge>
                            </li>
                        ))}
                    </ul>}
                </div>
            ))}
        </section>
    );
}

function Amendments({ review, amendments, canAmend, t }) {
    const ta = t.amendments ?? {};
    const [adding, setAdding] = useState(false);
    const form = useForm({ text: '', reason: '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/management-reviews/${review.id}/amendments`, { preserveScroll: true, onSuccess: () => { form.reset(); setAdding(false); } });
    };

    return (
        <section className={CARD} aria-labelledby="mr-amendments-heading" data-testid="mr-amendments">
            <h2 id="mr-amendments-heading" className="text-xl font-semibold text-slate-950">{ta.heading ?? 'Rettelser etter ferdigstilling'}</h2>
            <p className="mt-1 text-base text-slate-600">{ta.intro}</p>
            {amendments.length === 0 ? (
                <p className="mt-2 text-base text-slate-600">{ta.empty}</p>
            ) : (
                <ul className="mt-3 space-y-3">
                    {amendments.map((amendment) => (
                        <li key={amendment.id} className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <p className="whitespace-pre-line break-words text-base text-slate-900">{amendment.text}</p>
                            <p className="mt-1 break-words text-base text-slate-600">{ta.reason ?? 'Begrunnelse'}: {amendment.reason}</p>
                            <p className="text-base text-slate-600">{fill(ta.by, { name: amendment.created_by_name, date: formatMoment(amendment.created_at) })}</p>
                        </li>
                    ))}
                </ul>
            )}
            {canAmend && (adding ? (
                <form onSubmit={submit} className="mt-3 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div>
                        <label htmlFor="mr-amendment-text" className={LABEL}>{ta.text ?? 'Rettelse'}<RequiredMark /></label>
                        <textarea id="mr-amendment-text" required rows={3} value={form.data.text} onChange={(event) => form.setData('text', event.target.value)} className={`mt-1 ${INPUT}`} />
                        {form.errors.text && <p className={ERROR}>{form.errors.text}</p>}
                    </div>
                    <div>
                        <label htmlFor="mr-amendment-reason" className={LABEL}>{ta.reason ?? 'Begrunnelse'}<RequiredMark /></label>
                        <textarea id="mr-amendment-reason" required rows={2} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className={`mt-1 ${INPUT}`} />
                        {form.errors.reason && <p className={ERROR}>{form.errors.reason}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{ta.add ?? 'Registrer rettelse'}</button>
                        <button type="button" onClick={() => setAdding(false)} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
                    </div>
                </form>
            ) : (
                <button type="button" onClick={() => setAdding(true)} className={`mt-3 ${SECONDARY_ACTION}`} data-testid="mr-add-amendment">{ta.add ?? 'Registrer rettelse'}</button>
            ))}
        </section>
    );
}

function EditPanel({ review, participants, t, ownerOptions, areaOptions, frameworkOptions, participantOptions, onDone }) {
    // Someone already taking part stays choosable even if no longer an active person in Procynia.
    const chosenPeople = participants.filter((participant) => participant.user_id !== null);
    const peopleOptions = [
        ...participantOptions,
        ...chosenPeople.filter((participant) => ! participantOptions.some((person) => person.id === participant.user_id)).map((participant) => ({ id: participant.user_id, name: participant.name })),
    ];
    const form = useForm({
        title: review.title,
        purpose: review.purpose ?? '',
        period_start: review.period_start,
        period_end: review.period_end,
        meeting_date: review.meeting_date ?? '',
        all_business_areas: review.all_business_areas,
        business_area_ids: review.business_areas.map((area) => area.id),
        frameworks: review.frameworks,
        owner_user_id: review.owner_user_id ? String(review.owner_user_id) : '',
        participant_user_ids: chosenPeople.map((participant) => participant.user_id),
    });
    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, meeting_date: data.meeting_date || null }));
        form.patch(`/app/management-reviews/${review.id}`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD} aria-label={t.edit ?? 'Rediger'}>
            <ReviewForm form={form} onSubmit={submit} onCancel={onDone} t={t} ownerOptions={ownerOptions} areaOptions={areaOptions} frameworkOptions={frameworkOptions} participantOptions={peopleOptions} submitLabel={t.save ?? 'Lagre'} />
        </section>
    );
}

function FinalizePanel({ review, readiness, t, onDone }) {
    const tf = t.finalize ?? {};
    const [processing, setProcessing] = useState(false);
    const confirm = () => {
        setProcessing(true);
        router.post(`/app/management-reviews/${review.id}/finalize`, {}, { preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: onDone });
    };

    return (
        <section className="rounded-[24px] border border-violet-200 bg-violet-50 p-6" aria-labelledby="mr-finalize-heading" data-testid="mr-finalize-panel">
            <h2 id="mr-finalize-heading" className="text-xl font-semibold text-slate-950">{tf.heading ?? 'Ferdigstill gjennomgåelsen'}</h2>
            <p className="mt-2 text-base text-slate-800">{tf.intro}</p>
            {readiness?.unavailable?.length > 0 && (
                <p className="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900">
                    {fill(tf.limited, { sections: readiness.unavailable.map((key) => sectionTitle(key, t)).join(', ') })}
                </p>
            )}
            <div className="mt-4 flex flex-wrap gap-2">
                <button type="button" onClick={confirm} disabled={processing} className={PRIMARY_ACTION} data-testid="mr-finalize-confirm">{tf.confirm ?? 'Ferdigstill'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </section>
    );
}
