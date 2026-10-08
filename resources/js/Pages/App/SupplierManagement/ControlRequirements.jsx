import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import ControlRequirementForm from './ControlRequirementForm';
import SupplierTabs from './SupplierTabs';
import { LEVEL_TONES, anchorLabel, appliesToText, controlPointLabel, groupByTheme, intervalLabel, levelLabel } from './controlRequirements';
import { supplierHelp } from './supplierHelp';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const HINT = 'text-base text-slate-600';

/**
 * Leverandører → Kontrollkrav (docs/supplier-assurance-v2-plan.md §5.1, §22.2): the catalogue, grouped
 * by theme, each requirement with when it applies — in words — and to how many suppliers it applies
 * now. Everyone with supplier.view reads it; only supplier.assure is offered changes, and the server
 * refuses them otherwise. A requirement for one supplier is on that supplier's page, not here.
 */
export default function SupplierControlRequirementsIndex() {
    const { translations = {}, requirements = [], form: formOptions = null, permissions = {}, errors = {} } = usePage().props;
    const tr = translations?.supplier_management ?? {};
    const c = tr.control ?? {};
    const k = c.catalogue ?? {};
    const canManage = permissions.can_manage ?? false;
    // 'new', a requirement id being edited, or null.
    const [editing, setEditing] = useState(null);
    const close = () => setEditing(null);
    const groups = groupByTheme(requirements, tr);
    const base = '/app/supplier-management/control-requirements';

    const post = (url, confirmText = null) => {
        if (confirmText && ! window.confirm(confirmText)) {
            return;
        }

        router.post(url, {}, { preserveScroll: true });
    };

    const destroy = (requirement) => {
        if (window.confirm(k.delete_confirm ?? 'Slette kravet for godt? Dette kan ikke angres.')) {
            router.delete(`${base}/${requirement.id}`, { preserveScroll: true });
        }
    };

    return (
        <CustomerAppLayout title={k.heading ?? 'Kontrollkrav'} showPageTitle={false}>
            <div className="space-y-6">
                <SupplierTabs current="control_requirements" tr={tr} />

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{k.heading ?? 'Kontrollkrav'}</h1>
                        <p className={HINT}>{k.intro}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...supplierHelp(tr, 'control_requirements')} />
                        {canManage && editing === null && (
                            <button type="button" onClick={() => setEditing('new')} className={PRIMARY_ACTION}>{k.create ?? 'Nytt kontrollkrav'}</button>
                        )}
                    </div>
                </header>

                {errors.requirement && <p className="text-base text-rose-700">{errors.requirement}</p>}

                {editing === 'new' && (
                    <section className={CARD} aria-labelledby="control-requirement-new-heading">
                        <h2 id="control-requirement-new-heading" className="text-xl font-semibold text-slate-950">{k.create_heading ?? 'Nytt kontrollkrav'}</h2>
                        <ControlRequirementForm options={formOptions ?? {}} onDone={close} tr={tr} />
                    </section>
                )}

                {groups.length === 0 ? (
                    <section className={CARD} data-testid="control-catalogue-empty">
                        <p className="text-base text-slate-900">{k.none ?? 'Ingen kontrollkrav er registrert ennå.'}</p>
                        <p className={`mt-1 ${HINT}`}>{k.none_hint}</p>
                    </section>
                ) : groups.map((group) => (
                    <section key={group.theme} className={CARD} aria-labelledby={`control-theme-${group.theme}`}>
                        <h2 id={`control-theme-${group.theme}`} className="text-xl font-semibold text-slate-950">{group.label}</h2>
                        <ul className="mt-3 space-y-3">
                            {group.rows.map((row) => (
                                <li key={row.id} className="min-w-0 rounded-xl border border-slate-200 p-4" data-testid="control-catalogue-row">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="min-w-0 break-words text-base font-semibold text-slate-950">{row.title}</span>
                                        <StatusBadge tone={LEVEL_TONES[row.level] ?? 'slate'}>{levelLabel(row.level, tr)}</StatusBadge>
                                        {row.status === 'retired' && <StatusBadge tone="slate">{k.retired ?? 'Utgått'}</StatusBadge>}
                                    </div>
                                    <p className="mt-2 break-words text-base font-semibold text-slate-900" data-testid="control-rule-text">{row.rule_text}</p>
                                    {row.status !== 'retired' && <p className="text-base text-slate-700">{appliesToText(row.applies_to_count, tr)}</p>}
                                    <p className="mt-1 break-words text-base text-slate-700">
                                        {controlPointLabel(row.control_point, tr)} · {intervalLabel(row.control_interval_months, tr)}
                                    </p>
                                    {row.description && <p className="mt-2 whitespace-pre-line break-words text-base text-slate-700">{row.description}</p>}
                                    {row.basis_text && <p className="mt-1 break-words text-base text-slate-700">{c.basis ?? 'Grunnlag'}: {row.basis_text}</p>}
                                    {row.anchor && (
                                        <p className="mt-1 break-words text-base text-slate-700">
                                            {c.anchor ?? 'Forankret i'}:{' '}
                                            <a href={row.anchor.url} className="font-semibold text-violet-700 hover:text-violet-900">{anchorLabel(row.anchor)}</a>
                                            {row.anchor.retired && ` (${c.anchor_retired ?? 'utgått i Etterlevelse og revisjon'})`}
                                        </p>
                                    )}

                                    {canManage && editing === null && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button type="button" onClick={() => setEditing(row.id)} className={SECONDARY_ACTION}>{k.edit ?? 'Rediger'}</button>
                                            {row.status === 'active' ? (
                                                <button type="button" onClick={() => post(`${base}/${row.id}/retire`, k.retire_confirm)} className={WARNING_ACTION}>{k.retire ?? 'Sett som utgått'}</button>
                                            ) : (
                                                <button type="button" onClick={() => post(`${base}/${row.id}/reactivate`)} className={SECONDARY_ACTION}>{k.reactivate ?? 'Ta i bruk igjen'}</button>
                                            )}
                                            {row.deletable && <button type="button" onClick={() => destroy(row)} className={DESTRUCTIVE_ACTION}>{k.delete ?? 'Slett'}</button>}
                                        </div>
                                    )}
                                    {editing === row.id && (
                                        <>
                                            <h3 className="mt-4 text-lg font-semibold text-slate-950">{k.edit_heading ?? 'Rediger kontrollkrav'}</h3>
                                            <ControlRequirementForm requirement={row} options={formOptions ?? {}} onDone={close} tr={tr} />
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </CustomerAppLayout>
    );
}
