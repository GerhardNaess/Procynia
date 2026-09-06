import { Link, useForm, usePage } from '@inertiajs/react';
import { PRIMARY_ACTION } from '../../../Support/actionStyles';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';

function getAskHelpSections(tw) {
    return [
        {
            title: tw.ask_page_help_section_basis ?? 'Hva svaret bygger på',
            items: [
                {
                    title: tw.ask_page_help_item_only_wiki_title ?? 'Kun gjeldende Wiki-innhold',
                    text: tw.ask_page_help_item_only_wiki_text
                        ?? 'Svaret bygger utelukkende på gjeldende versjoner av Wiki-sidene du har tilgang til. Arkiverte og erstattede sider brukes ikke.',
                },
                {
                    title: tw.ask_page_help_item_no_guessing_title ?? 'Manglende informasjon gjettes ikke',
                    text: tw.ask_page_help_item_no_guessing_text
                        ?? 'Finnes ikke svaret i Wiki-en, sier Procynia det uttrykkelig i stedet for å fylle inn antakelser eller generell bransjekunnskap.',
                },
                {
                    title: tw.ask_page_help_item_sources_title ?? 'Kildehenvisninger vises alltid',
                    text: tw.ask_page_help_item_sources_text
                        ?? 'Under svaret ser du hvilke Wiki-sider og avsnitt det bygger på, med et kort utdrag og lenke til siden.',
                },
            ],
        },
        {
            title: tw.ask_page_help_section_limits ?? 'Grenser og bruk',
            items: [
                {
                    title: tw.ask_page_help_item_conflict_title ?? 'Motstridende opplysninger flagges',
                    text: tw.ask_page_help_item_conflict_text
                        ?? 'Hvis to gjeldende sider sier ulike ting, velger Procynia ikke én av dem. Du får se at Wiki-en er motstridende, med begge kildene.',
                },
                {
                    title: tw.ask_page_help_item_not_bid_answer_title ?? 'Dette er ikke anbudssvargenerering',
                    text: tw.ask_page_help_item_not_bid_answer_text
                        ?? 'Spør Wiki gir korte, direkte svar om egen dokumentert kunnskap. Den skriver ikke tilbudstekst og kobles ikke til en sak.',
                },
                {
                    title: tw.ask_page_help_item_single_question_title ?? 'Ett spørsmål om gangen',
                    text: tw.ask_page_help_item_single_question_text
                        ?? 'Denne første versjonen svarer på ett spørsmål av gangen, uten samtalehistorikk.',
                },
            ],
        },
    ];
}

