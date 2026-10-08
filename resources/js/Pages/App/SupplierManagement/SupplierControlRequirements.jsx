import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DISCLOSURE_INLINE, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import ControlRequirementForm from './ControlRequirementForm';
import { LEVEL_TONES, anchorLabel, groupByTheme, levelLabel, rowActions } from './controlRequirements';
import { DISPLAY_STATUS_TONES, displayStatusLabel, lastControlText } from './requirementEvaluations';
import { nextControlText } from './assuranceFollowUp';
import { EvaluationForm, EvaluationHistory } from './SupplierRequirementEvaluation';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';
const INPUT = 'mt-1 min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

/**
 * Legg til krav / Gjelder ikke denne leverandøren / Tilbake til automatisk vurdering: one begrunnelse,
 * one new row in the history. For include, the person also picks the requirement among those that do
 * not apply, each with when it would.
 */
function OverrideForm({ supplierId, action, requirement = null, includable = [], onDone, tr }) {
    const c = tr.control ?? {};
    const form = useForm({ requirement_id: requirement ? String(requirement.id) : '', action, reason: '' });
    const texts = {
        include: [c.include_heading, c.include_intro, c.include_submit],
        exclude: [c.exclude_heading, c.exclude_intro, c.exclude_submit],
        clear: [c.clear_heading, c.clear_intro, c.clear_submit],
    }[action];

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/requirement-overrides`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4" data-testid={`override-form-${action}`}>
            <h4 className="text-lg font-semibold text-slate-950">{texts[0]}</h4>
            {requirement && <p className="break-words text-base font-semibold text-slate-900">{requirement.title}</p>}
            <p className={HINT}>{texts[1]}</p>

            {action === 'include' && (
                <fieldset>
                    <legend className="text-base font-semibold text-slate-900">{c.include_choose ?? 'Velg krav'}<RequiredMark /></legend>
                    <div className="mt-1 space-y-1">
                        {includable.map((option) => (
                            <label key={option.id} className="flex min-h-10 items-start gap-2 py-1 text-base text-slate-900">
                                <input
                                    type="radio"
                                    name={`override-include-${supplierId}`}
                                    value={String(option.id)}
                                    checked={form.data.requirement_id === String(option.id)}
                                    onChange={() => form.setData('requirement_id', String(option.id))}
                                    className="mt-1 h-5 w-5 shrink-0"
                                />
                                <span className="min-w-0 break-words">
                                    <span className="font-semibold">{option.title}</span> · {levelLabel(option.level, tr)}
                                    <span className="block text-slate-600">{option.rule_text}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}
            {form.errors.requirement_id && <p className={ERROR}>{form.errors.requirement_id}</p>}

            <div>
                <label htmlFor={`override-reason-${action}`} className="block text-base font-semibold text-slate-900">{c.reason_label ?? 'Begrunnelse'}<RequiredMark /></label>
                <p className={HINT}>{c.reason_hint ?? 'Begrunnelsen lagres i historikken og kan ikke endres.'}</p>
                <textarea id={`override-reason-${action}`} rows={3} required aria-required value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className={INPUT} />
                {form.errors.reason && <p className={ERROR}>{form.errors.reason}</p>}
            </div>

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing || ! form.data.requirement_id} className={PRIMARY_ACTION}>{texts[2]}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

/** The reason line: «Gjelder fordi …», with the begrunnelse and «Også fordi …» under it. */
function Reason({ reason, tr }) {
    const c = tr.control ?? {};

    if (! reason) {
        return null;
    }

    return (
        <div className="mt-2 space-y-1" data-testid="requirement-reason">
            <p className="break-words text-base font-semibold text-slate-900">{reason.text}</p>
            {reason.note && <p className="break-words text-base text-slate-700">{c.reason_label ?? 'Begrunnelse'}: {reason.note}</p>}
            {(reason.also ?? []).length > 0 && (
                <p className="break-words text-base text-slate-700">{c.also_because ?? 'Også fordi'} {reason.also.join('; ')}</p>
            )}
        </div>
    );
}

/** Grunnlag and, for someone who may see it, Forankret i — never the compliance status. */
function Basis({ row, tr }) {
    const c = tr.control ?? {};

    return (
        <>
            {row.basis_text && <p className="mt-1 break-words text-base text-slate-700">{c.basis ?? 'Grunnlag'}: {row.basis_text}</p>}
            {row.anchor && (
                <p className="mt-1 break-words text-base text-slate-700" data-testid="requirement-anchor">
                    {c.anchor ?? 'Forankret i'}:{' '}
                    <a href={row.anchor.url} className="font-semibold text-violet-700 hover:text-violet-900">{anchorLabel(row.anchor)}</a>
                    {row.anchor.retired && ` (${c.anchor_retired ?? 'utgått i Etterlevelse og revisjon'})`}
                </p>
            )}
        </>
    );
}

/**
 * Krav og kvalifikasjoner on the supplier page (docs/supplier-assurance-v2-plan.md §5.2, §22.1):
 * the control requirements that apply, grouped by theme, each with exactly one «Gjelder fordi …».
 * Computed by the server on every read — change the profile and this changes.
 *
 * Each row shows its visningsstatus as the server computed it, when it was last controlled («Ikke
 * vurdert» until then), «Kontroller krav» and its controls, newest first, with the documentation as
 * it was at each control. Controls and overrides are offered only when the server says the person
 * may (supplier.assure, supplier not ended); «Gjelder ikke denne leverandøren» is never offered for a mandatory requirement. The
 * requirements that do not apply are listed only for someone who can include them; the excluded ones
 * and the history are folded away.
 */
export default function SupplierControlRequirements({ supplierId, data, onFollowUp = null, locale = 'no', tr }) {
    const c = tr.control ?? {};
    // One open form at a time: { kind: 'evaluate' | 'include' | 'exclude' | 'clear' | 'own', requirement }.
    const [panel, setPanel] = useState(null);
    const close = () => setPanel(null);
    const canOverride = data.permissions?.can_override ?? false;
    const groups = groupByTheme(data.applicable, tr);

    const panelFor = (row) => panel?.requirement?.id === row.id && (panel.kind === 'evaluate'
        ? <EvaluationForm key={`evaluate-${row.id}`} supplierId={supplierId} row={row} options={data.evaluation_form ?? {}} onDone={close} locale={locale} tr={tr} />
        : <OverrideForm key={`${panel.kind}-${row.id}`} supplierId={supplierId} action={panel.kind} requirement={row} onDone={close} tr={tr} />
    );

    return (
        <section className={CARD} aria-labelledby="supplier-control-heading" data-testid="supplier-control-requirements">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 id="supplier-control-heading" className="text-xl font-semibold text-slate-950">{c.heading ?? 'Krav og kvalifikasjoner'}</h2>
                <Link href="/app/supplier-management/control-requirements" className="text-base font-semibold text-violet-700 hover:text-violet-900">{c.go_to_catalogue ?? 'Se alle kontrollkrav'}</Link>
            </div>
            <p className={`mt-2 ${HINT}`}>{c.intro}</p>

            {groups.length === 0 ? (
                <p className="mt-4 text-base text-slate-700" data-testid="control-none">{c.none_applicable ?? 'Ingen kontrollkrav gjelder denne leverandøren nå.'}</p>
            ) : groups.map((group) => (
                <div key={group.theme} className="mt-5">
                    <h3 className="text-lg font-semibold text-slate-950">{group.label}</h3>
                    <ul className="mt-2 space-y-3">
                        {group.rows.map((row) => {
                            const actions = rowActions(row);
                            const lastControl = lastControlText(row.current, tr, locale);
                            const nextControl = nextControlText(row, tr, (date) => formatLongDate(date, locale));

                            return (
                                <li key={row.id} id={`control-requirement-${row.id}`} className="min-w-0 scroll-mt-4 rounded-xl border border-slate-200 p-4" data-testid="control-requirement-row">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="min-w-0 break-words text-base font-semibold text-slate-950">{row.title}</span>
                                        <StatusBadge tone={LEVEL_TONES[row.level] ?? 'slate'}>{levelLabel(row.level, tr)}</StatusBadge>
                                        <span data-testid="requirement-display-status">
                                            <StatusBadge tone={DISPLAY_STATUS_TONES[row.display_status] ?? 'slate'}>{displayStatusLabel(row.display_status, tr)}</StatusBadge>
                                        </span>
                                    </div>
                                    {lastControl && <p className="mt-1 text-base text-slate-600" data-testid="requirement-last-control">{lastControl}</p>}
                                    {nextControl && (
                                        <p className={`text-base ${row.follow_up?.control_overdue ? 'font-semibold text-amber-800' : 'text-slate-600'}`} data-testid="requirement-next-control">{nextControl}</p>
                                    )}
                                    {row.current?.accepted_until && (
                                        <p className="text-base text-slate-600">{(c.accepted_until ?? 'Akseptert til :date').replace(':date', formatLongDate(row.current.accepted_until, locale))}</p>
                                    )}
                                    <Reason reason={row.reason} tr={tr} />
                                    {row.exclusion_ignored && <p className="mt-1 text-base text-amber-800">{c.exclusion_ignored ?? 'Utelukkelse gjelder ikke obligatoriske krav.'}</p>}
                                    {row.description && <p className="mt-2 whitespace-pre-line break-words text-base text-slate-700">{row.description}</p>}
                                    <Basis row={row} tr={tr} />

                                    {panel === null && (row.can_evaluate || actions.exclude || actions.clear) && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {row.can_evaluate && <button type="button" onClick={() => setPanel({ kind: 'evaluate', requirement: row })} className={PRIMARY_ACTION}>{c.evaluate ?? 'Kontroller krav'}</button>}
                                            {row.can_follow_up && onFollowUp && (
                                                <button type="button" onClick={() => onFollowUp({ ...row.current, requirement_title: row.title })} className={SECONDARY_ACTION} data-testid="requirement-follow-up">
                                                    {c.follow_up ?? 'Følg opp i Avvik og forbedringer'}
                                                </button>
                                            )}
                                            {actions.exclude && <button type="button" onClick={() => setPanel({ kind: 'exclude', requirement: row })} className={SECONDARY_ACTION}>{c.exclude ?? 'Gjelder ikke denne leverandøren'}</button>}
                                            {actions.clear && <button type="button" onClick={() => setPanel({ kind: 'clear', requirement: row })} className={SECONDARY_ACTION}>{c.clear ?? 'Tilbake til automatisk vurdering'}</button>}
                                        </div>
                                    )}
                                    {panelFor(row)}
                                    <EvaluationHistory evaluations={row.evaluations ?? []} locale={locale} tr={tr} />
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}

            {canOverride && panel === null && (
                <div className="mt-5 flex flex-wrap gap-2">
                    {data.includable.length > 0 && <button type="button" onClick={() => setPanel({ kind: 'include' })} className={PRIMARY_ACTION}>{c.include ?? 'Legg til krav'}</button>}
                    <button type="button" onClick={() => setPanel({ kind: 'own' })} className={SECONDARY_ACTION}>{c.add_for_supplier ?? 'Nytt krav for denne leverandøren'}</button>
                </div>
            )}
            {panel?.kind === 'include' && <OverrideForm supplierId={supplierId} action="include" includable={data.includable} onDone={close} tr={tr} />}
            {panel?.kind === 'own' && (
                <div className="mt-4">
                    <h3 className="text-lg font-semibold text-slate-950">{c.add_for_supplier_heading ?? 'Nytt krav for denne leverandøren'}</h3>
                    <p className={HINT}>{c.add_for_supplier_intro}</p>
                    <ControlRequirementForm supplierId={supplierId} options={data.form ?? {}} onDone={close} tr={tr} />
                </div>
            )}

            {data.excluded.length > 0 && (
                <details className="mt-6 border-t border-slate-100 pt-4" data-testid="control-excluded">
                    <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>
                        {c.excluded_heading ?? 'Krav som ikke gjelder denne leverandøren'} ({data.excluded.length})
                    </summary>
                    <ul className="mt-3 space-y-3">
                        {data.excluded.map((row) => (
                            <li key={row.id} className="min-w-0 rounded-xl border border-slate-200 p-4" data-testid="control-excluded-row">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="min-w-0 break-words text-base font-semibold text-slate-950">{row.title}</span>
                                    <StatusBadge tone={LEVEL_TONES[row.level] ?? 'slate'}>{levelLabel(row.level, tr)}</StatusBadge>
                                </div>
                                <Reason reason={row.reason} tr={tr} />
                                {panel === null && row.can_clear && (
                                    <button type="button" onClick={() => setPanel({ kind: 'clear', requirement: row })} className={`mt-3 ${SECONDARY_ACTION}`}>{c.clear ?? 'Tilbake til automatisk vurdering'}</button>
                                )}
                                {panelFor(row)}
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {(data.earlier_evaluations ?? []).length > 0 && (
                <details className="mt-6 border-t border-slate-100 pt-4" data-testid="control-earlier-evaluations">
                    <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>
                        {c.evaluations?.earlier_heading ?? 'Kontroller av krav som ikke gjelder nå'} ({data.earlier_evaluations.length})
                    </summary>
                    <p className={`mt-2 ${HINT}`}>{c.evaluations?.earlier_intro}</p>
                    <ul className="mt-3 space-y-3">
                        {data.earlier_evaluations.map((group) => (
                            <li key={group.requirement_id} className="min-w-0 rounded-xl border border-slate-200 p-4">
                                <p className="break-words text-base font-semibold text-slate-950">{group.title}</p>
                                <EvaluationHistory evaluations={group.evaluations} locale={locale} tr={tr} testId="earlier-evaluation-history" />
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {data.history.length > 0 && (
                <details className="mt-6 border-t border-slate-100 pt-4" data-testid="control-history">
                    <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>
                        {c.history_heading ?? 'Historikk for kravene'} ({data.history.length})
                    </summary>
                    <ol className="mt-3 space-y-3">
                        {data.history.map((entry) => (
                            <li key={entry.id} className="min-w-0 border-l-2 border-slate-200 pl-3" data-testid="control-history-entry">
                                <p className="text-base text-slate-600">{formatLongDate(entry.created_at, locale)} – {entry.created_by_name ?? tr.unknown_user ?? 'en tidligere bruker'}</p>
                                <p className="break-words text-base font-semibold text-slate-900">{entry.action_text}: {entry.title}</p>
                                <p className="break-words text-base text-slate-700">{c.reason_label ?? 'Begrunnelse'}: {entry.reason}</p>
                            </li>
                        ))}
                    </ol>
                </details>
            )}

            {! canOverride && data.permissions?.ended && <p className={`mt-4 ${HINT}`}>{c.reopen_to_change}</p>}
        </section>
    );
}
