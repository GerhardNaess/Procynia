import { useState } from 'react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { LEVEL_TONES, levelLabel } from './controlRequirements';
import { DISPLAY_STATUS_TONES, displayStatusLabel } from './requirementEvaluations';
import { SupplierAttentionFindings } from './SupplierAttention';
import SupplierFollowUpPlan from './SupplierFollowUpPlan';
import { followUpEntryDate, followUpEntryText } from './assuranceFollowUp';
import { categoryLabel } from './supplierManagement';

const CARD = 'min-w-0 rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

function Fact({ term, children, wide = false, testId }) {
    return (
        <div className={`min-w-0 ${wide ? 'sm:col-span-2' : ''}`} data-testid={testId}>
            <dt className={TERM}>{term}</dt>
            <dd className={VALUE}>{children}</dd>
        </div>
    );
}

/**
 * «Sikkerhet og personvern» (docs/supplier-assurance-v2-plan.md §12, §22.1): the profile facts that
 * make security and privacy requirements apply, and those requirements with their status now. A
 * filtered view of the requirements, shown on Krav og kvalifikasjoner beside them — not an
 * assessment of its own, and never a score.
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

        </section>
    );
}

/**
 * «Trenger oppfølging» at the top of Oversikt: each finding SupplierAttentionService made, in one
 * sentence with the link to where it is done — the count, not the list of requirements. With none, a
 * short neutral line that does not read as an approval.
 */
export function OverviewAttention({ findings = [], formatDate, tr }) {
    const t = tr.attention ?? {};
    const o = tr.page?.overview ?? {};

    if (findings.length === 0) {
        return (
            <section className="min-w-0 rounded-[24px] border border-slate-200 bg-white px-6 py-4 shadow-sm" aria-labelledby="supplier-attention-heading" data-testid="supplier-attention-none">
                <h2 id="supplier-attention-heading" className="text-lg font-semibold text-slate-950">{o.follow_up_heading ?? 'Oppfølging'}</h2>
                <p className="mt-1 text-base text-slate-700">{o.follow_up_none ?? 'Ingen forhold er markert for oppfølging nå. Det er ikke en godkjenning av leverandøren – se fanene for status på krav, dokumentasjon og vurderinger.'}</p>
            </section>
        );
    }

    return (
        <section className="min-w-0 rounded-[24px] border border-slate-200 border-l-4 border-l-amber-400 bg-white px-6 py-4 shadow-sm" aria-labelledby="supplier-attention-heading" data-testid="supplier-attention">
            <h2 id="supplier-attention-heading" className="text-lg font-semibold text-slate-950">{t.heading ?? 'Trenger oppfølging'}</h2>
            <div className="mt-2">
                <SupplierAttentionFindings findings={findings} tr={tr} formatDate={formatDate} withLinks compact />
            </div>
        </section>
    );
}

// «Om leverandøren»: the field heading clearly larger than its value, the two kept close together.
// Values stay at 16 px, the module's floor for readable text, so the headings are 18 px.
const KEY_TERM = 'text-lg font-semibold text-slate-950';
const KEY_VALUE = 'mt-0.5 break-words text-base text-slate-700';

function KeyFact({ term, children, wide = false, testId }) {
    return (
        <div className={`min-w-0 ${wide ? 'sm:col-span-2' : ''}`} data-testid={testId}>
            <dt className={KEY_TERM}>{term}</dt>
            <dd className={KEY_VALUE}>{children}</dd>
        </div>
    );
}

/**
 * «Om leverandøren»: who the supplier is, what it delivers, who follows it up and how to reach it —
 * a compact two-column summary, one column on a phone. Criticality and status are at the top of the
 * page and not repeated. Nothing is made up for what is not registered.
 */
export function SupplierKeyFacts({ supplier, tr }) {
    const fields = tr.fields ?? {};
    const o = tr.page?.overview ?? {};
    const hasContact = Boolean(supplier.contact_name || supplier.contact_email || supplier.contact_phone);

    return (
        <section className="min-w-0 rounded-[24px] border border-slate-200 bg-white px-6 py-5 shadow-sm" aria-labelledby="supplier-details-heading" data-testid="supplier-key-facts">
            <h2 id="supplier-details-heading" className="text-xl font-semibold text-slate-950">{tr.details_heading ?? 'Om leverandøren'}</h2>
            <dl className="mt-3 grid max-w-3xl gap-x-10 gap-y-4 sm:grid-cols-2" data-testid="supplier-facts">
                <KeyFact term={fields.deliverable_description ?? 'Hva leverer de til oss?'} wide>
                    <span className="whitespace-pre-line" data-testid="supplier-deliverable">{supplier.deliverable_description}</span>
                </KeyFact>
                <KeyFact term={fields.category ?? 'Kategori'}>{categoryLabel(supplier.category, tr)}</KeyFact>
                <KeyFact term={fields.owner ?? 'Intern ansvarlig'}>
                    {supplier.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}
                </KeyFact>
                <KeyFact term={o.contact_label ?? 'Kontaktperson'} testId="supplier-contact">
                    {hasContact ? (
                        <>
                            {supplier.contact_name && <span className="block">{supplier.contact_name}</span>}
                            {supplier.contact_email && (
                                <a href={`mailto:${supplier.contact_email}`} className="block break-all text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900">{supplier.contact_email}</a>
                            )}
                            {supplier.contact_phone && (
                                <a href={`tel:${supplier.contact_phone.replace(/\s+/g, '')}`} className="block text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900">{supplier.contact_phone}</a>
                            )}
                        </>
                    ) : (
                        <span className="text-slate-600">{tr.no_contact ?? 'Ingen kontaktperson er registrert.'}</span>
                    )}
                </KeyFact>
                {supplier.organization_number && (
                    <KeyFact term={fields.organization_number ?? 'Organisasjonsnummer'}>{supplier.organization_number}</KeyFact>
                )}
            </dl>
        </section>
    );
}

