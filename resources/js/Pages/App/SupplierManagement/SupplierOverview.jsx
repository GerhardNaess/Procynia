import StatusBadge from '../../../Components/App/StatusBadge';
import { LEVEL_TONES, levelLabel } from './controlRequirements';
import { DUE_DILIGENCE_CONCLUSION_TONES, conclusionLabel, nextText } from './dueDiligence';
import { DISPLAY_STATUS_TONES, displayStatusLabel } from './requirementEvaluations';
import { RESULT_TONES, nextReviewText, resultLabel } from './supplierManagement';
import { documentationSummaryText } from './supplierPage';

const CARD = 'min-w-0 rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const LINK = 'inline-flex min-h-11 items-center text-base font-semibold text-violet-700 hover:text-violet-900';

/** «Åpne …»: an in-page link to the section on its tab; the page switches tab and scrolls there. */
function OpenLink({ anchor, children, testId }) {
    return <a href={`#${anchor}`} className={LINK} data-testid={testId}>{children}</a>;
}

/**
 * «Sikkerhet og personvern» (docs/supplier-assurance-v2-plan.md §12, §22.1): the profile facts that
 * make security and privacy requirements apply, and those requirements with their status now. A
 * filtered view of Krav og kvalifikasjoner — not an assessment of its own, and never a score.
 */
