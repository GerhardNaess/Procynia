import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function CatalogModal({
    isOpen,
    title,
    description,
    form,
    onClose,
    onSubmit,
    topicOptions = [],
    showTopicSelector = false,
    allowedTopicsLabel,
    selectTopicsLabel,
    noTopicsSelectedLabel,
    saveLabel,
    cancelLabel,
    nameLabel,
    descriptionLabel,
    statusLabel,
    activeLabel,
    inactiveLabel,
}) {
    if (!isOpen) {
        return null;
    }

    const selectedTopicIds = Array.isArray(form.data.topic_ids) ? form.data.topic_ids.map((topicId) => Number(topicId)) : [];

    const toggleTopic = (topicId) => {
        const normalizedTopicId = Number(topicId);
        const nextTopicIds = selectedTopicIds.includes(normalizedTopicId)
            ? selectedTopicIds.filter((id) => id !== normalizedTopicId)
            : [...selectedTopicIds, normalizedTopicId];

        form.setData('topic_ids', nextTopicIds);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-950/45 px-4 py-4">
            <div className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div className="space-y-1">
                        <h2 className="text-xl font-semibold text-slate-950">{title}</h2>
                        {description ? <p className="text-sm leading-6 text-slate-500">{description}</p> : null}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-900"
                    >
                        ×
                    </button>
                </div>

                <form onSubmit={onSubmit} className="min-h-0 flex-1 overflow-y-auto px-6 py-6">
                    <div className="space-y-4">
                        <label className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">{nameLabel}</span>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {form.errors.name ? <p className="text-sm text-rose-600">{form.errors.name}</p> : null}
                        </label>

                        <label className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">{descriptionLabel}</span>
                            <textarea
                                rows={4}
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {form.errors.description ? <p className="text-sm text-rose-600">{form.errors.description}</p> : null}
                        </label>

                        <label className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">{statusLabel}</span>
                            <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.data.is_active)}
                                    onChange={(event) => form.setData('is_active', event.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                />
                                <span>{form.data.is_active ? activeLabel : inactiveLabel}</span>
                            </label>
                            {form.errors.is_active ? <p className="text-sm text-rose-600">{form.errors.is_active}</p> : null}
                        </label>

                        {showTopicSelector ? (
                            <div className="space-y-2">
                                <span className="text-sm font-medium text-slate-700">{allowedTopicsLabel}</span>
                                <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                    <p className="text-xs leading-5 text-slate-500">{selectTopicsLabel}</p>

                                    {topicOptions.length > 0 ? (
                                        <div className="space-y-2">
                                            {topicOptions.map((topic) => {
                                                const topicId = Number(topic.id);
                                                const checked = selectedTopicIds.includes(topicId);

                                                return (
                                                    <label
                                                        key={topicId}
                                                        className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 transition hover:border-violet-300"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            onChange={() => toggleTopic(topicId)}
                                                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                                        />
                                                        <span className="min-w-0">
                                                            <span className="block font-medium text-slate-900">{topic.name}</span>
                                                            {topic.description ? <span className="block text-xs leading-5 text-slate-500">{topic.description}</span> : null}
                                                        </span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <p className="text-sm leading-6 text-slate-500">{noTopicsSelectedLabel}</p>
                                    )}
                                </div>
                            </div>
                        ) : null}
                    </div>

                    <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            {cancelLabel}
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {saveLabel}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function CatalogSection({
    title,
    description,
    items,
    createLabel,
    emptyTitle,
    emptyDescription,
    showTopicsSummary = false,
    topicsForCategoryLabel,
    noTopicsSelectedLabel,
    nameLabel,
    descriptionLabel,
    statusLabel,
    activeLabel,
    inactiveLabel,
    editLabel,
    deleteLabel,
    actionsLabel,
    openCreate,
    openEdit,
    deleteRecord,
    busyAction,
    sectionKey,
}) {
    const hasItems = items.length > 0;

    return (
        <section className="space-y-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-1">
                    <h2 className="text-2xl font-semibold tracking-tight text-slate-950">{title}</h2>
                    {description ? <p className="max-w-3xl text-sm leading-6 text-slate-500">{description}</p> : null}
                </div>
                <button
                    type="button"
                    onClick={openCreate}
                    className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                >
                    {createLabel}
                </button>
            </div>

            {hasItems ? (
                <div className="overflow-hidden rounded-[22px] border border-slate-200">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50/80">
                                <tr className="text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <th className="px-4 py-3">{nameLabel}</th>
                                    <th className="px-4 py-3">{descriptionLabel}</th>
                                    <th className="px-4 py-3">{statusLabel}</th>
                                    <th className="px-4 py-3 text-right">{actionsLabel}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 bg-white">
                                {items.map((item) => {
                                    const isBusy = busyAction === `${sectionKey}:${item.id}`;

                                    return (
                                        <tr key={item.id} className="align-top text-sm text-slate-700">
                                            <td className="px-4 py-3.5 font-medium text-slate-950">{item.name}</td>
                                            <td className="px-4 py-3.5 text-slate-500">
                                                <div className="space-y-1.5">
                                                    <div>{item.description || '—'}</div>
                                                    {showTopicsSummary ? (
                                                        item.topics?.length > 0 ? (
                                                            <div className="text-xs leading-5 text-slate-500">
                                                                <span className="font-medium text-slate-600">{topicsForCategoryLabel}:</span>{' '}
                                                                {item.topics.map((topic) => topic.name).join(', ')}
                                                            </div>
                                                        ) : (
                                                            <div className="text-xs leading-5 text-slate-400">{noTopicsSelectedLabel}</div>
                                                        )
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span
                                                    className={
                                                        item.is_active
                                                            ? 'inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700'
                                                            : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                    }
                                                >
                                                    {item.status_label ?? (item.is_active ? activeLabel : inactiveLabel)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => openEdit(item)}
                                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                    >
                                                        {editLabel}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => deleteRecord(item)}
                                                        disabled={isBusy}
                                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {isBusy ? `${deleteLabel}...` : deleteLabel}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            ) : (
                <div className="rounded-[22px] border border-dashed border-slate-200 bg-slate-50/70 px-5 py-8">
                    <div className="text-sm font-semibold text-slate-900">{emptyTitle}</div>
                    <p className="mt-1 text-sm leading-6 text-slate-500">{emptyDescription}</p>
                </div>
            )}
        </section>
    );
}

export default function KnowledgeBaseSettings({
    pageTitle = 'Kunnskapsbase',
    pageSubtitle = '',
    scopeNote = '',
    documentCategories = [],
    documentTopics = [],
    routes = {},
}) {
    const { translations = {} } = usePage().props;
    const commonText = translations?.common ?? {};
    const knowledgeBaseText = translations?.knowledge_base_settings ?? {};
    const activeTopicOptions = useMemo(() => documentTopics.filter((topic) => topic.is_active), [documentTopics]);
    const [modalState, setModalState] = useState(null);
    const [busyAction, setBusyAction] = useState(null);
    const form = useForm({
        name: '',
        description: '',
        is_active: true,
        topic_ids: [],
    });

    const activeCatalog = useMemo(() => {
        if (!modalState) {
            return null;
        }

        return modalState.type === 'category'
            ? {
                title: knowledgeBaseText.modal_create_category_title ?? 'Opprett dokumentkategori',
                editTitle: knowledgeBaseText.modal_edit_category_title ?? 'Rediger dokumentkategori',
                storeUrl: routes.category_store,
                sectionKey: 'category',
            }
            : {
                title: knowledgeBaseText.modal_create_topic_title ?? 'Opprett tema',
                editTitle: knowledgeBaseText.modal_edit_topic_title ?? 'Rediger tema',
                storeUrl: routes.topic_store,
                sectionKey: 'topic',
            };
    }, [knowledgeBaseText.modal_create_category_title, knowledgeBaseText.modal_create_topic_title, knowledgeBaseText.modal_edit_category_title, knowledgeBaseText.modal_edit_topic_title, modalState, routes.category_store, routes.topic_store]);

    const openCreate = (type) => {
        form.clearErrors();
        form.setData({
            name: '',
            description: '',
            is_active: true,
            topic_ids: [],
        });
        setModalState({ type, mode: 'create', record: null });
    };

    const openEdit = (type, record) => {
        form.clearErrors();
        form.setData({
            name: record.name ?? '',
            description: record.description ?? '',
            is_active: Boolean(record.is_active),
            topic_ids: type === 'category' ? (record.topic_ids ?? record.topics?.map((topic) => topic.id) ?? []) : [],
        });
        setModalState({ type, mode: 'edit', record });
    };

    const closeModal = () => {
        setModalState(null);
        form.reset();
        form.clearErrors();
    };

    const submitModal = (event) => {
        event.preventDefault();

        if (!modalState || !activeCatalog) {
            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        };

        if (modalState.mode === 'create') {
            form.post(activeCatalog.storeUrl, options);
            return;
        }

        form.patch(modalState.record.update_url, options);
    };

    const deleteRecord = (type, record) => {
        const confirmMessage = type === 'category'
            ? `${knowledgeBaseText.delete_category_confirm ?? 'Slett dokumentkategori'} "${record.name}"?`
            : `${knowledgeBaseText.delete_topic_confirm ?? 'Slett tema'} "${record.name}"?`;

        if (!window.confirm(confirmMessage)) {
            return;
        }

        const actionKey = `${type}:${record.id}`;
        setBusyAction(actionKey);

        router.delete(record.destroy_url, {
            preserveScroll: true,
            onFinish: () => setBusyAction(null),
        });
    };

    const sectionTitle = pageTitle || knowledgeBaseText.page_title || 'Kunnskapsbase';
    const subtitleText = pageSubtitle || knowledgeBaseText.page_subtitle || '';
    const noteText = scopeNote || knowledgeBaseText.scope_note || '';

    return (
        <CustomerAppLayout title={sectionTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-3">
                    <div className="space-y-2">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            {sectionTitle}
                        </div>
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            {sectionTitle}
                        </h1>
                        {subtitleText ? <p className="max-w-3xl text-[15px] leading-7 text-slate-500">{subtitleText}</p> : null}
                    </div>

                    {noteText ? (
                        <div className="rounded-[20px] border border-violet-200 bg-violet-50 px-5 py-4 text-sm leading-6 text-violet-900">
                            {noteText}
                        </div>
                    ) : null}
                </section>

                <CatalogSection
                    title={knowledgeBaseText.categories_title ?? 'Dokumentkategorier'}
                    description={knowledgeBaseText.categories_description ?? ''}
                    items={documentCategories}
                    createLabel={knowledgeBaseText.create_category ?? 'Opprett dokumentkategori'}
                    emptyTitle={knowledgeBaseText.empty_categories_title ?? 'Ingen dokumentkategorier ennå'}
                    emptyDescription={knowledgeBaseText.empty_categories_text ?? 'Opprett den første dokumentkategorien for å begynne å styre kunnskapsdokumentene.'}
                    showTopicsSummary
                    topicsForCategoryLabel={knowledgeBaseText.topics_for_category ?? 'Temaer for dokumentkategori'}
                    noTopicsSelectedLabel={knowledgeBaseText.no_topics_selected ?? 'Ingen temaer valgt'}
                    nameLabel={commonText.name ?? 'Navn'}
                    descriptionLabel={commonText.description ?? 'Beskrivelse'}
                    statusLabel={commonText.status ?? 'Status'}
                    activeLabel={commonText.active ?? 'Aktiv'}
                    inactiveLabel={knowledgeBaseText.inactive ?? 'Inaktiv'}
                    editLabel={knowledgeBaseText.edit ?? 'Rediger'}
                    deleteLabel={knowledgeBaseText.delete ?? 'Slett'}
                    actionsLabel={knowledgeBaseText.actions ?? 'Handlinger'}
                    openCreate={() => openCreate('category')}
                    openEdit={(record) => openEdit('category', record)}
                    deleteRecord={(record) => deleteRecord('category', record)}
                    busyAction={busyAction}
                    sectionKey="category"
                />

                <CatalogSection
                    title={knowledgeBaseText.topics_title ?? 'Temaer'}
                    description={knowledgeBaseText.topics_description ?? ''}
                    items={documentTopics}
                    createLabel={knowledgeBaseText.create_topic ?? 'Opprett tema'}
                    emptyTitle={knowledgeBaseText.empty_topics_title ?? 'Ingen temaer ennå'}
                    emptyDescription={knowledgeBaseText.empty_topics_text ?? 'Opprett det første temaet for å samle faglige emner i kunnskapsbasen.'}
                    showTopicsSummary={false}
                    nameLabel={commonText.name ?? 'Navn'}
                    descriptionLabel={commonText.description ?? 'Beskrivelse'}
                    statusLabel={commonText.status ?? 'Status'}
                    activeLabel={commonText.active ?? 'Aktiv'}
                    inactiveLabel={knowledgeBaseText.inactive ?? 'Inaktiv'}
                    editLabel={knowledgeBaseText.edit ?? 'Rediger'}
                    deleteLabel={knowledgeBaseText.delete ?? 'Slett'}
                    actionsLabel={knowledgeBaseText.actions ?? 'Handlinger'}
                    openCreate={() => openCreate('topic')}
                    openEdit={(record) => openEdit('topic', record)}
                    deleteRecord={(record) => deleteRecord('topic', record)}
                    busyAction={busyAction}
                    sectionKey="topic"
                />
            </div>

            {modalState && activeCatalog ? (
                <CatalogModal
                    isOpen
                    title={modalState.mode === 'create' ? activeCatalog.title : activeCatalog.editTitle}
                    description={noteText}
                    form={form}
                    onClose={closeModal}
                    onSubmit={submitModal}
                    topicOptions={activeTopicOptions}
                    showTopicSelector={modalState.type === 'category'}
                    allowedTopicsLabel={knowledgeBaseText.allowed_topics ?? 'Lovlige temaer'}
                    selectTopicsLabel={knowledgeBaseText.select_topics ?? 'Velg temaer'}
                    noTopicsSelectedLabel={knowledgeBaseText.no_topics_selected ?? 'Ingen temaer valgt'}
                    saveLabel={commonText.save ?? 'Lagre'}
                    cancelLabel={commonText.cancel ?? 'Avbryt'}
                    nameLabel={commonText.name ?? 'Navn'}
                    descriptionLabel={commonText.description ?? 'Beskrivelse'}
                    statusLabel={commonText.status ?? 'Status'}
                    activeLabel={commonText.active ?? 'Aktiv'}
                    inactiveLabel={knowledgeBaseText.inactive ?? 'Inaktiv'}
                />
            ) : null}
        </CustomerAppLayout>
    );
}
