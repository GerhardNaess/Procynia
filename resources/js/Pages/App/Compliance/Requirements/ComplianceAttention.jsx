import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { attentionPanel, attentionReasonLabel, attentionTotalLabel } from './complianceRequirement';

/**
 * Why one requirement needs attention: its reasons as a short list, in 16 px, amber like the rest
 * of the module's follow-up text. Nothing when there are none.
 */
export function AttentionReasons({ reasons = [], tr, withLabel = false, testId = 'compliance-attention-reasons' }) {
    if (reasons.length === 0) {
        return null;
    }

    return (
        <div className="text-base text-amber-900" data-testid={testId}>
            {withLabel && <p className="font-semibold">{tr.attention?.row_label ?? 'Trenger oppmerksomhet'}</p>}
            <ul className="flex flex-wrap gap-x-4 gap-y-1">
                {reasons.map((reason) => (
                    <li key={reason} className="flex items-center gap-2" data-reason={reason}>
                        <span aria-hidden="true" className="inline-block h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                        {attentionReasonLabel(reason, tr)}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * «Trenger oppmerksomhet» at the top of a register: a worklist, not a dashboard. The server has
 * already applied the rules — ComplianceAttentionService on Krav, ComplianceAuditAttentionService on
 * Revisjoner — to what this person may see, and only to that; this lists the entries with their
 * reasons. `tr` is the namespace holding the register's own `attention` texts. Not shown at all when
 * nothing needs attention.
 */
export default function ComplianceAttention({ attention, tr, testIdPrefix = 'compliance-attention' }) {
    const [expanded, setExpanded] = useState(false);
    const panel = attentionPanel(attention, expanded);

    if (! panel.visible) {
        return null;
    }

    return (
        <section aria-labelledby={`${testIdPrefix}-heading`} className="rounded-[24px] border border-amber-200 bg-amber-50 p-6 shadow-sm" data-testid={testIdPrefix}>
            <h2 id={`${testIdPrefix}-heading`} className="text-xl font-semibold text-slate-950">
                {tr.attention?.heading ?? 'Trenger oppmerksomhet'}
            </h2>
            <p className="mt-1 text-base font-semibold text-amber-900" data-testid={`${testIdPrefix}-total`}>
                {attentionTotalLabel(panel.total, tr)}
            </p>

            <ul className="mt-4 space-y-2" data-testid={`${testIdPrefix}-list`}>
                {panel.items.map((item) => (
                    <li key={item.id} className="rounded-2xl bg-white px-4 py-3" data-testid={`${testIdPrefix}-item`}>
                        <Link href={item.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">
                            {item.reference && <span className="mr-2 text-slate-700">{item.reference}</span>}
                            {item.title}
                        </Link>
                        <div className="mt-1">
                            <AttentionReasons reasons={item.reasons} tr={tr} />
                        </div>
                    </li>
                ))}
            </ul>

            {panel.hasMore && (
                <button
                    type="button"
                    className="mt-3 text-base font-semibold text-violet-700 hover:text-violet-900"
                    aria-expanded={expanded}
                    onClick={() => setExpanded((value) => ! value)}
                >
                    {expanded
                        ? (tr.attention?.show_fewer ?? 'Vis færre')
                        : (tr.attention?.show_all ?? 'Vis alle :count').replace(':count', String(panel.count))}
                </button>
            )}
        </section>
    );
}