export function SecurityPrivacyCard({ summary, tr }) {
    const s = tr.page?.security_privacy ?? {};

    if (! summary) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="supplier-security-privacy-heading" data-testid="supplier-security-privacy">
            <h2 id="supplier-security-privacy-heading" className="text-xl font-semibold text-slate-950">{s.heading ?? 'Sikkerhet og personvern'}</h2>
            <p className="mt-1 text-base text-slate-600">{s.intro ?? 'Hva leveransen gjelder, og status for kravene til sikkerhet og personvern.'}</p>

            <dl className="mt-4 grid min-w-0 gap-3 sm:grid-cols-2" data-testid="security-privacy-facts">
                {summary.facts.map((fact) => (
                    <div key={fact.key} className="min-w-0" data-fact={fact.key}>
                        <dt className="text-base font-semibold text-slate-600">{fact.label}</dt>
                        <dd className={`break-words text-base ${fact.uncertain ? 'text-amber-800' : 'text-slate-900'}`}>{fact.text}</dd>
                    </div>
                ))}
            </dl>

            <h3 className="mt-5 text-lg font-semibold text-slate-950">{s.requirements_heading ?? 'Krav til sikkerhet og personvern'}</h3>
            <p className="mt-1 break-words text-base text-slate-800" data-testid="security-privacy-counts">
                {[(summary.applicableCount === 1 ? (s.applicable_one ?? '1 krav gjelder') : (s.applicable ?? ':count krav gjelder').replace(':count', String(summary.applicableCount))), ...summary.groups.map((group) => group.text)].join(' · ')}
            </p>
            {summary.open.length > 0 ? (
                <ul className="mt-2 space-y-2" data-testid="security-privacy-open">
                    {summary.open.map((row) => (
                        <li key={row.id} className="flex min-w-0 flex-wrap items-center gap-2" data-requirement-id={row.id}>
                            <a href={`#control-requirement-${row.id}`} className="min-w-0 break-words text-base font-semibold text-violet-700 hover:text-violet-900">{row.title}</a>
                            <StatusBadge tone={LEVEL_TONES[row.level] ?? 'slate'}>{levelLabel(row.level, tr)}</StatusBadge>
                            <StatusBadge tone={DISPLAY_STATUS_TONES[row.displayStatus] ?? 'slate'}>{displayStatusLabel(row.displayStatus, tr)}</StatusBadge>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-2 text-base text-slate-800" data-testid="security-privacy-all-documented">{s.all_documented ?? 'Alle kravene er dokumentert.'}</p>
            )}

            <div className="mt-3">
                <OpenLink anchor="supplier-control-heading" testId="security-privacy-open-link">{s.open_link ?? 'Åpne Krav og kvalifikasjoner'}</OpenLink>
            </div>
        </section>
    );
}

/**
 * The short cards on Oversikt for what has its own tab — Dokumentasjon, Leverandørvurdering and
 * Aktsomhet og bærekraft: one or two lines each and «Åpne». The tab holds the list, the history and
 * the actions; the card only says where things stand.
 */
export function SupplierOverviewSummaries({ supplier, documentation, assessments = [], dueDiligence = null, formatDate, tr }) {
    const p = tr.page ?? {};
    const latest = assessments[0] ?? null;
    const latestConclusion = dueDiligence?.history?.[0] ?? null;
    const dueNext = dueDiligence ? nextText(dueDiligence, tr, formatDate) : null;

    return (
        <div className="grid min-w-0 gap-6 md:grid-cols-2" data-testid="supplier-overview-summaries">
            <section className={CARD} aria-labelledby="overview-documents-heading" data-testid="overview-documents">
                <h2 id="overview-documents-heading" className="text-xl font-semibold text-slate-950">{tr.documents?.heading ?? 'Dokumentasjon'}</h2>
                <p className="mt-2 break-words text-base text-slate-800" data-testid="overview-documents-summary">{documentationSummaryText(documentation, tr)}</p>
                <div className="mt-2"><OpenLink anchor="supplier-documents-heading">{p.open_documents ?? 'Åpne Dokumentasjon'}</OpenLink></div>
            </section>

            <section className={CARD} aria-labelledby="overview-assessment-heading" data-testid="overview-assessment">
                <h2 id="overview-assessment-heading" className="text-xl font-semibold text-slate-950">{tr.assessment?.heading ?? 'Leverandørvurdering'}</h2>
                {latest ? (
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <StatusBadge tone={RESULT_TONES[latest.overall_result] ?? 'slate'}>{resultLabel(latest.overall_result, tr)}</StatusBadge>
                        <span className="text-base text-slate-600">{formatDate(latest.assessed_on)}</span>
                    </div>
                ) : (
                    <p className="mt-2 text-base text-slate-800">{p.no_assessment ?? 'Ikke vurdert ennå'}</p>
                )}
                {supplier.next_review_on && <p className="mt-1 break-words text-base text-slate-800">{(p.next_assessment ?? 'Neste vurdering :date').replace(':date', nextReviewText(supplier, tr, formatDate))}</p>}
                <div className="mt-2"><OpenLink anchor="supplier-assessment-heading">{p.open_assessments ?? 'Åpne Vurderinger'}</OpenLink></div>
            </section>

            {dueDiligence && (
                <section className={CARD} aria-labelledby="overview-due-diligence-heading" data-testid="overview-due-diligence">
                    <h2 id="overview-due-diligence-heading" className="text-xl font-semibold text-slate-950">{tr.due_diligence?.heading ?? 'Aktsomhet og bærekraft'}</h2>
                    {latestConclusion ? (
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <StatusBadge tone={DUE_DILIGENCE_CONCLUSION_TONES[latestConclusion.conclusion] ?? 'slate'}>{conclusionLabel(latestConclusion.conclusion, tr)}</StatusBadge>
                            <span className="text-base text-slate-600">{formatDate(latestConclusion.assessed_on)}</span>
                        </div>
                    ) : (
                        <p className="mt-2 text-base text-slate-800">
                            {dueDiligence.relevant
                                ? (p.due_diligence_missing ?? 'Relevant ut fra profilen, men ikke vurdert ennå')
                                : (p.due_diligence_not_relevant ?? 'Ikke påkrevd ut fra profilen')}
                        </p>
                    )}
                    {dueNext && <p className={`mt-1 break-words text-base ${dueDiligence.overdue ? 'font-semibold text-amber-800' : 'text-slate-800'}`}>{dueNext}</p>}
                    <div className="mt-2"><OpenLink anchor="supplier-due-diligence-heading">{p.open_due_diligence ?? 'Åpne Aktsomhet'}</OpenLink></div>
                </section>
            )}
        </div>
    );
}
