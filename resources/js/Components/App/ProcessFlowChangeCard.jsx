import ProcessSwimlaneDiagram from './ProcessSwimlaneDiagram';
import { describeChange } from '../../Support/processFlowChanges';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TEXTAREA = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base leading-6 text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

const BADGE_STYLES = {
    add: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    update: 'border-sky-200 bg-sky-50 text-sky-800',
    remove: 'border-rose-200 bg-rose-50 text-rose-800',
};

/**
 * Asking for a change to a flow that exists, and reading what Procynia proposes.
 *
 * The proposal is a list before it is a picture. A process owner who asked for "en
 * sikkerhetskontroll før godkjenning" needs to see that one activity is added and one arrow is
 * rerouted — and, as importantly, that nothing else is touched. A redrawn diagram alone would make
 * them compare two pictures to find out. The diagram of the result comes after the list, as a check
 * of what the list says.
 *
 * Asking writes nothing, and discarding is hiding: the proposal lives in the flash of the visit
 * that produced it and is gone on the next one anyway. "Godta endringer" is the one write, and it
 * takes the whole list — the server re-applies it to the working version it was made against, or
 * refuses because that version has moved on. Unsaved edits block it rather than being thrown away
 * by the reload that follows.
 */
export default function ProcessFlowChangeCard({
    tb,
    itemTitle,
    value,
    setValue,
    onSubmit,
    onDiscard,
    onAccept,
    busy = false,
    accepting = false,
    error = null,
    changeError = null,
    proposal = null,
    hasUnsavedChanges = false,
}) {
    const changes = proposal?.changes ?? [];
    const questions = proposal?.questions ?? [];

    return (
        <section className={CARD} aria-labelledby="process-flow-change-heading">
            <h2 id="process-flow-change-heading" className="text-xl font-semibold text-slate-950">
                {tb.change_heading ?? 'Endre prosessen med AI'}
            </h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                {tb.change_help ?? 'Skriv med vanlige ord hva som skal endres i flyten.'}
            </p>

            <label className="mt-4 block space-y-1">
                <span className="block text-sm font-semibold text-slate-700">
                    {tb.change_label ?? 'Hva skal endres?'}
                </span>
                <textarea
                    className={TEXTAREA}
                    rows={3}
                    value={value}
                    placeholder={tb.change_placeholder ?? ''}
                    aria-invalid={error ? 'true' : undefined}
                    onChange={(event) => setValue(event.target.value)}
                    disabled={busy}
                />
            </label>

            {error && <p className="mt-2 text-sm font-medium text-rose-700">{error}</p>}

            <div className="mt-4 flex flex-wrap items-center gap-3">
                {/* No arguments on purpose — see InterpretCard for what the click event does to an
                    Inertia payload. */}
                <button type="button" className={PRIMARY_ACTION} onClick={() => onSubmit()} disabled={busy}>
                    {busy ? (tb.change_working ?? 'Lager endringsforslag …') : (tb.change_submit ?? 'Foreslå endringer')}
                </button>
            </div>

            {changeError && ! proposal && (
                <div role="alert" className="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-base text-amber-900">
                    <p>{changeError.message}</p>
                    {(changeError.problems ?? []).length > 0 && (
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            {changeError.problems.map((problem) => <li key={problem}>{problem}</li>)}
                        </ul>
                    )}
                </div>
            )}

            {proposal && (
                <div className="mt-6 space-y-5 border-t border-slate-100 pt-5" data-testid="flow-change-proposal">
                    <div>
                        <h3 className="text-lg font-semibold text-slate-950">
                            {tb.change_proposal_heading ?? 'Foreslåtte endringer'}
                        </h3>
                        <p className="mt-1 text-base leading-6 text-slate-600">
                            {tb.change_proposal_intro ?? 'Ingenting er endret i prosessen.'}
                        </p>
                    </div>

                    <dl className="rounded-2xl bg-slate-50 p-4 text-base leading-6">
                        <dt className="text-sm font-semibold text-slate-500">{tb.change_instruction_label ?? 'Instruks'}</dt>
                        <dd className="mt-1 text-slate-900">{proposal.instruction}</dd>
                        {proposal.summary && <dd className="mt-2 text-slate-700">{proposal.summary}</dd>}
                    </dl>

                    {hasUnsavedChanges && (
                        <p className="text-sm text-slate-600">
                            {tb.change_unsaved_notice ?? 'Forslaget er laget ut fra den lagrede arbeidsversjonen.'}
                        </p>
                    )}

                    {questions.length > 0 && (
                        <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-base text-amber-900">
                            <p className="font-semibold">{tb.change_questions_heading ?? 'Dette må avklares før endringen kan foreslås'}</p>
                            <ul className="mt-2 list-disc space-y-1 pl-5">
                                {questions.map((question) => <li key={question}>{question}</li>)}
                            </ul>
                        </div>
                    )}

                    {changes.length > 0 ? (
                        <div>
                            <h4 className="text-base font-semibold text-slate-900">{tb.change_list_heading ?? 'Dette vil endres'}</h4>
                            <ol className="mt-3 space-y-2">
                                {changes.map((change, index) => {
                                    const line = describeChange(change, tb);

                                    return (
                                        <li key={index} className="flex flex-wrap items-start gap-3 rounded-xl border border-slate-200 p-3">
                                            <span className={`inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-sm font-semibold ${BADGE_STYLES[line.badge]}`}>
                                                {tb[`change_badge_${line.badge}`] ?? line.badge}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-base font-medium text-slate-900">{line.title}</p>
                                                {line.details.map((detail) => (
                                                    <p key={detail} className="mt-0.5 text-sm text-slate-600">{detail}</p>
                                                ))}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ol>
                        </div>
                    ) : questions.length === 0 && (
                        <p className="text-base text-slate-600">{tb.change_none ?? 'Procynia fant ingen endring å foreslå ut fra instruksen.'}</p>
                    )}

                    {changes.length > 0 && (
                        <div>
                            <h4 className="text-base font-semibold text-slate-900">{tb.change_preview_heading ?? 'Slik blir flyten med endringene'}</h4>
                            <div className="mt-3">
                                {/* Read-only on purpose: no edit, insert or drill-down handlers. This is
                                    a picture of a flow that does not exist yet. */}
                                <ProcessSwimlaneDiagram
                                    blueprint={{ lanes: proposal.lanes ?? [], nodes: proposal.nodes ?? [], edges: proposal.edges ?? [] }}
                                    title={`${tb.change_preview_heading ?? 'Slik blir flyten med endringene'} — ${itemTitle}`}
                                    emptyText={tb.diagram_empty ?? 'Flyten har ingen steg å tegne.'}
                                    tb={tb}
                                />
                            </div>
                        </div>
                    )}

                    {changes.length > 0 && (
                        <p className="text-sm text-slate-600">
                            {tb.change_accept_help ?? 'Alle endringene i listen legges inn i arbeidsversjonen samlet. Ingenting publiseres.'}
                        </p>
                    )}

                    {changes.length > 0 && hasUnsavedChanges && (
                        <p role="status" className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                            {tb.change_accept_unsaved ?? 'Du har ulagrede endringer i strukturen. Forkast dem før du godtar forslaget.'}
                        </p>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        {changes.length > 0 && (
                            <button
                                type="button"
                                className={PRIMARY_ACTION}
                                onClick={() => onAccept()}
                                disabled={busy || hasUnsavedChanges}
                            >
                                {accepting ? (tb.change_accepting ?? 'Legger inn endringene …') : (tb.change_accept ?? 'Godta endringer')}
                            </button>
                        )}
                        <button type="button" className={SECONDARY_ACTION} onClick={onDiscard} disabled={busy}>
                            {tb.change_discard ?? 'Forkast forslaget'}
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
