import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';

function normalizeInstructionsText(value) {
    return String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

export default function AiInstructions({
    pageTitle = 'AI instrukser',
    case: caseData = null,
    ai_instructions: aiInstructionsProp = '',
    ai_instructions_update_url: aiInstructionsUpdateUrl = '',
}) {
    const { translations = {} } = usePage().props;
    const tai = translations?.ai ?? {};

    const form = useForm({
        ai_instructions: normalizeInstructionsText(aiInstructionsProp),
    });

    useEffect(() => {
        form.setData('ai_instructions', normalizeInstructionsText(aiInstructionsProp));
        form.clearErrors();
    }, [caseData?.id, aiInstructionsProp]);

    const submit = (event) => {
        event.preventDefault();

        if (!aiInstructionsUpdateUrl || form.processing) {
            return;
        }

        const normalizedInstructions = normalizeInstructionsText(form.data.ai_instructions);

        form.patch(aiInstructionsUpdateUrl, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setData('ai_instructions', normalizedInstructions);
                form.clearErrors();
            },
        });
    };

    const errorMessage = Object.values(form.errors).find(Boolean) ?? null;

    return (
        <CustomerAppLayout title="" showPageTitle={false}>
            <Head title={pageTitle} />

            <div className="mx-auto max-w-4xl">
                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-5">
                        <div className="space-y-1">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                AI instrukser
                            </div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-2xl font-semibold tracking-tight text-slate-950">
                                    AI instrukser
                                </h1>
                                <PageHelpButton
                                    buttonLabel={tai.instructions_page_help_button ?? 'Hjelp'}
                                    title={tai.instructions_page_help_title ?? 'AI instrukser'}
                                    intro={tai.instructions_page_help_intro}
                                    sections={[
                                        {
                                            title: tai.instructions_page_help_section_what ?? 'Hva er AI-instrukser?',
                                            items: [
                                                {
                                                    title: tai.instructions_page_help_item_what_title ?? 'Kundespesifikke føringer',
                                                    text: tai.instructions_page_help_item_what_text ?? 'AI-instrukser er faste regler som tas med når AI-en lager forslag til kravsvar, oppsummeringer og andre tekstutkast.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tai.instructions_page_help_section_how ?? 'Hva bør du skrive?',
                                            items: [
                                                {
                                                    title: tai.instructions_page_help_item_write_title ?? 'Korte og konkrete regler',
                                                    text: tai.instructions_page_help_item_write_text ?? 'Beskriv hvilke ord og begreper som skal brukes, hvilke formuleringer som skal unngås, og hvilken stil svarene skal ha.',
                                                },
                                                {
                                                    title: tai.instructions_page_help_item_avoid_title ?? 'Ikke legg inn sensitive data',
                                                    text: tai.instructions_page_help_item_avoid_text ?? 'Ikke legg inn passord, hemmeligheter, sensitive personopplysninger eller informasjon som ikke skal brukes i AI-genererte svar.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tai.instructions_page_help_section_example ?? 'Eksempel',
                                            items: [
                                                {
                                                    title: tai.instructions_page_help_item_example_title ?? 'Konkret eksempel på instrukser',
                                                    text: tai.instructions_page_help_item_example_text ?? 'Skriv svar på norsk bokmål i en formell og presis tone. Bruk betegnelsen Kunde om oppdragsgiver og Leverandør om oss. Unngå uforpliktende formuleringer som "kan", "bør" og "vi vil forsøke". Svar skal være konkrete, forpliktende og egnet for offentlige anbud.',
                                                },
                                            ],
                                        },
                                    ]}
                                />
                            </div>
                        </div>

                        <form onSubmit={submit} className="space-y-4">
                            <label className="block space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    Instruksjoner
                                </span>
                                <textarea
                                    value={form.data.ai_instructions}
                                    onChange={(event) => form.setData('ai_instructions', event.target.value)}
                                    rows={16}
                                    disabled={form.processing || !aiInstructionsUpdateUrl}
                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    placeholder="Skriv faste regler for tone, stil, terminologi og kapitalisering."
                                />
                                {errorMessage ? (
                                    <p className="text-sm text-rose-600">{errorMessage}</p>
                                ) : null}
                            </label>

                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    disabled={!aiInstructionsUpdateUrl || form.processing}
                                    className="inline-flex items-center justify-center rounded-full bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {form.processing ? 'Lagrer...' : 'Lagre endring'}
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </CustomerAppLayout>
    );
}
