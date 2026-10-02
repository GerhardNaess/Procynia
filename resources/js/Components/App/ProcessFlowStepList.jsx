import { flowReadingOrder } from '../../Support/processBlueprintLayout';

/**
 * The flow as text: who does what, in order.
 *
 * It is not a second representation to keep in step with the diagram — it is the same model read a
 * different way, through the same layout function, so the numbering cannot disagree with the
 * picture above it. See flowReadingOrder().
 *
 * It exists because a diagram is checkable only by someone who already understands it. A process
 * owner verifying that Procynia read their description correctly does that far faster down a list
 * of sentences than across a swimlane, and the list is also what survives being read aloud in a
 * review meeting.
 */
export default function ProcessFlowStepList({ tb, blueprint }) {
    const steps = flowReadingOrder(blueprint);

    if (steps.length === 0) {
        return <p className="text-sm text-slate-500">{tb.steps_list_empty ?? 'Flyten har ingen steg.'}</p>;
    }

    const typeLabels = tb.node_types ?? {};

    return (
        <ol className="space-y-3">
            {steps.map((step, index) => (
                <li key={step.key} className="flex gap-4">
                    <span
                        aria-hidden="true"
                        className="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600"
                    >
                        {index + 1}
                    </span>

                    <div className="min-w-0">
                        {/* The role, or what kind of thing this is when no role performs it. A
                            decision is attributed to whoever decides, so it keeps its role and
                            gains the word "Beslutning" rather than losing one for the other. */}
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {[
                                step.type === 'decision' ? (tb.step_decision ?? 'Beslutning') : null,
                                step.type === 'start' ? (typeLabels.start ?? 'Start') : null,
                                step.type === 'end' ? (typeLabels.end ?? 'Slutt') : null,
                                step.role,
                            ].filter(Boolean).join(' · ')}
                        </p>

                        <p className="text-base leading-6 text-slate-900">{step.label}</p>

                        {step.description && (
                            <p className="mt-1 text-sm leading-5 text-slate-600">{step.description}</p>
                        )}

                        {step.outcomes.length > 0 && (
                            <p className="mt-1 text-sm text-slate-500">
                                {(tb.step_outcomes ?? 'Utfall: :outcomes').replace(':outcomes', step.outcomes.join(' / '))}
                            </p>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}
