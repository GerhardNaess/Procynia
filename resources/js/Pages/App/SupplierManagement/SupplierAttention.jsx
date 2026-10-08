import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ATTENTION_TARGETS, attentionCategoryLabel, attentionFindingText, attentionPanel, attentionTotalLabel } from './supplierManagement';
import { attentionFindingItems } from './assuranceFollowUp';

/**
 * Why one supplier needs attention: each finding in a sentence, in 16 px, amber like the other
 * modules' follow-up text. A Leverandørkontroll finding lists its requirements under it, each with
 * why, so the reason is readable without opening the supplier. With `withLinks` each sentence is
 * followed by where on the supplier page it is followed up, and each requirement links to its row.
 * Nothing when there are no findings.
 */
export function SupplierAttentionFindings({ findings = [], tr, formatDate, withLinks = false }) {
    if (findings.length === 0) {
        return null;
    }

    return (
        <ul className="space-y-2 text-base text-amber-900" data-testid="supplier-attention-findings">
            {findings.map((finding, index) => {
                const target = ATTENTION_TARGETS[finding.key];

                return (
                    <li key={`${finding.key}-${index}`} className="flex items-start gap-2" data-finding={finding.key}>
                        <span aria-hidden="true" className="mt-2 inline-block h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                        <span className="min-w-0 break-words">
                            {attentionFindingText(finding, tr, formatDate)}
                            {withLinks && target && (
                                <>
                                    {' '}
                                    <a href={`#${target.anchor}`} className="font-semibold text-violet-700 hover:text-violet-900">
                                        {tr.attention?.[target.label] ?? target.fallback}
                                    </a>
                                </>
                            )}
                            {(finding.requirements ?? []).length > 0 && (
                                <ul className="mt-1 space-y-1 pl-1" data-testid="supplier-attention-requirements">
                                    {attentionFindingItems(finding, tr, formatDate).map((item) => (
                                        <li key={item.id} className="break-words" data-requirement-id={item.id}>
                                            {withLinks
                                                ? <a href={`#control-requirement-${item.id}`} className="text-amber-900 underline decoration-amber-400 underline-offset-2 hover:text-amber-950">{item.text}</a>
                                                : item.text}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * «Trenger oppmerksomhet» at the top of the register: a worklist, not a dashboard. The server has
 * already applied SupplierAttentionService to the suppliers this person may see, and only to those;
 * this lists them with their findings. Not shown at all when nothing needs attention.
 */
export default function SupplierAttention({ attention, tr, formatDate }) {
    const [expanded, setExpanded] = useState(false);
    const panel = attentionPanel(attention, expanded);

    if (! panel.visible) {
        return null;
    }

    return (
        <section aria-labelledby="supplier-attention-heading" className="rounded-[24px] border border-amber-200 bg-amber-50 p-6 shadow-sm" data-testid="supplier-attention">
            <h2 id="supplier-attention-heading" className="text-xl font-semibold text-slate-950">{tr.attention?.heading ?? 'Trenger oppmerksomhet'}</h2>
            <p className="mt-1 text-base font-semibold text-amber-900" data-testid="supplier-attention-total">{attentionTotalLabel(panel.total, tr)}</p>
            <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-base text-slate-800" data-testid="supplier-attention-categories">
                {panel.categories.map((category) => (
                    <li key={category.key} data-category={category.key}>{attentionCategoryLabel(category.key, tr)}: {category.count}</li>
                ))}
            </ul>

            <ul className="mt-4 space-y-2" data-testid="supplier-attention-list">
                {panel.items.map((item) => (
                    <li key={item.id} className="rounded-2xl bg-white px-4 py-3" data-testid="supplier-attention-item">
                        <Link href={item.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">{item.name}</Link>
                        <div className="mt-1">
                            <SupplierAttentionFindings findings={item.findings} tr={tr} formatDate={formatDate} />
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
