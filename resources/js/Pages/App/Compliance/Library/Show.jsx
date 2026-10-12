import { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { DISCLOSURE_INLINE, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { complianceHelp } from '../Requirements/complianceHelp';
import RequirementGuidance from './RequirementGuidance';
import {
    APPLICABILITY_TONES,
    FILTERS,
    applicabilityLabel,
    buildTree,
    chapterHeading,
    filterTree,
    hasControls,
    sectionIds,
} from './complianceLibrary';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * One requirement in the tree: clause, title and — once activated — its relevance or Kontrollvalg in
 * the customer's register. Whether it is met is not shown here; that is Etterlevelsesvurdering's.
 */
function RequirementRow({ node, activated, tr, open, onToggle }) {
    const tl = tr.library ?? {};
    const row = node.requirement;
    const detailsId = `compliance-library-details-${node.id}`;
    const control = node.applicability_rule === 'control';

    return (
        <li className="py-4" data-testid={`compliance-library-requirement-${node.clause}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1 space-y-2">
                    <p className="break-words text-base text-slate-950">
                        <span className="mr-2 font-semibold text-slate-600">{node.clause}</span>
                        <span className="font-semibold">{node.title}</span>
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        {node.applicability_rule === 'conditional' && <StatusBadge tone="violet">{tl.rule_short?.conditional ?? 'Betinget'}</StatusBadge>}
                        {node.withdrawn && <StatusBadge tone="slate">{tl.withdrawn ?? 'Tatt ut av kravbiblioteket'}</StatusBadge>}
                        {activated && row && (
                            <>
                                <span className="sr-only">{control ? (tl.col_control_choice ?? 'Kontrollvalg') : (tl.col_relevance ?? 'Relevans')}:</span>
                                <StatusBadge tone={APPLICABILITY_TONES[row.applicability] ?? 'slate'}>{applicabilityLabel(row.applicability, tr, node.applicability_rule)}</StatusBadge>
                            </>
                        )}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <button type="button" onClick={onToggle} aria-expanded={open} aria-controls={detailsId} className={DISCLOSURE_INLINE}>
                        {open ? (tl.hide_details ?? 'Skjul veiledning') : (tl.show_details ?? 'Vis veiledning')}
                    </button>
                    {row && (
                        <Link href={row.url} className={SECONDARY_ACTION} aria-label={`${tl.open_requirement ?? 'Åpne krav'} ${node.clause} ${node.title}`}>
                            {tl.open_requirement ?? 'Åpne krav'}
                        </Link>
                    )}
                </div>
            </div>
            {open && (
                <div id={detailsId} className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <RequirementGuidance details={node} tr={tr} showDescription idPrefix={`compliance-library-${node.clause}`} />
                </div>
            )}
        </li>
    );
}

/**
 * The chevron of a chapter or sub-clause, the rail's shape (ModuleSidebar's GroupChevron) made big
 * enough to see: right while closed, down while open. 24 px on a chapter, 20 px on a sub-clause.
 */
function SectionChevron({ open, chapter }) {
    return (
        <svg
            className={`${chapter ? 'h-6 w-6' : 'h-5 w-5'} shrink-0 text-slate-700 transition-transform ${open ? 'rotate-90' : ''}`}
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            data-testid="compliance-library-section-chevron"
        >
            <path d="M8 5.5 12.5 10 8 14.5" />
        </svg>
    );
}

/**
 * A chapter or sub-clause: the whole heading is one button that opens it. aria-expanded says
 * whether it is open; the chevron only shows it.
 */
function Section({ node, activated, tr, expanded, toggleSection, openDetails, toggleDetails }) {
    const isOpen = expanded(node.id);
    const panelId = `compliance-library-section-${node.id}`;
    const chapter = node.depth === 1;

    return (
        <li className={chapter ? 'rounded-2xl border border-slate-200' : ''} data-testid={`compliance-library-section-${node.clause}`}>
            <h3 className="m-0">
                <button
                    type="button"
                    onClick={() => toggleSection(node.id)}
                    aria-expanded={isOpen}
                    aria-controls={panelId}
                    className={`flex min-h-11 w-full flex-wrap items-center justify-between gap-3 text-left hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300 ${chapter ? 'rounded-2xl px-4 py-4' : 'rounded-xl px-1 py-3'}`}
                >
                    <span className={`flex min-w-0 items-center gap-2 ${chapter ? 'text-lg font-semibold text-slate-950' : 'text-base font-semibold text-slate-900'}`}>
                        <SectionChevron open={isOpen} chapter={chapter} />
                        <span className="min-w-0 break-words">{chapter ? chapterHeading(node) : `${node.clause} ${node.title}`}</span>
                    </span>
                </button>
            </h3>
            {isOpen && (
                <div id={panelId} className={chapter ? 'px-4 pb-2' : 'pl-5'}>
                    <ul className="divide-y divide-slate-100">
                        {node.children.map((child) => (
                            child.kind === 'requirement' ? (
                                <RequirementRow
                                    key={child.id}
                                    node={child}
                                    activated={activated}
                                    tr={tr}
                                    open={openDetails.has(child.id)}
                                    onToggle={() => toggleDetails(child.id)}
                                />
                            ) : (
                                <Section
                                    key={child.id}
                                    node={child}
                                    activated={activated}
                                    tr={tr}
                                    expanded={expanded}
                                    toggleSection={toggleSection}
                                    openDetails={openDetails}
                                    toggleDetails={toggleDetails}
                                />
                            )
                        ))}
                    </ul>
                </div>
            )}
        </li>
    );
}

/**
 * Kravbibliotek → one framework, for exploring it: what the framework is and every requirement in
 * the standard's order with guidance. Activation is offered on the standard's card in Kravbiblioteket,
 * and this page only says where. Once activated, each requirement shows its relevance or
 * Kontrollvalg and is one click from its own page; where the work stands is
 * Etterlevelsesvurdering's, which the page links to. Chapters open one by one so a long standard
 * stays readable; a search or a filter opens everything that matches.
 */
export default function ComplianceLibraryShow() {
    const {
        translations = {},
        framework,
        nodes = [],
        activation = null,
    } = usePage().props;

    const tr = translations?.compliance ?? {};
    const tl = tr.library ?? {};
    const activated = activation !== null;
    const withControls = hasControls(nodes);
    const filterLabels = withControls ? { ...(tl.filters ?? {}), ...(tl.filters_controls ?? {}) } : (tl.filters ?? {});

    const tree = useMemo(() => buildTree(nodes), [nodes]);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');
    const [openSections, setOpenSections] = useState(() => new Set());
    const [openDetails, setOpenDetails] = useState(() => new Set());

    const narrowing = search.trim() !== '' || filter !== 'all';
    const visible = useMemo(() => filterTree(tree, filter, search), [tree, filter, search]);
    const expanded = (id) => narrowing || openSections.has(id);

    const toggle = (setter) => (id) => setter((current) => {
        const next = new Set(current);
        next.has(id) ? next.delete(id) : next.add(id);

        return next;
    });

    return (
        <CustomerAppLayout title={framework.name} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-base font-semibold text-slate-700">{tl.edition ?? 'Utgave'}: {framework.label}</span>
                            <StatusBadge tone={activated ? 'emerald' : 'slate'}>{activated ? (tl.activated ?? 'Aktivert') : (tl.not_activated ?? 'Ikke aktivert')}</StatusBadge>
                            <StatusBadge tone={framework.content_verified ? 'emerald' : 'amber'}>
                                {framework.content_verified ? (tl.verified_badge ?? 'Faglig kvalitetssikret') : (tl.unverified_badge ?? 'Ikke faglig kvalitetssikret')}
                            </StatusBadge>
                        </div>
                        <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{framework.name}</h1>
                        <p className="text-base text-slate-600">
                            {(tl.chapter_count ?? ':count kapitler').replace(':count', String(framework.chapter_count))}
                            {' · '}
                            {(tl.requirement_count ?? ':count krav').replace(':count', String(framework.requirement_count))}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...complianceHelp(tr, 'framework')} />
                        {activated && (
                            <>
                                <Link href={activation.register_url} className={SECONDARY_ACTION}>{tl.open_register ?? 'Åpne i kravregisteret'}</Link>
                                <Link href={activation.assessments_url} className={PRIMARY_ACTION}>{tl.open_assessments ?? 'Til etterlevelsesvurdering'}</Link>
                            </>
                        )}
                    </div>
                </header>

                <section className={CARD} aria-labelledby="compliance-library-about-heading">
                    <h2 id="compliance-library-about-heading" className="sr-only">{framework.name}</h2>
                    <p className="max-w-4xl text-base leading-7 text-slate-800">{framework.description}</p>
                    {framework.scope_note && (
                        <div className="mt-4">
                            <h3 className="text-base font-semibold text-slate-700">{tl.scope_heading ?? 'Gjelder innenfor omfanget'}</h3>
                            <p className="mt-1 max-w-4xl text-base leading-7 text-slate-700">{framework.scope_note}</p>
                        </div>
                    )}
                    {framework.verification_note && (
                        <div className="mt-4" data-testid="compliance-library-verification">
                            <h3 className="text-base font-semibold text-slate-700">{tl.verification_heading ?? 'Om innholdet'}</h3>
                            <p className="mt-1 max-w-4xl text-base leading-7 text-slate-700">{framework.verification_note}</p>
                        </div>
                    )}
                </section>

                {activated ? (
                    <p className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-900" data-testid="compliance-library-activated">
                        {tl.activated_note ?? 'Aktivert for virksomheten.'}
                    </p>
                ) : (
                    <p className="text-base text-slate-700" data-testid="compliance-library-not-activated">
                        {tl.not_activated_note ?? 'Standarden er ikke aktivert for virksomheten.'}
                        {' '}
                        <Link href="/app/compliance/library" className="font-semibold text-violet-700 hover:text-violet-900">{tl.not_activated_link ?? 'Aktiver den fra Kravbiblioteket'} →</Link>
                    </p>
                )}

                <section className={CARD} aria-labelledby="compliance-library-tree-heading">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <h2 id="compliance-library-tree-heading" className="text-xl font-semibold text-slate-950">
                            {tl.tree_heading ?? 'Kravene'}
                        </h2>
                        {! narrowing && (
                            <div className="flex flex-wrap gap-2">
                                <button type="button" onClick={() => setOpenSections(new Set(sectionIds(tree)))} className={DISCLOSURE_INLINE}>{tl.expand_all ?? 'Åpne alle'}</button>
                                <button type="button" onClick={() => setOpenSections(new Set())} className={DISCLOSURE_INLINE}>{tl.collapse_all ?? 'Lukk alle'}</button>
                            </div>
                        )}
                    </div>

                    <div className="mt-4 grid gap-4 md:grid-cols-[2fr_1fr]">
                        <div>
                            <label htmlFor="compliance-library-search" className={LABEL}>{tl.search_label ?? 'Søk i kravene'}</label>
                            <input
                                id="compliance-library-search"
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={tl.search_placeholder ?? ''}
                                className={`mt-1 ${INPUT}`}
                            />
                        </div>
                        {activated && (
                            <div>
                                <label htmlFor="compliance-library-filter" className={LABEL}>{tl.filter_label ?? 'Vis'}</label>
                                <select id="compliance-library-filter" value={filter} onChange={(event) => setFilter(event.target.value)} className={`mt-1 ${INPUT}`}>
                                    {FILTERS.map((key) => <option key={key} value={key}>{filterLabels[key] ?? key}</option>)}
                                </select>
                            </div>
                        )}
                    </div>

                    {visible.length === 0 ? (
                        <p className="mt-6 text-base text-slate-600" data-testid="compliance-library-no-matches">{tl.no_matches ?? 'Ingen krav passer søket.'}</p>
                    ) : (
                        <ul className="mt-6 space-y-3" data-testid="compliance-library-tree">
                            {visible.map((node) => (
                                node.kind === 'section' ? (
                                    <Section
                                        key={node.id}
                                        node={node}
                                        activated={activated}
                                        tr={tr}
                                        expanded={expanded}
                                        toggleSection={toggle(setOpenSections)}
                                        openDetails={openDetails}
                                        toggleDetails={toggle(setOpenDetails)}
                                    />
                                ) : (
                                    <RequirementRow
                                        key={node.id}
                                        node={node}
                                        activated={activated}
                                        tr={tr}
                                        open={openDetails.has(node.id)}
                                        onToggle={() => toggle(setOpenDetails)(node.id)}
                                    />
                                )
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
