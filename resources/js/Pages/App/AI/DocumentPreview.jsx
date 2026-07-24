import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function formatFileSize(bytes) {
    const value = Number(bytes ?? 0);

    if (!Number.isFinite(value) || value <= 0) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = value;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex += 1;
    }

    return `${size >= 10 || unitIndex === 0 ? Math.round(size) : size.toFixed(1)} ${units[unitIndex]}`;
}

export default function DocumentPreview({
    pageTitle = 'Kilde',
    case: caseData = null,
    document = null,
    back_url: backUrl = '',
}) {
    const previewMode = document?.preview_mode ?? 'unavailable';
    const previewTitle = document?.original_filename ?? 'Kildedokument';
    const previewUrl = document?.preview_file_url ?? null;
    const caseTitle = caseData?.title ?? 'AI-sak';

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-6">
                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="space-y-2">
                            <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                                Kildevisning
                            </div>
                            <h1 className="text-3xl font-semibold tracking-tight text-slate-950">
                                {previewTitle}
                            </h1>
                            <p className="max-w-3xl text-base leading-6 text-slate-600">
                                Forhåndsvisning for {caseTitle}. PDF vises i en kontrollert visning her i appen, uavhengig av hvordan nettleseren håndterer originalfilen.
                            </p>
                        </div>

                        {backUrl ? (
                            <a
                                href={backUrl}
                                className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-base font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                Tilbake til AI-sak
                            </a>
                        ) : null}
                    </div>
                </section>

                <section className="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.6fr)]">
                    <div className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-base font-semibold uppercase tracking-[0.12em] leading-6 text-slate-600">
                                {previewMode === 'pdf' ? 'PDF-forhåndsvisning' : 'Forhåndsvisning ikke tilgjengelig'}
                            </span>
                            <span className="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-base font-semibold leading-6 text-slate-600">
                                {document?.mime_type ?? '—'}
                            </span>
                            <span className="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-base font-semibold leading-6 text-slate-600">
                                {formatFileSize(document?.file_size_bytes)}
                            </span>
                        </div>

                        <div className="mt-4">
                            {previewMode === 'pdf' && previewUrl ? (
                                <iframe
                                    title={previewTitle}
                                    src={previewUrl}
                                    className="h-[78vh] w-full rounded-2xl border border-slate-200 bg-slate-50"
                                />
                            ) : (
                                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                    <div className="text-lg font-semibold text-slate-900">
                                        Forhåndsvisning er ikke tilgjengelig.
                                    </div>
                                    <p className="mt-2 text-base leading-6 text-slate-600">
                                        Dokumentet mangler en forhåndsvisbar form. Originalfilen er fortsatt lagret som kildedokument.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    <aside className="space-y-5">
                        <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <h2 className="text-base font-semibold tracking-tight text-slate-950">
                                Dokumentdetaljer
                            </h2>
                            <dl className="mt-4 space-y-3 text-base leading-6">
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-600">Filnavn</dt>
                                    <dd className="text-right font-medium text-slate-950">{document?.original_filename ?? '—'}</dd>
                                </div>
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-600">MIME-type</dt>
                                    <dd className="text-right font-medium text-slate-950">{document?.mime_type ?? '—'}</dd>
                                </div>
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-600">Størrelse</dt>
                                    <dd className="text-right font-medium text-slate-950">{document?.file_size_human ?? '—'}</dd>
                                </div>
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-600">Tekst ekstrahert</dt>
                                    <dd className="text-right font-medium text-slate-950">
                                        {document?.has_extracted_text ? 'Ja' : 'Nei'}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <h2 className="text-base font-semibold tracking-tight text-slate-950">
                                Om visningen
                            </h2>
                            <p className="mt-3 text-base leading-6 text-slate-600">
                                Denne visningen bruker en generert PDF-forhåndsvisning når dokumentet kan vises.
                                Det betyr at du ikke er avhengig av at nettleseren åpner Word direkte.
                            </p>
                        </section>
                    </aside>
                </section>
            </div>
        </CustomerAppLayout>
    );
}
