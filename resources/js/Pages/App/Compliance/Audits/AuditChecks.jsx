import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { formatDay } from '../../Improvements/improvementStatus';
import RequiredMark from '../../Risk/RequiredMark';
import { FINDING_TYPE_TONES, checkFormData, countText, findingTypeLabel, requirementLabel } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';
const TERM = 'text-base font-semibold text-slate-600';

/**
 * Hva skal kontrolleres: the requirements in scope, each with its origin and how many
 * kontrollhandlinger and findings name it — or that nothing documents an examination of it yet.
 * Examined is never shown as met.
 */
export function AuditCoverage({ requirements = [], ta }) {
    return (
        <section className={CARD} aria-labelledby="compliance-audit-coverage-heading" data-testid="compliance-audit-coverage">
            <h2 id="compliance-audit-coverage-heading" className="text-xl font-semibold text-slate-950">{ta.coverage_heading ?? 'Hva skal kontrolleres'}</h2>
            <p className="mt-1 text-base text-slate-600">{ta.coverage_intro ?? 'Kravene i omfanget og om det er dokumentert en undersøkelse for hvert av dem.'}</p>
            {requirements.length === 0 ? (
                <p className="mt-4 text-base text-slate-700">{ta.coverage_empty ?? 'Ingen krav i omfanget. Kontrollhandlinger kan likevel registreres uten kravkobling.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {requirements.map((requirement) => (
                        <li key={requirement.id} className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid="compliance-audit-coverage-row">
                            <Link href={requirement.url} className="min-w-0 break-words text-base font-semibold text-violet-700 hover:text-violet-900">{requirementLabel(requirement)}</Link>
                            <span className="flex flex-wrap items-center gap-2">
                                {(requirement.check_count ?? 0) > 0 ? (
                                    <StatusBadge tone="blue">{countText(requirement.check_count, ta.coverage_checks_one ?? '1 kontrollhandling', ta.coverage_checks_many ?? ':count kontrollhandlinger')}</StatusBadge>
                                ) : (
                                    <StatusBadge tone="amber">{ta.coverage_none ?? 'Ingen dokumentert undersøkelse'}</StatusBadge>
                                )}
                                {(requirement.finding_count ?? 0) > 0 && (
                                    <StatusBadge tone="slate">{countText(requirement.finding_count, ta.coverage_findings_one ?? '1 funn', ta.coverage_findings_many ?? ':count funn')}</StatusBadge>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Ny kontrollhandling and Rediger: what was examined, what was actually seen, the evidence, and the
 * requirements in scope it was relevant for — any number, or none. No result field: being examined
 * is not being met.
 */
function CheckForm({ auditId, check = null, scopeRequirements, onDone, ta }) {
    const tc = ta.checks ?? {};
    const form = useForm(checkFormData(check));
    const idPrefix = check ? `compliance-check-${check.id}` : 'compliance-check-new';
    const selected = form.data.requirement_ids;
    const toggle = (id) => form.setData('requirement_ids', selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } };

        if (check) {
            form.patch(`/app/compliance/audits/${auditId}/checks/${check.id}`, done);
        } else {
            form.post(`/app/compliance/audits/${auditId}/checks`, done);
        }
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-4 rounded-2xl border border-slate-200 p-4" data-testid="compliance-check-form">
            <h3 className="text-lg font-semibold text-slate-950">{check ? (tc.edit_heading ?? 'Rediger kontrollhandling') : (tc.create_heading ?? 'Ny kontrollhandling')}</h3>

            <div>
                <label htmlFor={`${idPrefix}-title`} className={LABEL}>{tc.field_title ?? 'Kontrollhandling'}<RequiredMark /></label>
                <p id={`${idPrefix}-title-hint`} className={HINT}>{tc.field_title_hint ?? 'Kort om hva som ble undersøkt.'}</p>
                <input
                    id={`${idPrefix}-title`}
                    type="text"
                    required
                    aria-required="true"
                    aria-describedby={`${idPrefix}-title-hint`}
                    maxLength={255}
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor={`${idPrefix}-observation`} className={LABEL}>{tc.field_observation ?? 'Observasjon'}<RequiredMark /></label>
                <p id={`${idPrefix}-observation-hint`} className={HINT}>{tc.field_observation_hint ?? 'Hva du faktisk undersøkte og observerte.'}</p>
                <textarea
                    id={`${idPrefix}-observation`}
                    rows={4}
                    required
                    aria-required="true"
                    aria-describedby={`${idPrefix}-observation-hint`}
                    value={form.data.observation}
                    onChange={(event) => form.setData('observation', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.observation && <p className={ERROR}>{form.errors.observation}</p>}
            </div>

            <div>
                <label htmlFor={`${idPrefix}-evidence`} className={LABEL}>{tc.field_evidence ?? 'Dokumentasjon'}</label>
                <p id={`${idPrefix}-evidence-hint`} className={HINT}>{tc.field_evidence_hint ?? 'Valgfritt. Henvisninger til dokumenter, Wiki-artikler eller andre objektive bevis.'}</p>
                <textarea
                    id={`${idPrefix}-evidence`}
                    rows={2}
                    aria-describedby={`${idPrefix}-evidence-hint`}
                    value={form.data.evidence}
                    onChange={(event) => form.setData('evidence', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.evidence && <p className={ERROR}>{form.errors.evidence}</p>}
            </div>

            <fieldset>
                <legend className={LABEL}>{tc.field_requirements ?? 'Relevante krav'}</legend>
                <p className={HINT}>{tc.field_requirements_hint ?? 'Valgfritt, men anbefalt. Én kontrollhandling kan gjelde flere krav i omfanget.'}</p>
                {scopeRequirements.length === 0 ? (
                    <p className="mt-2 text-base text-slate-700">{tc.requirements_none_in_scope ?? 'Ingen krav i omfanget ennå. Kontrollhandlingen kan registreres uten kravkobling.'}</p>
                ) : (
                    <ul className="mt-2 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
                        {scopeRequirements.map((requirement) => (
                            <li key={requirement.id}>
                                <label className="flex min-h-10 items-start gap-3 px-3 py-2 text-base text-slate-800">
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(requirement.id)}
                                        onChange={() => toggle(requirement.id)}
                                        className="mt-1 h-5 w-5 shrink-0 rounded border-slate-300"
                                    />
                                    <span className="min-w-0 break-words font-semibold">{requirementLabel(requirement)}</span>
                                </label>
                            </li>
                        ))}
                    </ul>
                )}
                {form.errors.requirement_ids && <p className={ERROR}>{form.errors.requirement_ids}</p>}
            </fieldset>

            {form.errors.check && <p className={ERROR}>{form.errors.check}</p>}

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={() => { form.reset(); form.clearErrors(); onDone(); }} className={SECONDARY_ACTION}>{tc.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tc.saving ?? 'Lagrer...') : (tc.save ?? 'Lagre kontrollhandling')}
                </button>
            </div>
        </form>
    );
}

/**
 * One kontrollhandling: what was examined and seen, the evidence, the requirements with their
 * origin, and the findings that came from it. «Registrer funn» opens Nytt funn with this check —
 * and its requirement, when it names exactly one — already chosen.
 */
function CheckCard({ auditId, check, scopeRequirements, canRecordFindings, onNewFinding, panel, setPanel, ta }) {
    const tc = ta.checks ?? {};
    const perms = check.permissions ?? {};
    const editing = panel === `edit-${check.id}`;

    const destroy = () => {
        if (! window.confirm(tc.delete_confirm ?? 'Slette kontrollhandlingen? Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/compliance/audits/${auditId}/checks/${check.id}`, { preserveScroll: true });
    };

    if (editing) {
        return (
            <li id={`check-${check.id}`} className="py-4" data-testid="compliance-check">
                <CheckForm auditId={auditId} check={check} scopeRequirements={scopeRequirements} onDone={() => setPanel(null)} ta={ta} />
            </li>
        );
    }

    return (
        <li id={`check-${check.id}`} className="space-y-3 py-4" data-testid="compliance-check">
            <h3 className="break-words text-lg font-semibold text-slate-950">{check.title}</h3>
            <p className="whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="compliance-check-observation">{check.observation}</p>

            <dl className="grid gap-3 sm:grid-cols-2">
                <div className="min-w-0">
                    <dt className={TERM}>{tc.field_evidence ?? 'Dokumentasjon'}</dt>
                    <dd className="mt-1 whitespace-pre-line break-words text-base text-slate-800" data-testid="compliance-check-evidence">{check.evidence ?? '—'}</dd>
                </div>
                <div className="min-w-0">
                    <dt className={TERM}>{tc.field_requirements ?? 'Relevante krav'}</dt>
                    <dd className="mt-1 text-base" data-testid="compliance-check-requirements">
                        {check.requirements.length === 0 ? (
                            <span className="text-slate-600">{tc.no_requirements ?? 'Ikke knyttet til krav'}</span>
                        ) : (
                            <ul className="space-y-1">
                                {check.requirements.map((requirement) => (
                                    <li key={requirement.id} className="break-words">
                                        <Link href={requirement.url} className="font-semibold text-violet-700 hover:text-violet-900">{requirementLabel(requirement)}</Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </dd>
                </div>
            </dl>

            <div>
                <p className={TERM}>{tc.findings_label ?? 'Funn fra kontrollhandlingen'}</p>
                {check.findings.length === 0 ? (
                    <p className="mt-1 text-base text-slate-600" data-testid="compliance-check-no-findings">{tc.no_findings ?? 'Ingen funn registrert'}</p>
                ) : (
                    <ul className="mt-1 space-y-1" data-testid="compliance-check-findings">
                        {check.findings.map((finding) => (
                            <li key={finding.id} className="flex flex-wrap items-center gap-2 text-base">
                                <StatusBadge tone={FINDING_TYPE_TONES[finding.finding_type] ?? 'slate'}>{findingTypeLabel(finding.finding_type, ta)}</StatusBadge>
                                <a href={`#finding-${finding.id}`} className="break-words font-semibold text-violet-700 hover:text-violet-900">{finding.title}</a>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {check.created_at && (
                <p className="text-base text-slate-600">
                    {(tc.recorded_meta ?? 'Registrert :date av :name').replace(':date', formatDay(check.created_at.slice(0, 10))).replace(':name', check.created_by_name ?? ta.unknown_user ?? 'en tidligere bruker')}
                </p>
            )}

            {panel === null && (canRecordFindings || perms.can_edit || perms.can_delete) && (
                <div className="flex flex-wrap gap-2" data-testid="compliance-check-actions">
                    {canRecordFindings && (
                        <button type="button" onClick={() => onNewFinding(check)} className={SECONDARY_ACTION}>{tc.new_finding ?? 'Registrer funn'}</button>
                    )}
                    {perms.can_edit && (
                        <button type="button" onClick={() => setPanel(`edit-${check.id}`)} className={SECONDARY_ACTION}>{tc.edit ?? 'Rediger'}</button>
                    )}
                    {perms.can_delete && (
                        <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{tc.delete ?? 'Slett'}</button>
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * Kontrollhandlinger: what the audit examined, in the order it was recorded — the record that the
 * audit was carried out, also when it found nothing. Recorded while the audit is in progress and
 * the server allows it; afterwards read-only. One form open at a time.
 */
export default function AuditChecks({ audit, checks = [], scopeRequirements = [], canRecord = false, canRecordFindings = false, onNewFinding, ta }) {
    const tc = ta.checks ?? {};
    // 'create', 'edit-<id>' or none.
    const [panel, setPanel] = useState(null);
    const locked = audit.status === 'completed' ? tc.locked_completed : audit.status === 'cancelled' ? tc.locked_cancelled : null;

    return (
        <section className={CARD} aria-labelledby="compliance-audit-checks-heading" data-testid="compliance-audit-checks">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="compliance-audit-checks-heading" className="text-xl font-semibold text-slate-950">{tc.heading ?? 'Kontrollhandlinger'}</h2>
                    <p className="mt-1 text-base text-slate-600">{tc.intro ?? 'Det revisor har undersøkt, hvordan og på hvilket grunnlag.'}</p>
                </div>
                {canRecord && panel === null && (
                    <button type="button" onClick={() => setPanel('create')} className={PRIMARY_ACTION}>{tc.create ?? 'Ny kontrollhandling'}</button>
                )}
            </div>

            {locked && <p className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="compliance-checks-locked">{locked}</p>}

            {canRecord && panel === 'create' && (
                <CheckForm auditId={audit.id} scopeRequirements={scopeRequirements} onDone={() => setPanel(null)} ta={ta} />
            )}

            {checks.length === 0 ? (
                <p className="mt-4 text-base text-slate-700" data-testid="compliance-checks-empty">
                    {audit.status === 'planned' ? (tc.empty_planned ?? 'Kontrollhandlinger registreres når revisjonen er startet.') : (tc.empty ?? 'Ingen kontrollhandlinger er registrert.')}
                </p>
            ) : (
                <ul className="mt-2 divide-y divide-slate-100">
                    {checks.map((check) => (
                        <CheckCard
                            key={check.id}
                            auditId={audit.id}
                            check={check}
                            scopeRequirements={scopeRequirements}
                            canRecordFindings={canRecordFindings}
                            onNewFinding={onNewFinding}
                            panel={panel}
                            setPanel={setPanel}
                            ta={ta}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}
