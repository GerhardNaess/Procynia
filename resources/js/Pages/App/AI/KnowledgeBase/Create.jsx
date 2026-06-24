import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import KnowledgeItemForm, { KNOWLEDGE_DOCUMENT_TYPE_OPTIONS } from './KnowledgeItemForm';

function DuplicateFileDialog({ type, td = {}, onClose }) {
    if (!type) {
        return null;
    }

    const title =
        type === 'new_document'
            ? (td.new_document_title ?? 'Dokumentet finnes allerede')
            : type === 'same_document'
              ? (td.same_document_title ?? 'Filen finnes allerede som versjon')
              : (td.other_document_title ?? 'Filen finnes allerede i Kunnskapsbase');

    const body =
        type === 'new_document'
            ? (td.new_document_body ?? 'Denne filen finnes allerede i Kunnskapsbase. Åpne eksisterende dokument og bruk «Last opp ny versjon» dersom du vil erstatte innholdet.')
            : type === 'same_document'
              ? (td.same_document_body ?? 'Denne filen finnes allerede som en versjon av dette dokumentet. Velg en annen fil dersom du vil opprette en ny dokumentversjon.')
              : (td.other_document_body ?? 'Denne filen finnes allerede som et annet kunnskapsdokument. Åpne det eksisterende dokumentet dersom du vil se eller oppdatere det.');

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-950/45 px-4 py-4"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className="flex w-full max-w-lg flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="shrink-0 flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div className="space-y-1">
                        <h2 className="text-xl font-semibold text-slate-950">{title}</h2>
                        <p className="text-sm leading-6 text-slate-500">{body}</p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-900"
                    >
                        ×
                    </button>
                </div>
                <div className="shrink-0 border-t border-slate-200 bg-white px-6 py-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            {td.close ?? 'Lukk'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function KnowledgeBaseCreate({
    pageTitle = 'Kunnskapsbase · Last opp',
    documentTypeOptions = KNOWLEDGE_DOCUMENT_TYPE_OPTIONS,
    documentCategoryOptions = [],
    documentOwnershipOptions = [],
    defaultDocumentType = 'other',
    storeUrl = '/app/ai/knowledge-base',
    indexUrl,
}) {
    const { translations = {} } = usePage().props;
    const td = translations?.knowledge_duplicate ?? {};

    const [duplicateDialogType, setDuplicateDialogType] = useState(null);

    const form = useForm({
        document: null,
        document_type: defaultDocumentType,
        document_category_id: null,
        document_topic_id: null,
        ownership_type: 'company',
        ai_usage_enabled: true,
        document_status: 'active',
        last_reviewed_at: null,
        review_due_at: null,
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(storeUrl, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => {
                if (errors.duplicate_file) {
                    setDuplicateDialogType(errors.duplicate_file);
                }
            },
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
                            Kunnskapsbase
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
                        backHref={indexUrl}
                        submitLabel="Last opp dokument"
                        onSubmit={submit}
                        showFileUpload
                    />
                </section>
            </div>

            <DuplicateFileDialog
                type={duplicateDialogType}
                td={td}
                onClose={() => setDuplicateDialogType(null)}
            />
        </CustomerAppLayout>
    );
}
