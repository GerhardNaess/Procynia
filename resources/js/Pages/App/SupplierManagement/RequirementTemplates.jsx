import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ActionDialog from '../../../Components/App/ActionDialog';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { LEVEL_TONES, levelLabel, templatePreview } from './controlRequirements';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const HINT = 'text-base text-slate-600';

const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, String(value)), text ?? '');

function ItemList({ items, tr, existing = false }) {
    const t = tr.templates ?? {};

    return (
        <ul className="mt-2 space-y-2">
            {items.map((item) => (
                <li key={item.key} className="flex min-w-0 flex-wrap items-center gap-2 text-base text-slate-900" data-testid={existing ? 'template-item-existing' : 'template-item-new'}>
                    <span className="min-w-0 break-words">{item.title}</span>
                    <StatusBadge tone={LEVEL_TONES[item.level] ?? 'slate'}>{levelLabel(item.level, tr)}</StatusBadge>
                    {existing && item.existing?.status === 'retired' && <span className="text-slate-600">({t.dialog_existing_retired ?? 'utgått'})</span>}
                    {existing && item.existing && item.existing.title !== item.title && (
                        <span className="min-w-0 break-words text-slate-600">({fill(t.dialog_existing_renamed ?? 'heter nå «:title»', { title: item.existing.title })})</span>
                    )}
                    {item.recommended_level && (
                        <span className="w-full min-w-0 break-words text-slate-700" data-testid="template-item-recommended">
                            {fill(t.recommended_level ?? 'Anbefalt som :level i denne malen. Nivået kan endres under Kontrollkrav.', { level: levelLabel(item.recommended_level, tr).toLowerCase() })}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * Kravmaler on Kontrollkrav (docs/supplier-assurance-v2-plan.md §16, §22.2): the templates, and «Ta i
 * bruk kravmal» behind a confirmation that says what is added and what is already there. What
 * exists is the server's answer, matched on the template item. Applying fills the catalogue only —
 * no supplier is controlled or approved by it. Only supplier.assure is offered the action; the server
 * refuses it otherwise.
 */
export default function RequirementTemplates({ templates = [], canManage = false, tr }) {
    const t = tr.templates ?? {};
    const [open, setOpen] = useState(null);
    const [processing, setProcessing] = useState(false);
    const confirmRef = useRef(null);
    const template = templates.find((candidate) => candidate.key === open) ?? null;
    const preview = templatePreview(template);
    const close = () => setOpen(null);

    const apply = () => {
        router.post(`/app/supplier-management/control-requirements/templates/${template.key}`, {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: close,
        });
    };

    if (templates.length === 0) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="requirement-templates-heading" data-testid="requirement-templates">
            <h2 id="requirement-templates-heading" className="text-xl font-semibold text-slate-950">{t.heading ?? 'Kravmaler'}</h2>
            <p className={`mt-1 ${HINT}`}>{t.intro}</p>
            <p className={`mt-1 ${HINT}`}>{t.note}</p>

            <ul className="mt-4 grid gap-3 lg:grid-cols-3">
                {templates.map((row) => (
                    <li key={row.key} className="flex min-w-0 flex-col rounded-xl border border-slate-200 p-4" data-testid="requirement-template">
                        <h3 className="break-words text-lg font-semibold text-slate-950">{row.name}</h3>
                        <p className="mt-1 break-words text-base text-slate-700">{row.purpose}</p>
                        <p className="mt-2 break-words text-base text-slate-700"><span className="font-semibold text-slate-900">{t.suited_for ?? 'Passer for'}:</span> {row.suited_for}</p>
                        <p className="mt-2 text-base text-slate-700">
                            {fill(t.item_count ?? ':count krav', { count: row.items.length })} · {fill(t.mandatory_count ?? ':count obligatoriske', { count: row.mandatory_count })}
                        </p>
                        {row.to_create_count === 0 && <p className="mt-1 text-base font-semibold text-slate-900" data-testid="requirement-template-complete">{t.all_present ?? 'Alle kravene er lagt til'}</p>}
                        {canManage && (
                            <div className="mt-auto pt-3">
                                <button type="button" onClick={() => setOpen(row.key)} className={SECONDARY_ACTION}>{t.apply ?? 'Ta i bruk kravmal'}</button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            <ActionDialog isOpen={canManage && template !== null} onClose={close} closeDisabled={processing} titleId="requirement-template-dialog-heading" initialFocusRef={confirmRef}>
                {template && (
                    <div data-testid="requirement-template-dialog">
                        <h2 id="requirement-template-dialog-heading" className="break-words text-xl font-semibold text-slate-950">{fill(t.dialog_heading ?? 'Ta i bruk kravmal: :name', { name: template.name })}</h2>
                        <p className={`mt-2 ${HINT}`}>{t.dialog_keeps}</p>

                        {preview.toCreate.length > 0 ? (
                            <>
                                <h3 className="mt-4 text-base font-semibold text-slate-950">{fill(t.dialog_to_create ?? 'Legges til i Kontrollkrav (:count)', { count: preview.toCreate.length })}</h3>
                                <ItemList items={preview.toCreate} tr={tr} />
                            </>
                        ) : (
                            <p className="mt-4 text-base text-slate-900" data-testid="requirement-template-nothing">{t.dialog_nothing ?? 'Alle kravene i malen finnes allerede. Ingenting legges til.'}</p>
                        )}
                        {preview.existing.length > 0 && (
                            <>
                                <h3 className="mt-4 text-base font-semibold text-slate-950">{fill(t.dialog_existing ?? 'Finnes allerede og legges ikke til på nytt (:count)', { count: preview.existing.length })}</h3>
                                <ItemList items={preview.existing} tr={tr} existing />
                            </>
                        )}

                        <div className="mt-5 flex flex-wrap gap-2">
                            {preview.toCreate.length > 0 && (
                                <button ref={confirmRef} type="button" onClick={apply} disabled={processing} className={PRIMARY_ACTION}>
                                    {fill(t.confirm ?? 'Legg til :count kontrollkrav', { count: preview.toCreate.length })}
                                </button>
                            )}
                            <button type="button" onClick={close} disabled={processing} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                        </div>
                    </div>
                )}
            </ActionDialog>
        </section>
    );
}
