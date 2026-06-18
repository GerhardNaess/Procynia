import { useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import KnowledgeItemForm, { KNOWLEDGE_DOCUMENT_TYPE_OPTIONS } from './KnowledgeItemForm';

export default function KnowledgeBaseCreate({
    pageTitle = 'Kunnskapsdokumenter · Last opp',
    documentTypeOptions = KNOWLEDGE_DOCUMENT_TYPE_OPTIONS,
    documentCategoryOptions = [],
    documentOwnershipOptions = [],
    documentThemeOptions = [],
    defaultDocumentType = 'other',
    storeUrl = '/app/ai/knowledge-base',
    indexUrl,
}) {
    const form = useForm({
        document: null,
        document_type: defaultDocumentType,
        document_category_id: null,
        document_topic_id: null,
        ownership_type: 'company',
        document_theme_term_id: '',
        is_active: true,
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(storeUrl, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="space-y-2">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            Kunnskapsdokumenter
                        </div>
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            Kunnskapsdokumenter
                        </h1>
                        <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                            Last opp dokumenter som senere kan brukes som grunnlag for AI-forslag til besvarelse.
                        </p>
                    </div>
                    <KnowledgeItemForm
                        form={form}
                        documentTypeOptions={documentTypeOptions}
                        documentCategoryOptions={documentCategoryOptions}
                        documentOwnershipOptions={documentOwnershipOptions}
                        documentThemeOptions={documentThemeOptions}
                        backHref={indexUrl}
                        submitLabel="Last opp dokument"
                        onSubmit={submit}
                        showFileUpload
                    />
                </section>
            </div>
        </CustomerAppLayout>
    );
}
