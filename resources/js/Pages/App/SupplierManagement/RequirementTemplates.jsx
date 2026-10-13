import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ActionDialog from '../../../Components/App/ActionDialog';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { LEVEL_TONES, levelLabel, templateItemUse, templatePreview, themeLabel } from './controlRequirements';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const HINT = 'text-base text-slate-600';

const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, String(value)), text ?? '');

const USE_TONES = { in_use: 'emerald', in_use_retired: 'slate', not_in_use: 'slate' };

/**
 * The items of a template. In the confirmation it is split into what is added and what is already
 * there; `browse` is the read-only list on the card, for everyone who can see Kontrollkrav: each item
 * with its code and theme, and whether the customer already has it.
 */
function ItemList({ items, tr, existing = false, browse = false }) {
    const t = tr.templates ?? {};
    const testId = browse ? 'template-item' : (existing ? 'template-item-existing' : 'template-item-new');

    return (
        <ul className={browse ? 'divide-y divide-slate-100' : 'mt-2 space-y-2'}>
            {items.map((item) => (
                <li key={item.key} className={`flex min-w-0 flex-wrap items-center gap-2 text-base text-slate-900 ${browse ? 'py-2' : ''}`} data-testid={testId}>
                    {browse && <span className="font-semibold text-slate-600" data-testid="template-item-code">{item.key}</span>}
                    <span className="min-w-0 break-words">{item.title}</span>
                    <StatusBadge tone={LEVEL_TONES[item.level] ?? 'slate'}>{levelLabel(item.level, tr)}</StatusBadge>
                    {browse && (
                        <span data-testid="template-item-use" data-use={templateItemUse(item)}>
                            <StatusBadge tone={USE_TONES[templateItemUse(item)]}>{t[`item_${templateItemUse(item)}`] ?? templateItemUse(item)}</StatusBadge>
                        </span>
                    )}
                    {browse && <span className="min-w-0 break-words text-slate-600" data-testid="template-item-theme">{themeLabel(item.theme, tr)}</span>}
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
 * exists is the server's answer, matched on the template item. «Vis kravene» lists a template's items
 * on its card for everyone who reads Kontrollkrav — looking is not applying. Applying fills the catalogue only —
 * no supplier is controlled or approved by it. Only supplier.assure is offered the action; the server
 * refuses it otherwise. Until the templates' content is reviewed (plan §19.2), the section and the
 * dialog say so; `reviewed` comes from the server (RequirementTemplates::CONTENT_REVIEWED).
 *
 * The section is closed when the page opens — the templates are optional suggestions, the catalogue
 * above is the work. Its heading is a native button (aria-expanded); a closed section keeps its
 * content mounted behind `hidden`, so nothing opened in it is lost. A link to
 * #requirement-templates-heading («Bruk kravmal» on a supplier) opens it.
 */
function Unreviewed({ t }) {
    return (
        <p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900" role="note" data-testid="requirement-templates-unreviewed">
            {t.unreviewed ?? 'Kravmalene er ikke faglig eller juridisk kvalitetssikret ennå. Bruk dem som et utgangspunkt, og vurder tekst, nivå og intervall før kravene tas i bruk.'}
        </p>
    );
}

export default function RequirementTemplates({ templates = [], reviewed = false, canManage = false, tr }) {
    const t = tr.templates ?? {};
    const [expanded, setExpanded] = useState(false);
    const [open, setOpen] = useState(null);
    // The templates whose items are listed on the card. Reading changes nothing; it needs supplier.view only.
    const [shown, setShown] = useState([]);
    const toggleShown = (key) => setShown((current) => (current.includes(key) ? current.filter((candidate) => candidate !== key) : [...current, key]));
    const [processing, setProcessing] = useState(false);
    const confirmRef = useRef(null);
    const template = templates.find((candidate) => candidate.key === open) ?? null;
    const preview = templatePreview(template);
    const close = () => setOpen(null);

    const apply = () => {
        router.post(`/app/supplier-management/control-requirements/templates/${template.key}`, {}, {
            preserveScroll: true,
            // The section stays open on the template just applied.
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: close,
        });
    };

    useEffect(() => {
        if (window.location.hash === '#requirement-templates-heading') {
            setExpanded(true);
            document.getElementById('requirement-templates-heading')?.scrollIntoView?.({ block: 'start' });
        }
    }, []);

    if (templates.length === 0) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="requirement-templates-heading" data-testid="requirement-templates" data-open={expanded ? '1' : '0'}>
            <h2 id="requirement-templates-heading" className="scroll-mt-4">
                <button
                    type="button"
                    aria-expanded={expanded}
                    aria-controls="requirement-templates-panel"
                    onClick={() => setExpanded((value) => ! value)}
                    className="flex min-h-12 w-full min-w-0 items-start gap-3 rounded-xl py-1 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500"
                    data-testid="requirement-templates-toggle"
                >
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" className={`mt-1 h-5 w-5 shrink-0 text-slate-500 transition-transform ${expanded ? 'rotate-90' : ''}`}>
                        <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clipRule="evenodd" />
                    </svg>
                    <span className="min-w-0 flex-1">
                        <span className="block text-xl font-semibold text-slate-950">{t.heading ?? 'Kravmaler'}</span>
                        <span className="mt-0.5 block text-base font-normal text-slate-600" data-testid="requirement-templates-summary">{t.summary ?? 'Ferdige forslag til kontrollkrav som kan legges til virksomhetens kravbibliotek. Gjennomgå og tilpass kravene før bruk.'}</span>
                    </span>
                </button>
            </h2>

            <div id="requirement-templates-panel" hidden={! expanded}>
                <p className={`mt-3 ${HINT}`}>{t.intro}</p>
                <p className={`mt-1 ${HINT}`}>{t.note}</p>
                {!reviewed && <Unreviewed t={t} />}

                <ul className="mt-4 grid gap-3 lg:grid-flow-row-dense lg:grid-cols-3">
                    {templates.map((row) => (
                        // An open template takes the whole row, so its list has room and the others keep their size.
                        <li key={row.key} className={`flex min-w-0 flex-col rounded-xl border border-slate-200 p-4 ${shown.includes(row.key) ? 'lg:col-span-3' : ''}`} data-testid="requirement-template">
                            <h3 className="break-words text-lg font-semibold text-slate-950">{row.name}</h3>
                            <p className="mt-1 break-words text-base text-slate-700">{row.purpose}</p>
                            <p className="mt-2 break-words text-base text-slate-700"><span className="font-semibold text-slate-900">{t.suited_for ?? 'Passer for'}:</span> {row.suited_for}</p>
                            <p className="mt-2 text-base text-slate-700">
                                {fill(t.item_count ?? ':count krav', { count: row.items.length })} · {fill(t.mandatory_count ?? ':count obligatoriske', { count: row.mandatory_count })}
                            </p>
                            {row.to_create_count === 0 && <p className="mt-1 text-base font-semibold text-slate-900" data-testid="requirement-template-complete">{t.all_present ?? 'Alle kravene er lagt til'}</p>}
                            <div className="mt-auto flex flex-wrap gap-2 pt-3">
                                <button
                                    type="button"
                                    onClick={() => toggleShown(row.key)}
                                    aria-expanded={shown.includes(row.key)}
                                    aria-controls={`requirement-template-items-${row.key}`}
                                    className={SECONDARY_ACTION}
                                    data-testid="requirement-template-toggle"
                                >
                                    {shown.includes(row.key) ? (t.hide_items ?? 'Skjul kravene') : fill(t.show_items ?? 'Vis kravene (:count)', { count: row.items.length })}
                                </button>
                                {canManage && (
                                    <button type="button" onClick={() => setOpen(row.key)} className={SECONDARY_ACTION}>{t.apply ?? 'Ta i bruk kravmal'}</button>
                                )}
                            </div>
                            {shown.includes(row.key) && (
                                <div id={`requirement-template-items-${row.key}`} className="mt-3 border-t border-slate-200 pt-1" aria-label={fill(t.items_label ?? 'Kravene i :name', { name: row.name })} role="region" data-testid="requirement-template-items">
                                    <ItemList items={row.items} tr={tr} browse />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </div>

            <ActionDialog isOpen={canManage && template !== null} onClose={close} closeDisabled={processing} titleId="requirement-template-dialog-heading" initialFocusRef={confirmRef}>
                {template && (
                    <div data-testid="requirement-template-dialog">
                        <h2 id="requirement-template-dialog-heading" className="break-words text-xl font-semibold text-slate-950">{fill(t.dialog_heading ?? 'Ta i bruk kravmal: :name', { name: template.name })}</h2>
                        <p className={`mt-2 ${HINT}`}>{t.dialog_keeps}</p>
                        {!reviewed && <Unreviewed t={t} />}

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