export default function Ask({ question = null, result = null, maxQuestionLength = 500 }) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};

    const form = useForm({ question: question ?? '' });

    const submit = (event) => {
        event.preventDefault();

        form.post('/app/wiki/ask', {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const status = result?.answer_status ?? null;
    const citations = result?.citations ?? [];

    return (
        <CustomerAppLayout title={tw.ask_title ?? 'Spør Wiki'}>
            <div className="mx-auto max-w-[900px] space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold text-slate-950">{tw.ask_title ?? 'Spør Wiki'}</h1>
                        <p className="max-w-[640px] text-base text-slate-600">
                            {tw.ask_description
                                ?? 'Still spørsmål til kunnskapen i Enterprise Wiki. Svarene baseres kun på gjeldende Wiki-innhold.'}
                        </p>
                    </div>

                    <PageHelpButton
                        buttonLabel={tw.ask_page_help_button ?? 'Hjelp'}
                        title={tw.ask_page_help_title ?? 'Slik fungerer Spør Wiki'}
                        intro={tw.ask_page_help_intro}
                        sections={getAskHelpSections(tw)}
                    />
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div className="space-y-2">
                        <label htmlFor="wiki-question" className="block text-base font-semibold text-slate-900">
                            {tw.ask_question_label ?? 'Spørsmål'}
                        </label>
                        <textarea
                            id="wiki-question"
                            name="question"
                            rows={3}
                            maxLength={maxQuestionLength}
                            value={form.data.question}
                            onChange={(event) => form.setData('question', event.target.value)}
                            placeholder={tw.ask_question_placeholder ?? 'Skriv et spørsmål om kunnskapen i Wiki-en...'}
                            className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-base text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                        />
                        {form.errors.question ? (
                            <p className="text-sm font-medium text-rose-600">{form.errors.question}</p>
                        ) : null}
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={form.processing || form.data.question.trim() === ''}
                            className={`${PRIMARY_ACTION} gap-2 shadow-sm`}
                        >
                            <svg className="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <circle cx="9" cy="9" r="5.25" stroke="currentColor" strokeWidth="1.75" />
                                <path d="M13 13L17 17" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
                            </svg>
                            {tw.ask_submit ?? 'Still spørsmål'}
                        </button>

                        {form.processing ? (
                            <span className="text-base text-slate-600">{tw.ask_searching ?? 'Søker i Wiki-en...'}</span>
                        ) : null}
                    </div>
                </form>

                {result && !form.processing ? (
                    <div className="space-y-4">
                        {status === 'insufficient_evidence' ? (
                            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                                <p className="text-base font-medium text-amber-900">
                                    {tw.ask_insufficient_evidence
                                        ?? 'Jeg finner ikke tilstrekkelig dokumentert informasjon i Wiki-en til å svare sikkert på dette.'}
                                </p>
                                {result.answer ? (
                                    <p className="mt-2 whitespace-pre-line text-base text-amber-900/90">{result.answer}</p>
                                ) : null}
                            </div>
                        ) : null}

                        {status === 'conflicting_evidence' ? (
                            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-5">
                                <p className="text-base font-semibold text-rose-900">
                                    {tw.ask_conflicting_evidence
                                        ?? 'Wiki-en inneholder motstridende opplysninger om dette spørsmålet.'}
                                </p>
                                {result.answer ? (
                                    <p className="mt-2 whitespace-pre-line text-base text-rose-900/90">{result.answer}</p>
                                ) : null}
                            </div>
                        ) : null}

                        {status === 'answered' ? (
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <h2 className="text-base font-semibold uppercase tracking-[0.08em] text-slate-500">
                                    {tw.ask_answer_heading ?? 'Svar'}
                                </h2>
                                <p className="mt-2 whitespace-pre-line text-lg leading-relaxed text-slate-900">
                                    {result.answer}
                                </p>
                            </div>
                        ) : null}

                        <div className="space-y-3">
                            <h2 className="text-base font-semibold uppercase tracking-[0.08em] text-slate-500">
                                {tw.ask_sources_heading ?? 'Kilder'}
                            </h2>

                            {citations.length === 0 ? (
                                <p className="text-base text-slate-600">
                                    {tw.ask_no_sources ?? 'Ingen Wiki-kilder ble brukt for dette svaret.'}
                                </p>
                            ) : (
                                <ul className="space-y-3">
                                    {citations.map((citation, index) => (
                                        <li key={`${citation.page_slug}-${index}`}>
                                            <Link
                                                href={`/app/wiki/${citation.page_slug}`}
                                                className="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-violet-300 hover:shadow-md"
                                            >
                                                <div className="flex items-baseline justify-between gap-3">
                                                    <span className="text-base font-semibold text-violet-700">
                                                        {citation.page_title}
                                                    </span>
                                                    <span className="shrink-0 text-sm text-slate-500">
                                                        {tw.ask_open_page ?? 'Åpne Wiki-siden'}
                                                    </span>
                                                </div>

                                                {citation.heading ? (
                                                    <div className="mt-1 text-sm font-medium text-slate-600">
                                                        {citation.heading}
                                                    </div>
                                                ) : null}

                                                {citation.excerpt ? (
                                                    <p className="mt-2 border-l-2 border-slate-200 pl-3 text-base italic text-slate-600">
                                                        {citation.excerpt}
                                                    </p>
                                                ) : null}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                ) : null}
            </div>
        </CustomerAppLayout>
    );
}