/**
 * «Neste kontroll», its own compact section after «Om leverandøren»: the nearest entry of the plan
 * the server built (SupplierFollowUpPlan — overdue first, then nearest), the date prominent and what
 * it concerns under it, and «Vis alle kontroller» for the rest. A short empty state when nothing is
 * dated. Nothing is computed here.
 */
export function SupplierNextControl({ next, plan, formatDate, tr }) {
    const o = tr.page?.overview ?? {};
    const f = tr.follow_up ?? {};
    const [expanded, setExpanded] = useState(false);
    const entry = next?.entry ?? null;
    const onChange = plan?.on_change ?? [];
    const more = (next?.count ?? 0) > 1 || onChange.length > 0;

    return (
        <section className="min-w-0 rounded-[24px] border border-slate-200 bg-white px-6 py-5 shadow-sm" aria-labelledby="supplier-follow-up-heading" data-testid="supplier-next-control">
            <h2 id="supplier-follow-up-heading" className="text-xl font-semibold text-slate-950">{o.next_control ?? 'Neste kontroll'}</h2>
            {entry ? (
                <div className="mt-2 max-w-3xl">
                    <p className={`text-lg font-semibold ${entry.overdue ? 'text-amber-800' : 'text-slate-950'}`} data-testid="supplier-next-control-date">
                        {followUpEntryDate(entry, tr, formatDate)}
                        {entry.overdue && <> · {f.overdue ?? 'Forfalt'}</>}
                    </p>
                    <p className="mt-0.5 break-words text-base text-slate-600">
                        {entry.requirement
                            ? <a href={`#control-requirement-${entry.requirement.id}`} className="text-slate-700 underline decoration-slate-300 underline-offset-2 hover:text-violet-800">{followUpEntryText(entry, tr)}</a>
                            : followUpEntryText(entry, tr)}
                    </p>
                </div>
            ) : (
                <p className="mt-2 text-base text-slate-600" data-testid="supplier-next-control-none">{o.next_control_none ?? 'Ingen kommende kontroll er registrert.'}</p>
            )}
            {more && (
                <>
                    <button
                        type="button"
                        className="mt-1 min-h-11 text-base font-semibold text-violet-700 hover:text-violet-900"
                        aria-expanded={expanded}
                        aria-controls="supplier-follow-up-list"
                        onClick={() => setExpanded((value) => ! value)}
                        data-testid="supplier-next-control-toggle"
                    >
                        {expanded ? (o.hide_controls ?? 'Skjul kontrollene') : (o.show_controls ?? 'Vis alle kontroller (:count)').replace(':count', String((next?.count ?? 0) + onChange.length))}
                    </button>
                    <div id="supplier-follow-up-list" hidden={! expanded} className="mt-1 max-w-3xl">
                        <SupplierFollowUpPlan plan={plan} formatDate={formatDate} tr={tr} />
                    </div>
                </>
            )}
        </section>
    );
}

/** «Leverandørinformasjon» under Detaljer: what is registered beyond the key facts — the note and the registration. */
export function SupplierInfoDetails({ supplier, registered, formatDate, tr }) {
    const fields = tr.fields ?? {};
    const o = tr.page?.overview ?? {};

    return (
        <dl className="grid gap-4 sm:grid-cols-2" data-testid="supplier-info">
            <Fact term={fields.note ?? 'Notat'} wide>
                {supplier.note
                    ? <span className="whitespace-pre-line">{supplier.note}</span>
                    : <span className="text-slate-600">{o.no_note ?? 'Ingen notat er registrert.'}</span>}
            </Fact>
            {registered?.at && <Fact term={o.registered ?? 'Registrert'}>{formatDate(registered.at)}</Fact>}
            {registered?.at && <Fact term={o.registered_by ?? 'Registrert av'}>{registered.by_name ?? (tr.unknown_user ?? 'en tidligere bruker')}</Fact>}
        </dl>
    );
}
