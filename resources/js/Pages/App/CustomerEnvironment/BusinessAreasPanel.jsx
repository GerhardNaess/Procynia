import { router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { PRIMARY_COLOURS, SECONDARY_COLOURS, WARNING_COLOURS } from '../../../Support/actionStyles';
import ScrollRegion from '../../../Components/App/ScrollRegion';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { rowErrorId, trackRowAction, useRowActionError } from '../../../Support/rowActionError';

/**
 * The customer's fagområder, beside their own roles in Kundemiljø → Tilganger.
 *
 * An area is only a name here. Which roles reach it is set on the role (Rediger rolle → Fagområder),
 * and the Roller column below reads that back — including roles with «Alle» — so an administrator
 * sees both directions in one place. Nothing on this panel shows risk content or counts:
 * administering access is not reading risks.
 */
export default function BusinessAreasPanel({ areas = [], roles = [], storeUrl, modal: Modal, t = {}, tAll = 'Alle' }) {
    const [areaModal, setAreaModal] = useState({ mode: null, area: null });
    const { flash = {} } = usePage().props;
    const rowError = useRowActionError(flash?.error ?? null);

    const form = useForm({ name: '', description: '' });
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const nameField = fieldA11y({ id: 'business-area-name', hasError: Boolean(form.errors.name) });
    const descriptionField = fieldA11y({ id: 'business-area-description', hasError: Boolean(form.errors.description) });

    const rolesReaching = (area) => roles
        .filter((role) => role.all_business_areas || (role.business_area_ids ?? []).includes(area.id))
        .map((role) => (role.all_business_areas ? `${role.name} (${tAll})` : role.name));

    const openCreate = () => {
        form.clearErrors();
        form.setData({ name: '', description: '' });
        setAreaModal({ mode: 'create', area: null });
    };

    const openEdit = (area) => {
        form.clearErrors();
        form.setData({ name: area.name, description: area.description ?? '' });
        setAreaModal({ mode: 'edit', area });
    };

    const close = () => {
        setAreaModal({ mode: null, area: null });
        form.clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => close(), onError: focusFirstError };

        if (areaModal.mode === 'edit' && areaModal.area) {
            form.patch(areaModal.area.update_url, options);

            return;
        }

        form.post(storeUrl, options);
    };

    const destroy = (area) => {
        if (! window.confirm(t.delete_confirm ?? 'Slett fagområdet?')) {
            return;
        }

        // An area still in use is refused; the reason is shown beside its Slett button (A11Y-137).
        router.delete(area.delete_url, trackRowAction(`area-delete-${area.id}`, { preserveScroll: true, preserveState: false }));
    };

    return (
        <>
            <div id="business-areas" className="mt-6 scroll-mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-950">{t.heading ?? 'Fagområder'}</h2>
                        <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                            {t.subtitle ?? 'Fagområder brukes til å bestemme hvilke deler av virksomheten en rolle kan se og arbeide med.'}
                        </p>
                        <p className="mt-2 max-w-3xl text-base leading-6 text-slate-600">
                            {t.system_owner_note ?? 'System Owner leser ikke risikoer automatisk.'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={openCreate}
                        className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${PRIMARY_COLOURS}`}
                    >
                        {t.create ?? 'Nytt fagområde'}
                    </button>
                </div>

                {areas.length === 0 ? (
                    <p className="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-base leading-6 text-slate-600">
                        {t.empty ?? 'Ingen fagområder ennå.'}
                    </p>
                ) : (
                    <ScrollRegion label={t.heading ?? 'Fagområder'} className="mt-6">
                        <table className="w-full text-base">
                            <caption className="sr-only">{t.heading ?? 'Fagområder'}</caption>
                            <thead>
                                <tr className="border-b border-slate-200">
                                    <th scope="col" className="pb-3 pr-6 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                        {t.col_area ?? 'Fagområde'}
                                    </th>
                                    <th scope="col" className="px-4 pb-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                        {t.col_roles ?? 'Roller'}
                                    </th>
                                    {/* Was an empty <th> (A11Y-108): a column a screen reader announced without a name. */}
                                    <th scope="col" className="pb-3 pl-4">
                                        <span className="sr-only">{t.col_actions ?? 'Handlinger'}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {areas.map((area) => (
                                    <tr key={area.id}>
                                        <th scope="row" className="py-4 pr-6 text-left font-normal text-slate-900">
                                            <span className="font-medium">{area.name}</span>
                                            {area.description ? (
                                                <span className="mt-0.5 block max-w-md text-base font-normal leading-6 text-slate-600">
                                                    {area.description}
                                                </span>
                                            ) : null}
                                        </th>
                                        <td className="px-4 py-4 text-slate-600">
                                            {rolesReaching(area).length > 0 ? rolesReaching(area).join(', ') : '—'}
                                        </td>
                                        <td className="py-4 pl-4 text-right">
                                            <div className="inline-flex flex-wrap justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(area)}
                                                    aria-label={`${t.edit ?? 'Rediger'} ${area.name}`}
                                                    className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                                                >
                                                    {t.edit ?? 'Rediger'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => destroy(area)}
                                                    aria-label={`${t.delete ?? 'Slett'} ${area.name}`}
                                                    aria-describedby={rowError(`area-delete-${area.id}`) ? rowErrorId(`area-delete-${area.id}`) : undefined}
                                                    className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${WARNING_COLOURS}`}
                                                >
                                                    {t.delete ?? 'Slett'}
                                                </button>
                                            </div>
                                            {rowError(`area-delete-${area.id}`) ? (
                                                <p id={rowErrorId(`area-delete-${area.id}`)} className="mt-2 ml-auto max-w-xs text-left text-base text-rose-700">
                                                    {rowError(`area-delete-${area.id}`)}
                                                </p>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ScrollRegion>
                )}
            </div>

            <Modal
                isOpen={areaModal.mode !== null}
                title={areaModal.mode === 'edit' ? (t.modal_edit_title ?? 'Rediger fagområde') : (t.modal_create_title ?? 'Nytt fagområde')}
                onClose={close}
            >
                <form ref={formRef} onSubmit={submit} className="space-y-5">
                    <div>
                        <label htmlFor="business-area-name" className="block text-base font-semibold text-slate-900">
                            {t.field_name ?? 'Navn'}
                        </label>
                        <input
                            {...nameField.controlProps}
                            type="text"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder={t.field_name_placeholder ?? ''}
                            className="mt-2 w-full rounded-xl border border-field-border px-4 py-2.5 text-base text-slate-900 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                        />
                        {form.errors.name ? <p id={nameField.errorId} className="mt-1.5 text-base text-rose-700">{form.errors.name}</p> : null}
                    </div>
                    <div>
                        <label htmlFor="business-area-description" className="block text-base font-semibold text-slate-900">
                            {t.field_description ?? 'Beskrivelse'}
                        </label>
                        <textarea
                            {...descriptionField.controlProps}
                            rows={3}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            className="mt-2 w-full rounded-xl border border-field-border px-4 py-2.5 text-base text-slate-900 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                        />
                        {form.errors.description ? <p id={descriptionField.errorId} className="mt-1.5 text-base text-rose-700">{form.errors.description}</p> : null}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            onClick={close}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                        >
                            {t.cancel ?? 'Avbryt'}
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${PRIMARY_COLOURS}`}
                        >
                            {form.processing ? (t.saving ?? 'Lagrer...') : (t.save ?? 'Lagre fagområde')}
                        </button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
