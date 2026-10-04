import { SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

export const TREATMENT_EXPLANATIONS = {
    avoid: 'Fjerne aktiviteten eller forholdet som skaper risikoen.',
    reduce: 'Redusere sannsynlighet eller konsekvens.',
    share: 'Dele eller overføre deler av risikoen til en annen part.',
    accept: 'Beholde risikoen etter en eksplisitt beslutning.',
};

export const TREATMENT_LABELS = {
    avoid: 'Unngå',
    reduce: 'Redusere',
    share: 'Dele / overføre',
    accept: 'Akseptere',
};

/**
 * Behandling: the direction chosen for the risk. It is only a direction — tiltak and formal
 * acceptance live in their own panels. When the direction is «Akseptere», the page says whether the
 * residual risk is actually accepted, from the server's acceptance decision (a current acceptance that
 * has not expired); choosing the direction never makes it so.
 *
 * «Endre» (given only with risk.edit) opens the risk's own edit form at Behandlingsvalg — there is no
 * separate form for the direction.
 */
export default function RiskTreatmentStrategy({ strategy, decision, onEdit = null, tr }) {
    const tt = tr.treatment_strategy ?? {};
    const current = decision?.current ?? null;
    const formallyAccepted = Boolean(current && ! current.is_expired);

    return (
        <section className={CARD} aria-labelledby="risk-treatment-strategy-heading">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <h2 id="risk-treatment-strategy-heading" className="text-lg font-semibold text-slate-950">{tt.heading ?? 'Behandling'}</h2>
                {onEdit && (
                    <button type="button" onClick={onEdit} className={SECONDARY_ACTION}>
                        {tt.edit ?? 'Endre'}
                    </button>
                )}
            </div>
            {strategy ? (
                <div className="mt-3 space-y-2">
                    <p className="text-base text-slate-900">
                        <span className="font-semibold">{tt.options?.[strategy] ?? TREATMENT_LABELS[strategy] ?? strategy}</span>
                        <span className="text-slate-600"> – {tt.explanations?.[strategy] ?? TREATMENT_EXPLANATIONS[strategy]}</span>
                    </p>
                    {strategy === 'accept' && (
                        <p className={`rounded-xl px-3 py-2 text-sm ${formallyAccepted ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}>
                            {formallyAccepted
                                ? (tt.accept_formal ?? 'Restrisikoen er formelt akseptert — se Aksept av restrisiko.')
                                : (tt.accept_pending ?? '«Akseptere» er valgt som retning. Restrisikoen er ikke akseptert før beslutningen er registrert under Aksept av restrisiko.')}
                        </p>
                    )}
                </div>
            ) : (
                <p className="mt-3 text-base text-slate-600">{tt.not_decided ?? 'Behandling er ikke besluttet ennå.'}</p>
            )}
        </section>
    );
}
