import { useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import ActionDialog from '../../../../Components/App/ActionDialog';
import EmptyStateBox from '../../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { complianceHelp } from '../Requirements/complianceHelp';
import { activationText } from './complianceLibrary';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Etterlevelse og revisjon → Kravbibliotek: choosing standards. Which standards Procynia offers,
 * what each contains, and whether this customer has activated it.
 *
 * Not a status page: where the organisation stands is Etterlevelsesvurdering's, and the card only
 * leads there. A card for a standard that is not activated has «Se innholdet» to explore it and —
 * only for someone the server lets activate — «Aktiver for virksomheten», confirmed in a dialog that
 * says how many requirements land in the register. An activated card says how many of its
 * requirements are in the register, with «Åpne i kravregisteret» and «Til etterlevelsesvurdering»,
 * both filtered on that standard. The server re-sends the cards after activation, so the card
 * changes without a reload.
 */
export default function ComplianceLibraryIndex() {
    const { translations = {}, frameworks = [], permissions = {} } = usePage().props;
    const tr = translations?.compliance ?? {};
    const tl = tr.library ?? {};
    const [confirming, setConfirming] = useState(null);
    const [activating, setActivating] = useState(false);
    const confirmRef = useRef(null);

    const activate = () => {
        if (! confirming || activating) {
            return;
        }

        router.post(confirming.activate_url, {}, {
            preserveScroll: true,
            onStart: () => setActivating(true),
            onFinish: () => {
                setActivating(false);
                setConfirming(null);
            },
        });
    };

    return (
        <CustomerAppLayout title={tl.index_title ?? 'Kravbibliotek'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.module_name ?? 'Etterlevelse og revisjon'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">{tl.index_subtitle ?? ''}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...complianceHelp(tr, 'library')} />
                    </div>
                </header>

                <section className="grid gap-4 md:grid-cols-2" aria-label={tl.index_title ?? 'Kravbibliotek'} data-testid="compliance-library-explainer">
                    <div className="rounded-[24px] border border-violet-200 bg-violet-50 p-5">
                        <h2 className="text-lg font-semibold text-violet-900">{tl.explainer_library_title ?? 'Kravbibliotek'}</h2>
                        <p className="mt-1 text-base text-violet-900">{tl.explainer_library ?? ''}</p>
                    </div>
                    <div className="rounded-[24px] border border-slate-200 bg-white p-5">
                        <h2 className="text-lg font-semibold text-slate-950">
                            <Link href="/app/compliance/requirements" className="hover:text-violet-700">{tl.explainer_register_title ?? 'Kravregister'}</Link>
                        </h2>
                        <p className="mt-1 text-base text-slate-700">{tl.explainer_register ?? ''}</p>
                    </div>
                </section>

                {frameworks.length === 0 ? (
                    <EmptyStateBox title={tl.empty ?? 'Ingen kravpakker er tilgjengelige ennå.'} />
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="compliance-library-frameworks">
                        {frameworks.map((framework) => (
                            <li key={framework.id} className={`${CARD} flex flex-col gap-4`} data-testid={`compliance-library-framework-${framework.key}`}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 space-y-1">
                                        <p className="text-base font-semibold text-slate-600">{framework.label}</p>
                                        <h2 className="break-words text-xl font-semibold text-slate-950">{framework.name}</h2>
                                    </div>
                                    <StatusBadge tone={framework.activated ? 'emerald' : 'slate'}>
                                        {framework.activated ? (tl.activated ?? 'Aktivert') : (tl.not_activated ?? 'Ikke aktivert')}
                                    </StatusBadge>
                                </div>
                                <p className="text-base leading-6 text-slate-700">{framework.description}</p>
                                <p className="text-base text-slate-600" data-testid={`compliance-library-card-${framework.key}-count`}>
                                    {framework.activated
                                        ? (tl.register_count ?? ':count krav i kravregisteret').replace(':count', String(framework.register_count ?? 0))
                                        : (tl.requirement_count ?? ':count krav').replace(':count', String(framework.requirement_count))}
                                </p>
                                {! framework.content_verified && (
                                    <p><StatusBadge tone="amber">{tl.unverified_badge ?? 'Ikke faglig kvalitetssikret'}</StatusBadge></p>
                                )}
                                <div className="mt-auto flex flex-wrap gap-3">
                                    {framework.activated ? (
                                        <>
                                            <Link href={framework.assessments_url} className={PRIMARY_ACTION}>{tl.open_assessments ?? 'Til etterlevelsesvurdering'}</Link>
                                            <Link href={framework.register_url} className={SECONDARY_ACTION}>{tl.open_register ?? 'Åpne i kravregisteret'}</Link>
                                            <Link href={framework.url} className={SECONDARY_ACTION}>{tl.open_framework ?? 'Se innholdet'}</Link>
                                        </>
                                    ) : (
                                        <>
                                            <Link href={framework.url} className={permissions.can_activate ? SECONDARY_ACTION : PRIMARY_ACTION}>{tl.open_framework ?? 'Se innholdet'}</Link>
                                            {permissions.can_activate && (
                                                <button type="button" onClick={() => setConfirming(framework)} className={PRIMARY_ACTION}>
                                                    {tl.activate ?? 'Aktiver for virksomheten'}
                                                </button>
                                            )}
                                        </>
                                    )}
                                </div>
                                {! framework.activated && ! permissions.can_activate && (
                                    <p className="text-base text-slate-600">{tl.activate_no_permission ?? ''}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <ActionDialog
                    isOpen={confirming !== null}
                    onClose={() => setConfirming(null)}
                    closeDisabled={activating}
                    titleId="compliance-library-activate-title"
                    initialFocusRef={confirmRef}
                >
                    {confirming && (
                        <div data-testid="compliance-library-activate-dialog">
                            <h2 id="compliance-library-activate-title" className="text-xl font-semibold tracking-tight text-slate-950">
                                {(tl.activate_dialog_title ?? 'Aktiver :name?').replace(':name', confirming.reference)}
                            </h2>
                            <p className="mt-2 text-base leading-6 text-slate-600">{activationText(confirming, tl)}</p>
                            <div className="mt-6 flex flex-wrap justify-end gap-3">
                                <button type="button" onClick={() => setConfirming(null)} disabled={activating} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                                <button ref={confirmRef} type="button" onClick={activate} disabled={activating} className={PRIMARY_ACTION}>
                                    {activating ? (tl.activating ?? 'Aktiverer...') : (tl.activate ?? 'Aktiver for virksomheten')}
                                </button>
                            </div>
                        </div>
                    )}
                </ActionDialog>
            </div>
        </CustomerAppLayout>
    );
}
