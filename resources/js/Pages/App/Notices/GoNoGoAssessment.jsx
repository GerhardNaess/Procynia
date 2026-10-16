import { router } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { useRef, useState } from 'react';
import PageHelpPanel from '../../../Components/App/PageHelpPanel';
import { useTranslations } from '../../../Support/useTranslations';
import { useTransitionAnnouncement } from '../../../Support/statusAnnouncement';

function ChevronDown({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={className} aria-hidden="true">
            <path d="m5 7.5 5 5 5-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function ChevronUp({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={className} aria-hidden="true">
            <path d="m5 12.5 5-5 5 5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function CheckIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={className} aria-hidden="true">
            <path d="m5 10.5 3.2 3.2L15 7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

const RATING_VALUES = ['lav', 'middels', 'hoy'];
const RATING_LABELS = { lav: 'Lav', middels: 'Middels', hoy: 'Høy' };

// Colors keyed by semantic goodness (reversed for criteria like Risiko)
const ACTIVE_COLOR = {
    good:    'border-emerald-200 bg-emerald-50 text-emerald-700',
    neutral: 'border-amber-200 bg-amber-50 text-amber-700',
    bad:     'border-rose-200 bg-rose-50 text-rose-700',
};
const INACTIVE_COLOR = {
    good:    'border-slate-200 bg-white text-slate-600 hover:border-emerald-200 hover:text-emerald-600',
    neutral: 'border-slate-200 bg-white text-slate-600 hover:border-amber-200 hover:text-amber-600',
    bad:     'border-slate-200 bg-white text-slate-600 hover:border-rose-200 hover:text-rose-600',
};

function ratingSemantic(ratingValue, isReversed) {
    const direct   = { lav: 'bad', middels: 'neutral', hoy: 'good' };
    const inverted = { lav: 'good', middels: 'neutral', hoy: 'bad' };
    return (isReversed ? inverted : direct)[ratingValue];
}

function ratingNumericValue(ratingValue, isReversed) {
    const base = { lav: 1, middels: 2, hoy: 3 }[ratingValue] ?? 0;
    return isReversed ? (4 - base) : base;
}

// Build help sections from a criterion's help fields
function criterionHelpSections(criterion) {
    const items = [
        { title: 'Hva vurderes?',             text: criterion.help_what_is_assessed    },
        { title: 'Hvorfor er dette viktig?',   text: criterion.help_why_it_matters      },
        { title: 'Hva bør dere undersøke?',    text: criterion.help_what_to_investigate },
        { title: 'Positive indikatorer',       text: criterion.help_positive_indicators },
        { title: 'Faresignaler',               text: criterion.help_warning_signs       },
        { title: 'Eksempel på god vurdering',  text: criterion.help_example_assessment  },
    ].filter(item => item.text);
    return [{ items }];
}

// Compute verdict — only when ALL active criteria are answered
function computeVerdict(criteria, answers) {
    const allAnswered = criteria.every(c => answers[c.id]?.selected_value);
    if (!allAnswered) return null;

    const maxScore = criteria.reduce((s, c) => s + c.weight * 3, 0);
    const total    = criteria.reduce((s, c) => {
        const rv = answers[c.id]?.selected_value;
        return rv ? s + ratingNumericValue(rv, c.is_score_reversed) * c.weight : s;
    }, 0);

    const pct = maxScore > 0 ? (total / maxScore) * 100 : 0;

    if (pct >= 75) return { label: 'Sterkt grunnlag for Go',               level: 'go',    dot: 'bg-emerald-500', className: 'border-emerald-200 bg-emerald-50 text-emerald-800' };
    if (pct >= 55) return { label: 'Krever nærmere avklaringer',            level: 'avklar', dot: 'bg-amber-400',   className: 'border-amber-200 bg-amber-50 text-amber-800' };
    return            { label: 'Svakt beslutningsgrunnlag – vurder No-go', level: 'nogo',  dot: 'bg-rose-500',    className: 'border-rose-200 bg-rose-50 text-rose-800' };
}

function generateSummary(criteria, answers) {
    const allAnswered = criteria.every(c => answers[c.id]?.selected_value);
    if (!allAnswered) return null;

    const join = pts => {
        const names = pts.map(p => p.title);
        if (!names.length) return '';
        return names.length === 1 ? names[0] : names.slice(0, -1).join(', ') + ' og ' + names[names.length - 1];
    };

    const strengths = criteria.filter(c => {
        const rv = answers[c.id]?.selected_value;
        return rv && (c.is_score_reversed ? rv === 'lav' : rv === 'hoy');
    });
    const concerns = criteria.filter(c => {
        const rv = answers[c.id]?.selected_value;
        return rv && (c.is_score_reversed ? rv === 'hoy' : rv === 'lav');
    });

    if (strengths.length && concerns.length) return `Basert på vurderingene fremstår ${join(strengths).toLowerCase()} som identifiserte styrker. ${join(concerns)} bør avklares nærmere.`;
    if (strengths.length) return `Basert på vurderingene er saken sterk på ${join(strengths).toLowerCase()}. Ingen punkter er vurdert som svake.`;
    if (concerns.length) return `Basert på vurderingene er ${join(concerns).toLowerCase()} identifisert som punkter som krever avklaring.`;
    return 'Vurderingene er balanserte. Helhetsbildet er moderat – ingen klare styrker eller svakheter er registrert.';
}

function WeightBadge({ weight, isReversed }) {
    return (
        <span className="inline-flex items-center rounded border border-slate-200 bg-slate-100 px-2 py-1 text-base font-semibold leading-6 text-slate-600">
            Vekt {weight}{isReversed ? ' · reversert' : ''}
        </span>
    );
}

/**
 * The Go/No-go assessment on a case: one Lav/Middels/Høy rating and an optional reasoning per
 * criterion, a preliminary verdict once every criterion is rated, and a save.
 *
 * Accessibility (A11Y-003): each criterion's three ratings are a named group (the criterion's title,
 * described by its short description) of toggle buttons with aria-pressed — a rating can be cleared by
 * pressing it again, which a radio group cannot express. The chosen rating carries a check mark as
 * well as its colour. The reasoning field is named after its criterion, and each «?» says which
 * criterion it explains and returns focus to itself when the help closes. When the verdict appears or
 * changes it is spoken once; a rejected save says so beside the button. «Lagre vurdering» stays
 * focusable while it saves and once saved (aria-disabled), so focus is not dropped to <body>.
 */
export default function GoNoGoAssessment({ template, assessment, saveUrl }) {
    const a11y = useTranslations().a11y ?? {};
    // Initialise local answer state from persisted assessment (if any)
    const [answers, setAnswers] = useState(() => {
        const map = {};
        (assessment?.answers ?? []).forEach(a => {
            map[a.criterion_id] = { selected_value: a.selected_value, comment: a.comment ?? '' };
        });
        return map;
    });

    const [isCollapsed, setIsCollapsed]   = useState(false);
    const [activeHelpKey, setActiveHelpKey] = useState(null);
    const [isSaving, setIsSaving]         = useState(false);
    const [lastSavedAt, setLastSavedAt]   = useState(assessment?.updated_at ?? null);
    const [hasPendingChanges, setHasPendingChanges] = useState(false);
    const [saveFailed, setSaveFailed] = useState(false);
    const helpTriggerRef = useRef(null);

    const criteria = template?.criteria ?? [];
    const totalPoints  = criteria.length;
    const ratedCount   = criteria.filter(c => answers[c.id]?.selected_value).length;
    const allRated     = ratedCount === totalPoints && totalPoints > 0;
    const verdict      = computeVerdict(criteria, answers);
    const summary      = generateSummary(criteria, answers);
    const activePoint  = activeHelpKey !== null ? criteria.find(c => c.id === activeHelpKey) : null;
    const saveUnavailable = isSaving || !hasPendingChanges;

    // Spoken when the verdict first appears, changes, or goes away — not on load, and not on every
    // rating that leaves it as it was.
    useTransitionAnnouncement(verdict?.level ?? 'none', (_previous, next) => (
        next === 'none' || !verdict
            ? null
            : { message: (a11y.gonogo_verdict ?? 'Foreløpig beslutningsgrunnlag: :verdict').replace(':verdict', verdict.label) }
    ));

    function handleRatingClick(criterionId, value) {
        setAnswers(prev => {
            const current = prev[criterionId] ?? { selected_value: null, comment: '' };
            return {
                ...prev,
                [criterionId]: {
                    ...current,
                    selected_value: current.selected_value === value ? null : value,
                },
            };
        });
        setHasPendingChanges(true);
    }

    function handleCommentChange(criterionId, value) {
        setAnswers(prev => ({
            ...prev,
            [criterionId]: { ...(prev[criterionId] ?? { selected_value: null }), comment: value },
        }));
        setHasPendingChanges(true);
    }

    function handleSave() {
        if (!saveUrl || saveUnavailable) return;
        setIsSaving(true);
        setSaveFailed(false);
        router.patch(saveUrl, {
            template_id: template.id,
            answers: criteria.map(c => ({
                criterion_id:   c.id,
                selected_value: answers[c.id]?.selected_value ?? null,
                comment:        answers[c.id]?.comment ?? '',
            })),
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsSaving(false);
                setLastSavedAt(new Date().toISOString());
                setHasPendingChanges(false);
            },
            onError: () => {
                setIsSaving(false);
                setSaveFailed(true);
            },
        });
    }

    function formatSavedAt(iso) {
        if (!iso) return null;
        return new Intl.DateTimeFormat('nb-NO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    }

    return (
        <>
            <section aria-labelledby="go-no-go-assessment-title" className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">

                {/* ── Header ────────────────────────────────────── */}
                <div className="flex items-center justify-between gap-4">
                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <h2 id="go-no-go-assessment-title" className="text-xl font-semibold tracking-tight text-slate-950">Beslutningsvurdering</h2>
                        <span className="shrink-0 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-base font-medium leading-6 text-slate-600">
                            {ratedCount} av {totalPoints} vurdert
                        </span>
                        {isCollapsed && (
                            allRated && verdict ? (
                                <span className={`shrink-0 rounded-full border px-3 py-1.5 text-base font-semibold leading-6 ${verdict.className}`}>
                                    {verdict.label}
                                </span>
                            ) : (
                                <span className="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-base font-medium leading-6 text-slate-600">
                                    Vurdering ikke fullført
                                </span>
                            )
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => setIsCollapsed(v => !v)}
                        aria-expanded={!isCollapsed}
                        aria-controls="go-no-go-assessment-body"
                        className="shrink-0 inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-base font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-700"
                    >
                        {isCollapsed ? <><ChevronDown className="h-3.5 w-3.5" />Vis</> : <><ChevronUp className="h-3.5 w-3.5" />Skjul</>}
                    </button>
                </div>

                {/* ── Collapsed summary ─────────────────────────── */}
                {isCollapsed && (
                    <p className="mt-2 text-base leading-6 text-slate-600">
                        {allRated && summary ? summary : 'Fullfør alle vurderingspunktene for å se foreløpig anbefaling.'}
                    </p>
                )}

                {/* ── Expanded ──────────────────────────────────── */}
                {!isCollapsed && (
                    <div id="go-no-go-assessment-body">
                        <p className="mt-1 text-base leading-6 text-slate-600">
                            Vurder saken på tvers av {totalPoints} dimensjoner som grunnlag for Go/No-go-beslutningen.
                            {template?.name && <span className="ml-1 text-slate-600">· {template.name}</span>}
                        </p>

                        {/* Progress */}
                        <div className="mt-3.5">
                            <div className="mb-1 flex items-center justify-between">
                                <span className="text-base text-slate-600">{ratedCount} av {totalPoints} vurderinger fullført</span>
                                <span className="text-base text-slate-600">{Math.round((ratedCount / Math.max(totalPoints, 1)) * 100)}%</span>
                            </div>
                            <div aria-hidden="true" className="h-1 w-full overflow-hidden rounded-full bg-slate-100">
                                <div
                                    className="h-full rounded-full bg-violet-400 transition-all duration-300"
                                    style={{ width: `${(ratedCount / Math.max(totalPoints, 1)) * 100}%` }}
                                />
                            </div>
                        </div>

                        {/* Verdict or pending notice */}
                        <div className="mt-4">
                            {allRated && verdict ? (
                                <div className={`rounded-2xl border px-4 py-3.5 ${verdict.className}`}>
                                    <div className="flex items-center gap-2 text-base font-semibold">
                                        <span aria-hidden="true" className={`h-2 w-2 shrink-0 rounded-full ${verdict.dot}`} />
                                        <span>Foreløpig beslutningsgrunnlag: {verdict.label}</span>
                                    </div>
                                    {/* Full-strength text: at opacity-75 the verdict's own colour fell below 4.5:1 on its tint. */}
                                    {summary && <p className="mt-1.5 text-base leading-6">{summary}</p>}
                                    <p className="mt-1 text-base leading-6">Beslutningen tas av deg – dette er et strukturert grunnlag.</p>
                                </div>
                            ) : (
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base leading-6 text-slate-600">
                                    Fullfør alle vurderingspunktene for å se foreløpig anbefaling.
                                </div>
                            )}
                        </div>

                        {/* Criteria rows */}
                        <div className="mt-4 space-y-2">
                            {criteria.map((criterion, index) => {
                                const a = answers[criterion.id] ?? { selected_value: null, comment: '' };
                                const titleId = `go-no-go-criterion-${criterion.id}-title`;
                                const descriptionId = `go-no-go-criterion-${criterion.id}-description`;
                                return (
                                    <div key={criterion.id} className="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                                        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span aria-hidden="true" className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">{index + 1}</span>
                                                    <span id={titleId} className="text-base font-semibold text-slate-900">{criterion.title}</span>
                                                    <WeightBadge weight={criterion.weight} isReversed={criterion.is_score_reversed} />
                                                </div>
                                                <p id={descriptionId} className="mt-0.5 text-base leading-6 text-slate-600">{criterion.short_description}</p>
                                            </div>

                                            {/* Not shrink-0: at 320 px the three ratings and «?» are wider than the
                                                card, and a box that cannot shrink cannot wrap — the page scrolled
                                                sideways (1.4.10). */}
                                            <div className="flex min-w-0 max-w-full flex-wrap items-center gap-1">
                                                <div role="group" aria-labelledby={titleId} aria-describedby={criterion.short_description ? descriptionId : undefined} className="flex flex-wrap items-center gap-1">
                                                    {RATING_VALUES.map(rv => {
                                                        const semantic   = ratingSemantic(rv, criterion.is_score_reversed);
                                                        const isSelected = a.selected_value === rv;
                                                        return (
                                                            <button
                                                                key={`${criterion.id}-${rv}`}
                                                                type="button"
                                                                aria-pressed={isSelected}
                                                                onClick={() => handleRatingClick(criterion.id, rv)}
                                                                className={`inline-flex h-9 items-center gap-1 rounded-full border px-3 text-base font-semibold leading-6 transition ${isSelected ? ACTIVE_COLOR[semantic] : INACTIVE_COLOR[semantic]}`}
                                                            >
                                                                {isSelected ? <CheckIcon className="h-4 w-4 shrink-0" /> : null}
                                                                {RATING_LABELS[rv]}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                                <button
                                                    key={`${criterion.id}-help`}
                                                    type="button"
                                                    onClick={(event) => {
                                                        helpTriggerRef.current = event.currentTarget;
                                                        setActiveHelpKey(criterion.id);
                                                    }}
                                                    title="Vurderingshjelp"
                                                    aria-label={(a11y.gonogo_help ?? 'Vurderingshjelp for :criterion').replace(':criterion', criterion.title)}
                                                    className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-base font-bold text-slate-600 transition hover:border-violet-200 hover:text-violet-600"
                                                >
                                                    ?
                                                </button>
                                            </div>
                                        </div>

                                        {/* Note — always rendered to prevent layout shifts */}
                                        <textarea
                                            key={`${criterion.id}-note`}
                                            value={a.comment}
                                            onChange={e => handleCommentChange(criterion.id, e.target.value)}
                                            rows={1}
                                            aria-label={(a11y.gonogo_comment ?? 'Begrunnelse for :criterion (valgfritt)').replace(':criterion', criterion.title)}
                                            placeholder="Begrunnelse (valgfritt)…"
                                            className="mt-2 w-full resize-none rounded-xl border border-field-border bg-white px-3 py-1.5 text-base text-slate-900 placeholder:text-slate-500 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                        />
                                    </div>
                                );
                            })}
                        </div>

                        {/* Save bar */}
                        {saveUrl && (
                            <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-4">
                                    <span id="go-no-go-save-state" className="text-base text-slate-600">
                                        {hasPendingChanges
                                            ? 'Ulagrede endringer'
                                            : lastSavedAt
                                                ? `Sist lagret ${formatSavedAt(lastSavedAt)}`
                                                : 'Ikke lagret ennå'}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={handleSave}
                                        aria-disabled={saveUnavailable || undefined}
                                        aria-describedby={saveFailed ? 'go-no-go-save-state go-no-go-save-error' : 'go-no-go-save-state'}
                                        className={`inline-flex items-center gap-2 rounded-xl px-4 py-2 text-base font-semibold shadow-sm aria-disabled:cursor-default aria-disabled:opacity-40 ${PRIMARY_COLOURS}`}
                                    >
                                        {isSaving ? 'Lagrer…' : 'Lagre vurdering'}
                                    </button>
                                </div>
                                {saveFailed ? (
                                    <p id="go-no-go-save-error" role="alert" className="mt-2 text-base text-rose-700">
                                        {a11y.gonogo_save_failed ?? 'Vurderingen ble ikke lagret. Prøv igjen.'}
                                    </p>
                                ) : null}
                            </div>
                        )}
                    </div>
                )}
            </section>

            {activePoint && (
                <PageHelpPanel
                    id="go-no-go-assessment-help"
                    title={activePoint.title}
                    intro="Procynias anbefalte vurderingsmetodikk"
                    sections={criterionHelpSections(activePoint)}
                    isOpen={activeHelpKey !== null}
                    onClose={() => setActiveHelpKey(null)}
                    returnFocusRef={helpTriggerRef}
                />
            )}
        </>
    );
}
