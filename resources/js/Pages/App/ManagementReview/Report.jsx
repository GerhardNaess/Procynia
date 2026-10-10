import { Head, Link, usePage } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

/**
 * The review as one readable document (plan §11) — made for printing, and the same document the PDF
 * renders (ManagementReviewReportBuilder): every word was chosen on the server, and every part the
 * reader may not see was left out there. Standalone, without the app's navigation, so what prints is
 * the document and nothing else.
 */
export default function ManagementReviewReport() {
    const { translations = {}, document, back_url: backUrl, pdf_url: pdfUrl } = usePage().props;
    const t = translations?.management_review ?? {};
    const labels = document.labels ?? {};

    return (
        <div className="min-h-screen bg-slate-100 print:bg-white">
            <Head title={document.title} />
            <div className="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-4 py-3 print:hidden">
                <div className="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-2">
                    <Link href={backUrl} className="text-base font-semibold text-violet-700 hover:text-violet-900">← {t.back ?? 'Til ledelsens gjennomgåelse'}</Link>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => window.print()} className={SECONDARY_ACTION} data-testid="mr-print">{t.print ?? 'Skriv ut'}</button>
                        <a href={pdfUrl} className={PRIMARY_ACTION} data-testid="mr-download-pdf">{t.download_pdf ?? 'Last ned PDF'}</a>
                    </div>
                </div>
            </div>

            <main>
            <article className="mx-auto my-6 max-w-4xl space-y-6 bg-white px-4 py-8 text-base leading-7 text-slate-900 shadow-sm sm:px-10 print:my-0 print:max-w-none print:px-0 print:py-0 print:shadow-none" data-testid="mr-report">
                {document.is_draft && <p className="text-lg font-bold text-amber-800" data-testid="mr-report-draft">{labels.draft_banner}</p>}
                <header className="space-y-2">
                    <h1 className="break-words text-3xl font-semibold text-slate-950">{document.title}</h1>
                    <p className="text-slate-700">{document.status} · {document.basis_note}</p>
                    <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-[12rem_1fr]">
                        {document.meta.map(([label, value]) => (
                            <div key={label} className="contents">
                                <dt className="text-slate-600">{label}</dt>
                                <dd className="break-words">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    {document.restricted_note && <p className="rounded-xl bg-slate-100 px-4 py-3 text-slate-800 print:border print:border-slate-300" data-testid="mr-report-restricted">{document.restricted_note}</p>}
                </header>

                {document.purpose && <Block title={labels.purpose}><p className="whitespace-pre-line break-words">{document.purpose}</p></Block>}

                <Block title={labels.participants}>
                    {document.participants.length === 0 ? <p className="text-slate-600">{labels.none}</p> : (
                        <ul>{document.participants.map((participant) => <li key={participant.id}>{participant.name}{participant.role_label ? ` – ${participant.role_label}` : ''}</li>)}</ul>
                    )}
                </Block>

                <Block title={labels.conclusion}><p className="whitespace-pre-line break-words">{document.conclusion || labels.none}</p></Block>

                {document.sections.map((section) => (
                    <section key={section.key} className="space-y-3 break-inside-avoid-page" data-testid={`mr-report-section-${section.key}`}>
                        <h2 className="border-b border-slate-300 pb-1 text-xl font-semibold text-slate-950">{section.title}</h2>
                        {section.state_text && <p className="text-slate-600">{section.state_text}</p>}
                        {section.coverage_text && <p className="text-slate-600">{section.coverage_text}</p>}
                        {section.notes && <p className="whitespace-pre-line break-words">{section.notes}</p>}
                        {section.basis && ! section.basis.empty && (
                            <>
                                {section.basis.groups.map((group) => (
                                    <div key={group.key}>
                                        <h3 className="font-semibold text-slate-700">{group.label}</h3>
                                        <table className="mt-1 w-full">
                                            <tbody>
                                                {group.metrics.map((metric) => (
                                                    <tr key={metric.key} className="border-b border-slate-100">
                                                        <td className="py-1 pr-4">{metric.label}</td>
                                                        <td className="py-1 text-right font-semibold">{metric.value}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ))}
                                {section.basis.lists.filter((list) => list.rows.length > 0).map((list) => (
                                    <div key={list.key}>
                                        <h3 className="font-semibold text-slate-700">{list.label} ({list.total})</h3>
                                        <ul className="mt-1 space-y-1">
                                            {list.rows.map((row) => (
                                                <li key={`${list.key}-${row.id}`} className="break-words">
                                                    <span className="font-semibold">{row.title}</span>
                                                    {list.columns.filter((column) => row.cells?.[column.key]).map((column) => ` · ${column.label}: ${row.cells[column.key]}`).join('')}
                                                    {row.case && <span className="block text-slate-600">{labels.case}: {row.case.title} – {Object.values(row.case.cells ?? {}).filter(Boolean).join(' · ')}</span>}
                                                    {row.case_hidden && <span className="block text-slate-600">{labels.case_hidden}</span>}
                                                </li>
                                            ))}
                                        </ul>
                                        {list.total > list.shown && <p className="text-slate-600">{(labels.truncated ?? '').replace(':shown', list.shown).replace(':total', list.total)}</p>}
                                    </div>
                                ))}
                                {section.basis.notes.map((note) => <p key={note} className="text-slate-600">{note}</p>)}
                            </>
                        )}
                        <p><span className="font-semibold">{labels.judgement}:</span> {section.judgement ?? labels.not_judged}</p>
                        {section.comment && <p className="whitespace-pre-line break-words">{section.comment}</p>}
                    </section>
                ))}

                <Block title={labels.decisions}>
                    {document.decisions.length === 0 ? <p className="text-slate-600">{labels.none}</p> : (
                        <ul className="space-y-3">
                            {document.decisions.map((decision) => (
                                <li key={decision.id} className="break-words">
                                    <span className="font-semibold">{decision.kind}:</span> {decision.text}
                                    <span className="block text-slate-600">
                                        {[decision.section, decision.owner && `${labels.owner}: ${decision.owner}`, decision.due_date && `${labels.due}: ${decision.due_date}`, decision.follow_up].filter(Boolean).join(' · ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Block>

                {document.frameworks.map((framework) => (
                    <Block key={framework.name} title={framework.name}>
                        {framework.coverage === 'none' ? <p className="text-slate-600">{labels.framework_no_coverage}</p> : (
                            <>
                                <p className="text-slate-600">{labels.framework_disclaimer}</p>
                                <ul className="mt-1">
                                    {framework.inputs.map((input) => <li key={`${input.clause}-${input.label}`}>{input.clause} {input.label}: <span className="font-semibold">{input.state}</span></li>)}
                                </ul>
                            </>
                        )}
                    </Block>
                ))}

                {document.amendments.length > 0 && (
                    <Block title={labels.amendments}>
                        {document.amendments.map((amendment) => (
                            <div key={`${amendment.at}-${amendment.text}`} className="mb-2">
                                <p className="whitespace-pre-line break-words">{amendment.text}</p>
                                <p className="text-slate-600">{labels.reason}: {amendment.reason} · {amendment.by} · {amendment.at}</p>
                            </div>
                        ))}
                    </Block>
                )}

                <Block title={labels.history}>
                    <ul>{document.history.map((event, index) => <li key={`${event.at}-${index}`}>{event.at} – {event.event} – {event.by}</li>)}</ul>
                </Block>

                <p className="text-slate-600">{labels.generated} {document.generated_at}</p>
            </article>
            </main>
        </div>
    );
}

function Block({ title, children }) {
    return (
        <section className="space-y-2">
            <h2 className="border-b border-slate-300 pb-1 text-xl font-semibold text-slate-950">{title}</h2>
            {children}
        </section>
    );
}
