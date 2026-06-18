import { useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import KnowledgeItemForm from './KnowledgeItemForm';

export default function KnowledgeBaseEdit({
    pageTitle = 'Kunnskapsbase · Rediger',
    knowledgeItem,
    documentTypeOptions,
    documentCategoryOptions = [],
    documentOwnershipOptions = [],
    documentThemeOptions = [],
    documentOwnerOptions = [],
    updateUrl,
    deleteUrl,
    indexUrl,
}) {
    const form = useForm({
        document_type: knowledgeItem.document_type,
        document_category_id: knowledgeItem.document_category_id ?? null,
        document_topic_id: knowledgeItem.document_topic_id ?? null,
        ownership_type: knowledgeItem.ownership_type ?? 'company',
        document_theme_term_id: knowledgeItem.document_theme_term_id ?? '',
        owner_user_id: knowledgeItem.owner_user_id ?? '',
        ai_usage_enabled: knowledgeItem.ai_usage_enabled ?? true,
        document_status: knowledgeItem.document_status ?? 'active',
        last_reviewed_at: knowledgeItem.last_reviewed_at ?? null,
        review_due_at: knowledgeItem.review_due_at ?? null,
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(updateUrl, {
            preserveScroll: true,
        });
    };

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="space-y-2">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            Kunnskapsbase
                        </div>
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            Rediger kunnskapsdokument
                        </h1>
                        <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                            Oppdater tilhørighet, dokumenteier, dokumentkategori eller status. For å endre innholdet må du laste opp et nytt dokument.
                        </p>
                    </div>
                </section>

                <KnowledgeItemForm
                    form={form}
                    documentTypeOptions={documentTypeOptions}
                    documentCategoryOptions={documentCategoryOptions}
                    documentOwnershipOptions={documentOwnershipOptions}
                    documentThemeOptions={documentThemeOptions}
                    documentOwnerOptions={documentOwnerOptions}
                    backHref={indexUrl}
                    submitLabel="Lagre endringer"
                    onSubmit={submit}
                    deleteUrl={deleteUrl}
                    knowledgeItem={knowledgeItem}
                />
            </div>
        </CustomerAppLayout>
    );
}
