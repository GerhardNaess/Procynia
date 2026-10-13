import { ATTENTION_TARGETS, attentionCategoryLabel, attentionFindingText } from './supplierManagement';
import { attentionFindingItems } from './assuranceFollowUp';

/**
 * Why one supplier needs attention: each finding in a sentence, in 16 px, amber like the other
 * modules' follow-up text. A Leverandørkontroll finding lists its requirements under it, each with
 * why, so the reason is readable without opening the supplier. With `withLinks` each sentence is
 * followed by where on the supplier page it is followed up, and each requirement links to its row.
 * `compact` (Oversikt) keeps each finding to its sentence and its link — the count, not the list of
 * requirements, which Krav og kvalifikasjoner holds. Nothing when there are no findings.
 */
export function SupplierAttentionFindings({ findings = [], tr, formatDate, withLinks = false, compact = false }) {
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
                            {compact ? attentionFindingText(finding, tr, formatDate).replace(/:\s*$/, '.') : attentionFindingText(finding, tr, formatDate)}
                            {withLinks && target && (
                                <>
                                    {' '}
                                    <a href={`#${target.anchor}`} className="font-semibold text-violet-700 hover:text-violet-900">
                                        {tr.attention?.[target.label] ?? target.fallback}
                                    </a>
                                </>
                            )}
                            {! compact && (finding.requirements ?? []).length > 0 && (
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
 * «Trenger oppfølging» on a register row: which kinds of follow-up the supplier has, in one
 * discreet line under its name. The register finds the supplier; the supplier page says why and
 * where to act, and Oppfølging holds the task. Nothing when there is nothing to follow up — the
 * register never claims a supplier needs nothing it cannot determine.
 */
export function SupplierAttentionMarker({ keys = [], tr }) {
    if (keys.length === 0) {
        return null;
    }

    return (
        <p className="mt-1 flex max-w-xs items-start gap-2 text-base text-amber-900" data-testid="supplier-row-attention">
            <span aria-hidden="true" className="mt-2 inline-block h-2 w-2 shrink-0 rounded-full bg-amber-500" />
            <span className="min-w-0 break-words">
                <span className="font-semibold">{tr.attention?.heading ?? 'Trenger oppfølging'}</span>
                {': '}
                {keys.map((key) => attentionCategoryLabel(key, tr)).join(' · ')}
            </span>
        </p>
    );
}
