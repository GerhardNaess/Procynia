import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';

function formatDateTime(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function formatStatusBadgeClass(status) {
    if (status === 'completed') {
        return 'inline-flex items-center justify-center rounded-full px-3 py-1.5 text-base font-semibold leading-6 ring-1 ring-inset bg-emerald-100 text-emerald-700 ring-emerald-200';
    }

    if (status === 'pending_review') {
        return 'inline-flex min-w-[118px] items-center justify-center rounded-xl px-4 py-2 text-center text-base font-semibold leading-6 ring-1 ring-inset bg-amber-100 text-amber-800 ring-amber-200 whitespace-nowrap';
    }

    if (status === 'failed') {
        return 'inline-flex items-center justify-center rounded-full px-3 py-1.5 text-base font-semibold leading-6 ring-1 ring-inset bg-rose-100 text-rose-700 ring-rose-200';
    }

    if (status === 'analyzing' || status === 'parsing') {
        return 'inline-flex items-center justify-center rounded-full px-3 py-1.5 text-base font-semibold leading-6 ring-1 ring-inset bg-sky-100 text-sky-700 ring-sky-200';
    }

    return 'inline-flex items-center justify-center rounded-full px-3 py-1.5 text-base font-semibold leading-6 ring-1 ring-inset bg-slate-100 text-slate-700 ring-slate-200';
}

function normalizeText(value) {
    return String(value ?? '').trim();
}

function formatSynonyms(value) {
    if (!Array.isArray(value) || value.length === 0) {
        return '—';
    }

    return value.join(', ');
}

function SuggestionEditModal({ suggestion, typeOptions, form, onClose, onSubmit }) {
    if (!suggestion) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/45 px-4 py-6"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className="w-full max-w-3xl overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div className="space-y-1">
                        <h2 className="text-xl font-semibold text-slate-950">Rediger og godkjenn forslag</h2>
                        <p className="text-base leading-6 text-slate-600">
                            Juster canonical_name, synonymer eller beskrivelse før du godkjenner.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-slate-300 hover:text-slate-900"
                    >
                        ×
                    </button>
                </div>

                <form onSubmit={onSubmit} className="space-y-5 px-6 py-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="space-y-2">
                            <span className="text-base font-medium text-slate-700">Type</span>
                            <select
                                value={form.data.suggested_type}
                                onChange={(event) => form.setData('suggested_type', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                {typeOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.suggested_type ? <p className="text-base text-rose-700">{form.errors.suggested_type}</p> : null}
                        </label>

                        <label className="space-y-2">
                            <span className="text-base font-medium text-slate-700">Canonical name</span>
                            <input
                                value={form.data.suggested_canonical_name}
                                onChange={(event) => form.setData('suggested_canonical_name', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                placeholder="Governance"
                            />
                            {form.errors.suggested_canonical_name ? <p className="text-base text-rose-700">{form.errors.suggested_canonical_name}</p> : null}
                        </label>
                    </div>

                    <label className="space-y-2">
                        <span className="text-base font-medium text-slate-700">Synonymer</span>
                        <textarea
                            value={form.data.suggested_synonyms}
                            onChange={(event) => form.setData('suggested_synonyms', event.target.value)}
                            rows={4}
                            className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            placeholder="samhandling, styring, møtefora"
                        />
                        {form.errors.suggested_synonyms ? <p className="text-base text-rose-700">{form.errors.suggested_synonyms}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="text-base font-medium text-slate-700">Beskrivelse</span>
                        <textarea
                            value={form.data.suggested_description}
                            onChange={(event) => form.setData('suggested_description', event.target.value)}
                            rows={4}
                            className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            placeholder="Kort forklaring av begrepet."
                        />
                        {form.errors.suggested_description ? <p className="text-base text-rose-700">{form.errors.suggested_description}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="text-base font-medium text-slate-700">Begrunnelse</span>
                        <textarea
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            rows={3}
                            className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            placeholder="Hvorfor bør dette begrepet inn i vokabularet?"
                        />
                        {form.errors.reason ? <p className="text-base text-rose-700">{form.errors.reason}</p> : null}
                    </label>

                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Avbryt
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-base font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {form.processing ? 'Lagrer...' : 'Lagre og godkjenn'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ApprovedTermEditModal({ term, typeOptions, form, onClose, onSubmit }) {
    if (!term) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/45 px-4 py-6"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className="w-full max-w-3xl overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div className="space-y-1">
                        <h2 className="text-xl font-semibold text-slate-950">Rediger godkjent begrep</h2>
                        <p className="text-base leading-6 text-slate-600">
                            Oppdater canonical_name, synonymer, type eller beskrivelse.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-slate-300 hover:text-slate-900"
                    >
                        ×
                    </button>
                </div>

                <form onSubmit={onSubmit} className="space-y-5 px-6 py-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="space-y-2">
                            <span className="text-base font-medium text-slate-700">Type</span>
                            <select
                                value={form.data.type}
                                onChange={(event) => form.setData('type', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                {typeOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.type ? <p className="text-base text-rose-700">{form.errors.type}</p> : null}
                        </label>

                        <label className="space-y-2">
                            <span className="text-base font-medium text-slate-700">Canonical name</span>
                            <input
                                value={form.data.canonical_name}
                                onChange={(event) => form.setData('canonical_name', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                placeholder="Governance"
                            />
                            {form.errors.canonical_name ? <p className="text-base text-rose-700">{form.errors.canonical_name}</p> : null}
                        </label>
                    </div>

                    <label className="space-y-2">
                        <span className="text-base font-medium text-slate-700">Synonymer</span>
                        <textarea
                            value={form.data.synonyms}
                            onChange={(event) => form.setData('synonyms', event.target.value)}
                            rows={4}
                            className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            placeholder="samhandling, styring, møtefora"
                        />
                        {form.errors.synonyms ? <p className="text-base text-rose-700">{form.errors.synonyms}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="text-base font-medium text-slate-700">Beskrivelse</span>
                        <textarea
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            rows={4}
                            className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            placeholder="Kort forklaring av begrepet."
                        />
                        {form.errors.description ? <p className="text-base text-rose-700">{form.errors.description}</p> : null}
                    </label>

                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Avbryt
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-base font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {form.processing ? 'Lagrer...' : 'Lagre endringer'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function KnowledgeVocabularyIndex({
    pageTitle = 'Standardvokabular',
    approvedVocabularyGroups = [],
    suggestions = [],
    recentBatches = [],
    typeOptions = [],
}) {
    const { locale = 'nb-NO', translations = {} } = usePage().props;
    const tai = translations?.ai ?? {};
    const editForm = useForm({
        suggested_type: '',
        suggested_canonical_name: '',
        suggested_synonyms: '',
        suggested_description: '',
        reason: '',
    });
    const termEditForm = useForm({
        type: '',
        canonical_name: '',
        synonyms: '',
        description: '',
    });
    const [editingSuggestion, setEditingSuggestion] = useState(null);
    const [editingTerm, setEditingTerm] = useState(null);
    const [mergeTargets, setMergeTargets] = useState({});
    const approvedTermsByType = useMemo(() => {
        return approvedVocabularyGroups.reduce((accumulator, group) => {
            accumulator[group.type] = Array.isArray(group.terms) ? group.terms : [];
            return accumulator;
        }, {});
    }, [approvedVocabularyGroups]);

    useEffect(() => {
        setMergeTargets((currentTargets) => {
            const nextTargets = { ...currentTargets };

            suggestions.forEach((suggestion) => {
                if (nextTargets[suggestion.id] !== undefined) {
                    return;
                }

                const terms = approvedTermsByType[suggestion.suggested_type] ?? [];
                nextTargets[suggestion.id] = suggestion.related_existing_term_id ?? (terms[0]?.id ?? null);
            });

            return nextTargets;
        });
    }, [suggestions, approvedTermsByType]);

    useEffect(() => {
        if (!editingSuggestion) {
            return;
        }

        editForm.setData({
            suggested_type: editingSuggestion.suggested_type ?? '',
            suggested_canonical_name: editingSuggestion.suggested_canonical_name ?? editingSuggestion.suggested_term ?? '',
            suggested_synonyms: Array.isArray(editingSuggestion.suggested_synonyms)
                ? editingSuggestion.suggested_synonyms.join(', ')
                : '',
            suggested_description: editingSuggestion.suggested_description ?? '',
            reason: editingSuggestion.reason ?? '',
        });
        editForm.clearErrors();
    }, [editingSuggestion?.id]);

    useEffect(() => {
        if (!editingTerm) {
            return;
        }

        termEditForm.setData({
            type: editingTerm.type ?? '',
            canonical_name: editingTerm.canonical_name ?? '',
            synonyms: Array.isArray(editingTerm.synonyms)
                ? editingTerm.synonyms.join(', ')
                : '',
            description: editingTerm.description ?? '',
        });
        termEditForm.clearErrors();
    }, [editingTerm?.id]);

    const submitEditAndApprove = (event) => {
        event.preventDefault();

        if (!editingSuggestion || editForm.processing) {
            return;
        }

        editForm.patch(editingSuggestion.edit_and_approve_url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setEditingSuggestion(null),
        });
    };

    const submitTermEdit = (event) => {
        event.preventDefault();

        if (!editingTerm || termEditForm.processing) {
            return;
        }

        termEditForm.patch(editingTerm.edit_url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setEditingTerm(null),
        });
    };

    const editErrorMessage = Object.values(editForm.errors).find(Boolean) ?? null;

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <Head title={pageTitle} />

            <div className="space-y-7">
                <section className="space-y-2">
                    <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                        Standardvokabular
                    </div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            Standardvokabular
                        </h1>
                        <PageHelpButton
                            buttonLabel={tai.vocabulary_page_help_button ?? 'Hjelp'}
                            title={tai.vocabulary_page_help_title ?? 'Standardvokabular'}
                            intro={tai.vocabulary_page_help_intro}
                            sections={[
                                {
                                    title: tai.vocabulary_page_help_section_what ?? 'Hva er standardvokabular?',
                                    items: [
                                        {
                                            title: tai.vocabulary_page_help_item_what_title ?? 'Godkjente begreper og synonymer',
                                            text: tai.vocabulary_page_help_item_what_text ?? 'Standardvokabular brukes til å bygge og vedlikeholde godkjente begreper, synonymer og metadata for kunden. Bare godkjente begreper regnes som autoritativt vokabular.',
                                        },
                                    ],
                                },
                                {
                                    title: tai.vocabulary_page_help_section_how ?? 'Hvordan brukes det i dag?',
                                    items: [
                                        {
                                            title: tai.vocabulary_page_help_item_how_title ?? 'Metadata-normalisering',
                                            text: tai.vocabulary_page_help_item_how_text ?? 'Godkjent standardvokabular brukes først og fremst når Procynia normaliserer metadata for kunnskapsdokumenter.',
                                        },
                                    ],
                                },
                                {
                                    title: tai.vocabulary_page_help_section_example ?? 'Eksempel',
                                    items: [
                                        {
                                            title: tai.vocabulary_page_help_item_example_title ?? 'Synonymer på tvers av dokumenter',
                                            text: tai.vocabulary_page_help_item_example_text ?? 'Hvis dokumentene bruker både "SLA", "tjenestenivåavtale" og "driftsavtale", kan standardvokabular hjelpe Procynia å forstå at disse begrepene er relaterte.',
                                        },
                                    ],
                                },
                                {
                                    title: tai.vocabulary_page_help_section_ai_diff ?? 'Forskjell på AI-instrukser',
                                    items: [
                                        {
                                            title: tai.vocabulary_page_help_item_ai_diff_title ?? 'Stil vs. begreper',
                                            text: tai.vocabulary_page_help_item_ai_diff_text ?? 'AI-instrukser beskriver tone, språk og skrivestil. Standardvokabular er strukturert begrepsdata for synonymer, kategorier og metadata.',
                                        },
                                    ],
                                },
                                {
                                    title: tai.vocabulary_page_help_section_knowledge_diff ?? 'Forskjell på kunnskapsdokumenter',
                                    items: [
                                        {
                                            title: tai.vocabulary_page_help_item_knowledge_diff_title ?? 'Kildemateriale vs. begrepslag',
                                            text: tai.vocabulary_page_help_item_knowledge_diff_text ?? 'Kunnskapsdokumenter er kildematerialet. Standardvokabular er et strukturert begrepslag Procynia bygger på toppen av dokumentene.',
                                        },
                                    ],
                                },
                                {
                                    title: tai.vocabulary_page_help_section_limitation ?? 'Viktig begrensning',
                                    items: [
                                        {
                                            title: tai.vocabulary_page_help_item_limitation_title ?? 'Påvirker ikke gamle chunks automatisk',
                                            text: tai.vocabulary_page_help_item_limitation_text ?? 'Godkjent vokabular omskriver ikke automatisk gamle chunks, eksisterende krav eller tidligere genererte svar. For at eldre dokumenter skal få full effekt, må metadata eventuelt genereres på nytt.',
                                        },
                                    ],
                                },
                                {
                                    title: tai.vocabulary_page_help_section_analysis ?? 'Hva skjer når du trykker Start analyse?',
                                    items: [
                                        {
                                            title: tai.vocabulary_page_help_item_analysis_title ?? 'Analyse og forslag til gjennomgang',
                                            text: tai.vocabulary_page_help_item_analysis_text ?? 'Procynia sender de valgte kunnskapsdokumentene som grunnlag for en vokabularanalyse. AI leser tekst og sammendrag fra dokumentene og forsøker å finne relevante begreper, synonymer, temaer, undertemaer, nøkkelord og metadata-verdier. Forslagene sammenlignes med eksisterende godkjent standardvokabular. Nye funn lagres som forslag til gjennomgang — de blir ikke automatisk godkjent og brukes ikke som autoritativt vokabular før en bruker har godkjent, redigert og godkjent, avvist eller slått dem sammen med et eksisterende begrep.',
                                        },
                                        {
                                            title: null,
                                            text: tai.vocabulary_page_help_item_analysis_summary ?? 'AI foreslår. Du godkjenner. Først etter godkjenning blir begrepet en del av kundens autoritative standardvokabular.',
                                        },
                                    ],
                                },
                            ]}
                        />
                    </div>
                    <p className="max-w-3xl text-base leading-7 text-slate-600">
                        Bygg og vedlikehold kundens godkjente vokabular for metadata, synonymer og begreper.
                    </p>
                    <p className="max-w-3xl text-base leading-6 text-slate-600">
                        AI foreslår. Du godkjenner. Bare godkjente verdier brukes som autoritativt vokabular.
                    </p>
                    <div className="mt-1 max-w-3xl rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 space-y-1">
                        <p className="text-base leading-6 text-slate-700">
                            {tai.vocabulary_metadata_notice ?? 'Godkjent vokabular brukes når Procynia normaliserer metadata for kunnskapsdokumenter. Det hjelper AI-en å finne riktig kunnskap selv om dokumentene bruker ulike ord for samme begrep.'}
                        </p>
                        <p className="text-base leading-6 text-slate-600">
                            {tai.vocabulary_metadata_notice_note ?? 'Merk: Nytt vokabular påvirker først og fremst nye eller reanalyserte kunnskapsdokumenter.'}
                        </p>
                    </div>
                </section>

                <section className="rounded-[22px] border border-amber-200 bg-amber-50 p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-2">
                        <div className="text-base font-medium uppercase tracking-[0.16em] text-amber-700">
                            {tai.knowledge_vocabulary_deprecated_kicker ?? 'Under utfasing'}
                        </div>
                        <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                            {tai.knowledge_vocabulary_deprecated_title ?? 'Nye analyser kan ikke lenger startes'}
                        </h2>
                        <p className="text-base leading-6 text-slate-700">
                            {tai.knowledge_vocabulary_deprecated_notice ?? 'Standardvokabular fases ut til fordel for Enterprise Wiki. Eksisterende godkjente begreper og ventende forslag kan fortsatt ses og behandles her, men det kan ikke lenger startes nye analyser. Se Wiki for virksomhetens samlede, godkjente kunnskap.'}
                        </p>
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-6">
                        <div className="space-y-2">
                            <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                                Godkjent vokabular
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                                Autoritativ katalog per type
                            </h2>
                            <p className="max-w-3xl text-base leading-6 text-slate-600">
                                Kun godkjente verdier er autoritative. Synonymer vises sammen med kanonisk term.
                            </p>
                        </div>

                        {approvedVocabularyGroups.length > 0 ? (
                            <div className="space-y-3">
                                {approvedVocabularyGroups.map((group) => (
                                    <details
                                        key={group.type}
                                        className="overflow-hidden rounded-[20px] border border-slate-200 bg-slate-50"
                                        open={group.count > 0}
                                    >
                                        <summary className="cursor-pointer list-none px-5 py-4">
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <div className="text-base font-semibold text-slate-950">
                                                        {group.label}
                                                    </div>
                                                    <div className="text-base uppercase tracking-[0.12em] text-slate-600">
                                                        {group.count} godkjente begreper
                                                    </div>
                                                </div>
                                                <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Klikk for å vise
                                                </div>
                                            </div>
                                        </summary>

                                        <div className="border-t border-slate-200 bg-white">
                                            <div className="overflow-hidden rounded-b-[20px]">
                                                <div className="overflow-x-auto">
                                                    <table className="min-w-full divide-y divide-slate-200">
                                                        <thead className="bg-slate-50">
                                                            <tr>
                                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    Canonical
                                                                </th>
                                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    Synonymer
                                                                </th>
                                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    Beskrivelse
                                                                </th>
                                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    Status
                                                                </th>
                                                                <th className="px-5 py-3 text-right text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    Handlinger
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-slate-200 bg-white">
                                                            {group.terms.map((term) => (
                                                                <tr key={term.id}>
                                                                    <td className="px-5 py-4 font-semibold text-slate-950">
                                                                        {term.canonical_name}
                                                                    </td>
                                                                    <td className="px-5 py-4 text-base text-slate-600">
                                                                        {formatSynonyms(term.synonyms)}
                                                                    </td>
                                                                    <td className="px-5 py-4 text-base text-slate-600">
                                                                        {term.description || '—'}
                                                                    </td>
                                                                    <td className="px-5 py-4">
                                                                        <span className="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1.5 text-base font-semibold uppercase tracking-[0.12em] leading-6 text-emerald-700">
                                                                            Godkjent
                                                                        </span>
                                                                    </td>
                                                                    <td className="px-5 py-4 text-right">
                                                                        <div className="flex flex-wrap justify-end gap-2">
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => {
                                                                                    if (!window.confirm(`Slette ${term.canonical_name}?`)) {
                                                                                        return;
                                                                                    }

                                                                                    router.delete(term.delete_url, {
                                                                                        preserveScroll: true,
                                                                                        preserveState: true,
                                                                                    });
                                                                                }}
                                                                                className="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                                            >
                                                                                <svg
                                                                                    aria-hidden="true"
                                                                                    viewBox="0 0 24 24"
                                                                                    fill="none"
                                                                                    stroke="currentColor"
                                                                                    strokeWidth="1.8"
                                                                                    strokeLinecap="round"
                                                                                    strokeLinejoin="round"
                                                                                    className="mr-2 h-4 w-4"
                                                                                >
                                                                                    <path d="M3 6h18" />
                                                                                    <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2" />
                                                                                    <path d="M10 11v6" />
                                                                                    <path d="M14 11v6" />
                                                                                    <path d="M6 6l1 14a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-14" />
                                                                                </svg>
                                                                                Slett
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => setEditingTerm(term)}
                                                                                className="inline-flex rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                                            >
                                                                                Rediger
                                                                            </button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </details>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-base text-slate-600">
                                Ingen godkjente vokabulartermer er registrert ennå.
                            </div>
                        )}
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-6">
                        <div className="space-y-2">
                            <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                                Forslagskø
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                                Pending forslag fra AI og validering
                            </h2>
                            <p className="max-w-3xl text-base leading-6 text-slate-600">
                                Godkjenn, rediger, slå sammen eller avvis forslag før de blir en del av det autoritative vokabularet.
                            </p>
                        </div>

                        {suggestions.length > 0 ? (
                            <div className="overflow-hidden rounded-[20px] border border-slate-200">
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Forslag
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Detaljer
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Kilde
                                                </th>
                                                <th className="px-5 py-3 text-right text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Handlinger
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white">
                                            {suggestions.map((suggestion) => {
                                                const mergeOptions = approvedTermsByType[suggestion.suggested_type] ?? [];
                                                const selectedMergeTarget = mergeTargets[suggestion.id] ?? suggestion.related_existing_term_id ?? (mergeOptions[0]?.id ?? null);
                                                const confidenceScore = suggestion.confidence_score;
                                                const confidenceLabel = confidenceScore === null || confidenceScore === undefined
                                                    ? '—'
                                                    : Number(confidenceScore).toFixed(2);

                                                return (
                                                    <tr key={suggestion.id} className="align-top">
                                                        <td className="px-5 py-4">
                                                            <div className="space-y-2">
                                                                <div className="font-semibold text-slate-950">
                                                                    {suggestion.suggested_canonical_name || suggestion.suggested_term}
                                                                </div>
                                                                <div className="text-base uppercase tracking-[0.12em] text-slate-600">
                                                                    {suggestion.suggested_type_label ?? suggestion.suggested_type}
                                                                </div>
                                                                {suggestion.suggested_synonyms?.length > 0 ? (
                                                                    <div className="flex flex-wrap gap-1.5">
                                                                        {suggestion.suggested_synonyms.map((synonym) => (
                                                                            <span
                                                                                key={`${suggestion.id}-${synonym}`}
                                                                                className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-base font-medium leading-6 text-slate-600"
                                                                            >
                                                                                {synonym}
                                                                            </span>
                                                                        ))}
                                                                    </div>
                                                                ) : (
                                                                    <div className="text-base text-slate-600">Ingen synonymer oppgitt.</div>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-5 py-4 text-base text-slate-600">
                                                            <div className="space-y-2">
                                                                <div>
                                                                    <span className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                        Beskrivelse
                                                                    </span>
                                                                    <div>{suggestion.suggested_description || '—'}</div>
                                                                </div>
                                                                <div>
                                                                    <span className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                        Begrunnelse
                                                                    </span>
                                                                    <div>{suggestion.reason || '—'}</div>
                                                                </div>
                                                                <div>
                                                                    <span className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                        Confidence
                                                                    </span>
                                                                    <div>
                                                                        {confidenceLabel}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-5 py-4 text-base text-slate-600">
                                                            <div className="space-y-2">
                                                                <div>
                                                                    <span className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                        Batch
                                                                    </span>
                                                                    <div>{suggestion.source_label ?? suggestion.batch_label}</div>
                                                                </div>
                                                                {suggestion.related_existing_term_label ? (
                                                                    <div className="inline-flex rounded-full bg-violet-100 px-3 py-1 text-base font-semibold text-violet-700">
                                                                        Sammen med: {suggestion.related_existing_term_label}
                                                                    </div>
                                                                ) : (
                                                                    <div className="text-base text-slate-600">Ny term</div>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-5 py-4">
                                                            <div className="flex flex-col gap-3">
                                                                <div className="flex flex-wrap gap-2">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => router.patch(suggestion.approve_url, {}, {
                                                                            preserveScroll: true,
                                                                            preserveState: true,
                                                                        })}
                                                                        className="inline-flex rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-base font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100"
                                                                    >
                                                                        Godkjenn
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => setEditingSuggestion(suggestion)}
                                                                        className="inline-flex rounded-xl border border-violet-200 bg-violet-50 px-3.5 py-2 text-base font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                                    >
                                                                        Rediger og godkjenn
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => router.patch(suggestion.reject_url, {}, {
                                                                            preserveScroll: true,
                                                                            preserveState: true,
                                                                        })}
                                                                        className="inline-flex rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                                    >
                                                                        Avvis
                                                                    </button>
                                                                </div>

                                                                <div className="space-y-2 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                                                                    <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                        Slå sammen
                                                                    </div>
                                                                    <div className="flex flex-col gap-2 sm:flex-row">
                                                                        <select
                                                                            value={selectedMergeTarget ?? ''}
                                                                            onChange={(event) => setMergeTargets((currentTargets) => ({
                                                                                ...currentTargets,
                                                                                [suggestion.id]: Number(event.target.value),
                                                                            }))}
                                                                            className="h-11 min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        >
                                                                            {mergeOptions.length > 0 ? (
                                                                                mergeOptions.map((term) => (
                                                                                    <option key={term.id} value={term.id}>
                                                                                        {term.canonical_name}
                                                                                    </option>
                                                                                ))
                                                                            ) : (
                                                                                <option value="">Ingen godkjente termer i samme type</option>
                                                                            )}
                                                                        </select>
                                                                        <button
                                                                            type="button"
                                                                            disabled={!selectedMergeTarget}
                                                                            onClick={() => router.patch(suggestion.merge_url, {
                                                                                existing_term_id: selectedMergeTarget,
                                                                            }, {
                                                                                preserveScroll: true,
                                                                                preserveState: true,
                                                                            })}
                                                                            className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                                        >
                                                                            Slå sammen
                                                                        </button>
                                                                    </div>
                                                                </div>
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
                            <div className="rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-base text-slate-600">
                                Ingen forslag ligger i køen ennå.
                            </div>
                        )}
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                                Batchhistorikk
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                                Seneste analyser
                            </h2>
                        </div>

                        {recentBatches.length > 0 ? (
                            <div className="overflow-hidden rounded-[20px] border border-slate-200">
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Batch
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Status
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Kilder
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Oppsummering
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Oppdatert
                                                </th>
                                                <th className="px-5 py-3 text-right text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Handlinger
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white">
                                            {recentBatches.map((batch) => (
                                                <tr key={batch.id}>
                                                    <td className="px-5 py-4">
                                                        <div className="font-semibold text-slate-950">
                                                            Batch #{batch.id}
                                                        </div>
                                                        <div className="text-base text-slate-600">
                                                            Opprettet av {batch.created_by ?? '—'}
                                                        </div>
                                                    </td>
                                                    <td className="px-5 py-4">
                                                        <span className={formatStatusBadgeClass(batch.status)}>
                                                            {batch.status_label}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-4 text-base text-slate-600">
                                                        <div className="space-y-1">
                                                            <div className="text-base uppercase tracking-[0.12em] text-slate-600">
                                                                {batch.source_document_count} dokumenter
                                                            </div>
                                                            <div>{(batch.source_documents ?? []).join(', ') || '—'}</div>
                                                        </div>
                                                    </td>
                                                    <td className="px-5 py-4 text-base text-slate-600">
                                                        {batch.summary || batch.error_message || '—'}
                                                    </td>
                                                    <td className="px-5 py-4 text-base text-slate-600">
                                                        {formatDateTime(batch.updated_at, locale)}
                                                    </td>
                                                    <td className="px-5 py-4 text-right">
                                                        {batch.delete_url ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    if (!window.confirm(`Slette batch #${batch.id}?`)) {
                                                                        return;
                                                                    }

                                                                    router.delete(batch.delete_url, {
                                                                        preserveScroll: true,
                                                                        preserveState: true,
                                                                    });
                                                                }}
                                                                className="inline-flex rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                            >
                                                                Slett
                                                            </button>
                                                        ) : (
                                                            <span className="text-base text-slate-600">—</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ) : (
                            <div className="rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-base text-slate-600">
                                Ingen batcher er registrert ennå.
                            </div>
                        )}
                    </div>
                </section>
            </div>

            <SuggestionEditModal
                suggestion={editingSuggestion}
                typeOptions={typeOptions}
                form={editForm}
                onClose={() => setEditingSuggestion(null)}
                onSubmit={submitEditAndApprove}
            />

            <ApprovedTermEditModal
                term={editingTerm}
                typeOptions={typeOptions}
                form={termEditForm}
                onClose={() => setEditingTerm(null)}
                onSubmit={submitTermEdit}
            />

            {editErrorMessage ? (
                <div className="fixed bottom-6 right-6 z-50 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-700 shadow-lg">
                    {editErrorMessage}
                </div>
            ) : null}
        </CustomerAppLayout>
    );
}
