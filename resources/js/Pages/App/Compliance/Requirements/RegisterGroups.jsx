import StatusBadge from '../../../../Components/App/StatusBadge';
import { APPLICABILITY_TONES, applicabilityLabel } from '../Library/complianceLibrary';
import RequirementOrigin from '../RequirementOrigin';
import { REQUIREMENT_STATUS_TONES, countLabel } from './complianceRequirement';
import { groupHeading } from './complianceRegister';

/** Right while closed, down while open — Kravbiblioteket's chevron. */
function Chevron({ open, large = false }) {
    return (
        <svg
            className={`${large ? 'h-6 w-6' : 'h-5 w-5'} shrink-0 text-slate-700 transition-transform ${open ? 'rotate-90' : ''}`}
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M8 5.5 12.5 10 8 14.5" />
        </svg>
    );
}

function CountPill({ count, tr, testId }) {
    return (
        <span className="shrink-0 rounded-full bg-slate-100 px-3 py-0.5 text-base font-semibold text-slate-700" data-testid={testId}>
            {countLabel(count, tr.count_one ?? '1 krav', tr.count ?? ':count krav')}
        </span>
    );
}

/** One requirement: its origin (the title links to the requirement), relevance and status. No text. */
function RequirementItem({ item, tr }) {
    const statusLabels = tr.statuses ?? {};

    return (
        <li className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 py-3" data-testid="compliance-group-requirement">
            <RequirementOrigin origin={item.origin} href={item.url} className="min-w-0 flex-1 basis-64" />
            <div className="flex flex-wrap items-center gap-2">
                {item.from_library && (
                    <StatusBadge tone={APPLICABILITY_TONES[item.applicability] ?? 'slate'}>
                        {applicabilityLabel(item.applicability, tr, item.applicability_rule)}
                    </StatusBadge>
                )}
                <StatusBadge tone={REQUIREMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
            </div>
        </li>
    );
}

function RequirementItems({ rows, tr, testId }) {
    if (rows === null || rows === undefined) {
        return <p className="py-3 text-base text-slate-600">{tr.register_view?.loading ?? 'Henter krav …'}</p>;
    }

    return (
        <ul className="divide-y divide-slate-100" data-testid={testId}>
            {rows.map((item) => <RequirementItem key={item.id} item={item} tr={tr} />)}
        </ul>
    );
}

/**
 * A chapter or theme in a source: the heading is one button that opens it. A theme of a control set
 * (ISO/IEC 27001 Vedlegg A) names the set it belongs to above its own heading.
 */
function Group({ group, open, onToggle, tr }) {
    const panelId = `compliance-group-${group.key.replace(':', '-')}`;

    return (
        <li data-testid={`compliance-group-${group.reference}`}>
            <h4 className="m-0">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={open}
                    aria-controls={panelId}
                    className="flex min-h-11 w-full items-center justify-between gap-3 rounded-xl px-2 py-3 text-left hover:bg-slate-50"
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <Chevron open={open} />
                        <span className="min-w-0">
                            {group.context && (
                                <span className="block break-words text-base text-slate-600" data-testid="compliance-group-context">{group.context.title}</span>
                            )}
                            <span className="block break-words text-base font-semibold text-slate-900">{groupHeading(group)}</span>
                        </span>
                    </span>
                    <CountPill count={group.count} tr={tr} testId="compliance-group-count" />
                </button>
            </h4>
            {open && (
                <div id={panelId} className="pb-2 pl-4 sm:pl-9">
                    <RequirementItems rows={group.requirements} tr={tr} testId="compliance-group-requirements" />
                </div>
            )}
        </li>
    );
}

/**
 * The headings are an h3 per source and an h4 per chapter, under the register's h2 (A11Y-119). The
 * buttons draw no focus ring of their own: the app-wide violet-600 outline is the indicator
 * (A11Y-010; the violet-300 ring it replaced was under 3:1).
 *
 * Gruppert: kravkilde → kapittel → krav, everything closed at first. The counts are the server's,
 * over the same search and filters as Liste; a source or chapter without matches is not shown. A
 * source without chapters — one registered by hand — lists its requirements directly.
 */
export default function RegisterGroups({ sources, isOpen, onToggle, tr }) {
    return (
        <ul className="mt-4 space-y-3" data-testid="compliance-register-groups">
            {sources.map((source) => {
                const open = isOpen(source.key);
                const panelId = `compliance-source-${source.id}`;

                return (
                    <li key={source.key} className="rounded-2xl border border-slate-200" data-testid={`compliance-register-source-${source.id}`}>
                        <h3 className="m-0">
                            <button
                                type="button"
                                onClick={() => onToggle(source.key)}
                                aria-expanded={open}
                                aria-controls={panelId}
                                className="flex min-h-11 w-full items-center justify-between gap-3 rounded-2xl px-4 py-4 text-left hover:bg-slate-50"
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <Chevron open={open} large />
                                    <span className="min-w-0 break-words text-lg font-semibold text-slate-950" data-testid="compliance-source-group-label">{source.label}</span>
                                </span>
                                <CountPill count={source.count} tr={tr} testId="compliance-source-group-count" />
                            </button>
                        </h3>
                        {open && (
                            <div id={panelId} className="border-t border-slate-100 px-2 pb-2 sm:px-4">
                                {source.groups.length > 0 && (
                                    <ul className="divide-y divide-slate-100">
                                        {source.groups.map((group) => (
                                            <Group
                                                key={group.key}
                                                group={group}
                                                open={isOpen(group.key)}
                                                onToggle={() => onToggle(group.key)}
                                                tr={tr}
                                            />
                                        ))}
                                    </ul>
                                )}
                                {source.ungrouped_count > 0 && (
                                    <div className={source.groups.length > 0 ? 'border-t border-slate-100 px-2' : 'px-2'}>
                                        <RequirementItems rows={source.requirements} tr={tr} testId="compliance-source-requirements" />
                                    </div>
                                )}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
