import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';

/**
 * Kvalitet's landing page.
 *
 * The module is in the rail as a real destination, so it needs somewhere to land. Until the module
 * itself is built this says plainly what it will gather and sends people to the quality work that
 * already exists, rather than pretending to be a feature it is not.
 */
export default function QualityIndex() {
    const { translations = {} } = usePage().props;
    const tm = translations?.navigation?.modules ?? {};

    return (
        <CustomerAppLayout title={tm.quality ?? 'Kvalitet'}>
            <EmptyStateBox
                title={tm.quality_placeholder_title ?? 'Kvalitetsmodulen er under arbeid'}
                description={tm.quality_placeholder_body
                    ?? 'Her samles kvalitetssikring av krav, svar og dokumentasjon før tilbudet sendes. Kvalitetsarbeidet som finnes i dag ligger foreløpig i Wiki.'}
            >
                <div className="mt-5 flex flex-wrap justify-center gap-3">
                    <Link
                        href="/app/wiki"
                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-950"
                    >
                        {translations?.wiki?.nav ?? 'Wiki'}
                    </Link>
                </div>
            </EmptyStateBox>
        </CustomerAppLayout>
    );
}
