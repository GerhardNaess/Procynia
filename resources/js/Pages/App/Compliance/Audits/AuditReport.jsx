import StatusBadge from '../../../../Components/App/StatusBadge';
import { FINDING_TYPE_TONES, findingTypeLabel, requirementLabel, scopeCoverage } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const SUBHEADING = 'text-lg font-semibold text-slate-950';
const EMPTY = 'mt-2 text-base text-slate-700';

function RequirementList({ requirements, testId }) {
    return (
        <ul className="mt-2 space-y-1" data-testid={testId}>
            {requirements.map((requirement) => (
                <li key={requirement.id} className="break-words text-base text-slate-800">{requirementLabel(requirement)}</li>
            ))}
        </ul>
    );
}

/**
 * Rapport og oppfølging: everything the auditor needs before Fullfør, and what the audit says once
 * it is completed — purpose and omfang, the kontrollhandlinger, which requirements were examined
 * and which have no documented examination, the findings and which of them still wait for
 * Avvik og forbedringer, and the conclusion. Read only from what the page already has; nothing here
 * is stored, and nothing says a requirement is met.
 *
 * `children` is where the Fullfør form goes while it is open.
 */
export default function AuditReport({ audit, checks = [], requirements = [], findings = [], ta, children = null }) {
    const { examined, unexamined } = scopeCoverage(requirements);
    const awaiting = findings.filter((finding) => ! finding.handed_off);
    const handedOff = findings.length - awaiting.length;

    return (
        <div className="space-y-6">
            <section className={CARD} aria-labelledby="compliance-audit-report-heading" data-testid="compliance-audit-report">
                <h2 id="compliance-audit-report-heading" className="text-xl font-semibold text-slate-950">{ta.report_heading ?? 'Rapport'}</h2>
                <p className="mt-1 text-base text-slate-600">{ta.report_intro ?? 'Samlet oversikt over hva revisjonen har undersøkt og funnet.'}</p>

                <dl className="mt-4 grid gap-4 md:grid-cols-2">
                    <div className="min-w-0">
                        <dt className="text-base font-semibold text-slate-600">{ta.field_purpose ?? 'Formål'}</dt>
                        <dd className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{audit.purpose ?? <span className="text-slate-600">{ta.no_purpose ?? 'Ikke angitt'}</span>}</dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-base font-semibold text-slate-600">{ta.field_scope ?? 'Omfang'}</dt>
                        <dd className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{audit.scope_description ?? <span className="text-slate-600">{ta.no_scope_text ?? 'Ingen beskrivelse. Omfanget er kravene og prosessene nedenfor.'}</span>}</dd>
                    </div>
                </dl>

                <div className="mt-6 grid gap-6 lg:grid-cols-2">
                    <div className="min-w-0">
                        <h3 className={SUBHEADING}>{ta.report_checks ?? 'Dokumenterte kontrollhandlinger'} ({checks.length})</h3>
                        {checks.length === 0 ? (
                            <p className={EMPTY}>{ta.report_checks_none ?? 'Ingen kontrollhandlinger er dokumentert.'}</p>
                        ) : (
                            <ul className="mt-2 space-y-1" data-testid="compliance-audit-report-checks">
                                {checks.map((check) => (
                                    <li key={check.id} className="break-words text-base text-slate-800">{check.title}</li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="min-w-0">
                        <h3 className={SUBHEADING}>{ta.report_examined ?? 'Krav som er undersøkt'} ({examined.length})</h3>
                        {examined.length === 0 ? (
                            <p className={EMPTY}>{ta.report_examined_none ?? 'Ingen krav i omfanget er knyttet til en kontrollhandling.'}</p>
                        ) : (
                            <RequirementList requirements={examined} testId="compliance-audit-report-examined" />
                        )}
                    </div>
                </div>

                {requirements.length > 0 && (
                    <div className={`mt-6 rounded-2xl border px-4 py-3 ${unexamined.length > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50'}`} data-testid="compliance-audit-report-unexamined">
                        <h3 className={SUBHEADING}>{ta.report_unexamined ?? 'Krav i omfanget uten dokumentert undersøkelse'} ({unexamined.length})</h3>
                        {unexamined.length === 0 ? (
                            <p className={EMPTY}>{ta.report_unexamined_none ?? 'Alle krav i omfanget har minst én dokumentert undersøkelse.'}</p>
                        ) : (
                            <>
                                <p className="mt-1 text-base text-amber-900">{ta.report_unexamined_hint ?? 'Ta stilling til disse før du fullfører.'}</p>
                                <RequirementList requirements={unexamined} testId="compliance-audit-report-unexamined-list" />
                            </>
                        )}
                    </div>
                )}

                <div className="mt-6 grid gap-6 lg:grid-cols-2">
                    <div className="min-w-0">
                        <h3 className={SUBHEADING}>{ta.report_findings ?? 'Registrerte funn'} ({findings.length})</h3>
                        {findings.length === 0 ? (
                            <p className={EMPTY}>{ta.report_findings_none ?? 'Ingen funn er registrert.'}</p>
                        ) : (
                            <ul className="mt-2 space-y-2" data-testid="compliance-audit-report-findings">
                                {findings.map((finding) => (
                                    <li key={finding.id} className="flex flex-wrap items-center gap-2 text-base text-slate-800">
                                        <StatusBadge tone={FINDING_TYPE_TONES[finding.finding_type] ?? 'slate'}>{findingTypeLabel(finding.finding_type, ta)}</StatusBadge>
                                        <span className="min-w-0 break-words">{finding.title}</span>
                                        {finding.handed_off && <StatusBadge tone="emerald">{ta.findings?.handed_off ?? 'Overført'}</StatusBadge>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="min-w-0">
                        <h3 className={SUBHEADING}>{ta.report_follow_up ?? 'Funn som ikke er fulgt opp i Avvik og forbedringer'} ({awaiting.length})</h3>
                        {findings.length > 0 && (
                            <p className="mt-1 text-base text-slate-600">{(ta.report_handed_off ?? ':count av :total funn er overført til Avvik og forbedringer.').replace(':count', String(handedOff)).replace(':total', String(findings.length))}</p>
                        )}
                        {awaiting.length === 0 ? (
                            <p className={EMPTY}>{ta.report_follow_up_none ?? 'Ingen funn venter på oppfølging.'}</p>
                        ) : (
                            <ul className="mt-2 space-y-1" data-testid="compliance-audit-report-follow-up">
                                {awaiting.map((finding) => (
                                    <li key={finding.id} className="break-words text-base text-slate-800">
                                        {findingTypeLabel(finding.finding_type, ta)}: {finding.title}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                <p className="mt-6 text-base text-slate-600">{ta.report_conformity_note ?? 'At et krav er undersøkt, betyr ikke at det er oppfylt.'}</p>
            </section>

            {children}

            <section className={CARD} aria-labelledby="compliance-audit-conclusion-heading">
                <h2 id="compliance-audit-conclusion-heading" className="text-xl font-semibold text-slate-950">{ta.conclusion_heading ?? 'Konklusjon'}</h2>
                {audit.conclusion ? (
                    <p className="mt-3 whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="compliance-audit-conclusion">{audit.conclusion}</p>
                ) : (
                    <p className="mt-3 text-base text-slate-700" data-testid="compliance-audit-conclusion">
                        {audit.status === 'planned' ? (ta.conclusion_planned ?? 'Konklusjonen skrives når revisjonen er startet.') : (ta.conclusion_empty ?? 'Ingen konklusjon er skrevet ennå.')}
                    </p>
                )}
            </section>
        </div>
    );
}
