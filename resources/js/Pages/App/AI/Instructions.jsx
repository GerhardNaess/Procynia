import { Head, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function normalizeInstructionsText(value) {
    return String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

export default function AiInstructions({
    pageTitle = 'AI instrukser',
    case: caseData = null,
    ai_instructions: aiInstructionsProp = '',
    ai_instructions_update_url: aiInstructionsUpdateUrl = '',
}) {
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
                            <h1 className="text-2xl font-semibold tracking-tight text-slate-950">
                                AI instrukser
                            </h1>
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
                                    className="inline-flex items-center justify-center rounded-full bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
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
