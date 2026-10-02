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
export default function ProcessFlowStepList({ tb, blueprint, onOpenSubprocess = null, onOpenActivity = null }) {
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

                        {step.subprocess && (
                            /* The same statement the pill on the diagram makes, in the view that
                               can actually be read aloud or by a screen reader. A step that is a
                               process of its own is not a detail of the picture. */
                            onOpenSubprocess ? (
                                <button
                                    type="button"
                                    className="mt-1 inline-flex items-center gap-1.5 rounded-lg border border-violet-200 bg-violet-50 px-2.5 py-1 text-sm font-semibold text-violet-700 transition hover:border-violet-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                                    onClick={() => onOpenSubprocess(step.subprocess, step)}
                                >
                                    {subprocessLabel(tb, step.subprocess)}
                                    <span aria-hidden="true">→</span>
                                </button>
                            ) : (
                                <p className="mt-1 text-sm font-medium text-violet-700">
                                    {subprocessLabel(tb, step.subprocess)}
                                </p>
                            )
                        )}

                        {(step.knowledge ?? []).length > 0 && (
                            /* The same count the diagram puts on the node, in the view that can be
                               read aloud and by a screen reader. It is the way into the activity's
                               knowledge whether or not the step also stands for a process — on the
                               diagram the box can only do one thing, and drilling in wins there. */
                            onOpenActivity ? (
                                <button
                                    type="button"
                                    className="mt-1 inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1 text-sm font-semibold text-sky-800 transition hover:border-sky-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600"
                                    onClick={() => onOpenActivity(step)}
                                    aria-label={(tb.knowledge_open ?? 'Vis kunnskapskildene for :label')
                                        .replace(':label', step.label ?? '')}
                                >
                                    {knowledgeLabel(tb, step.knowledge)}
                                </button>
                            ) : (
                                <p className="mt-1 text-sm font-medium text-sky-800">
                                    {knowledgeLabel(tb, step.knowledge)}
                                </p>
                            )
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

/** "2 kunnskapskilder" — a count, never the titles: see the diagram's KnowledgeMarker. */
function knowledgeLabel(tb, knowledge) {
    const template = knowledge.length === 1
        ? (tb.knowledge_count_one ?? ':count kunnskapskilde')
        : (tb.knowledge_count ?? ':count kunnskapskilder');

    return template.replace(':count', String(knowledge.length));
}

/** "Underprosess: Leverandørkontroll (6 steg)" — the name first, because that is what is clicked. */
function subprocessLabel(tb, subprocess) {
    const steps = Number(subprocess.step_count ?? 0);

    const name = (tb.subprocess_step ?? 'Underprosess: :title')
        .replace(':title', subprocess.title ?? '');

    return steps > 0
        ? `${name} (${(tb.subprocess_steps ?? ':count steg').replace(':count', String(steps))})`
        : name;
}
