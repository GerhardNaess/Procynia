import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ProcessSwimlaneDiagram from './ProcessSwimlaneDiagram';
import ProcessFlowStepList from './ProcessFlowStepList';
import ActionDialog from './ActionDialog';
import StatusBadge from './StatusBadge';
import { flowReadingOrder } from '../../Support/processBlueprintLayout';
import {
    branchProblems,
    branchTargets,
    canInsertStepOn,
    decisionBranches,
    freshStepKey,
    isEditableStep,
    stepEditIsValid,
    withDecisionBranches,
    withStepEdited,
    withStepInserted,
} from '../../Support/processStepEdit';
import {
    DESTRUCTIVE_ACTION,
    DESTRUCTIVE_COLOURS,
    DESTRUCTIVE_CONFIRM,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
} from '../../Support/actionStyles';

/**
 * The "Flyt" tab of one process.
 *
 * Read top to bottom, it is the order the work happens in: what the process is for, a button to
 * propose a flow, the flow itself as something you can edit, the diagram that follows from it, and
 * finally the approval.
 *
 * The diagram is deliberately downstream of the editor and not beside it. It redraws from local
 * state on every keystroke, so a change to a lane or an arrow is visible before it is saved. The
 * picture has no handles of its own, because the blueprint is the source of truth and giving it some
 * would create a second one. Clicking a step opens a small dialog for its text and role — but that
 * dialog edits the blueprint's node and saves the working version through the same route as the
 * structure editor; the diagram only redraws from what comes back.
 *
 * Unsaved state lives here, not on the server. `isDirty` is what the approve button reads: you
 * cannot approve a flow that is not the flow that would be saved.
 *
 * THE PROPOSAL.
 *
 * Describing the process in plain language produces a proposal, and a proposal is loaded into the
 * same editor the saved flow uses, through the same state. That is deliberate: there is one flow
 * model, so there is one place to correct it, and a user who fixes a role in a proposal is doing
 * the identical thing they would do to a stored flow. What changes is only what the buttons at the
 * bottom mean — adopt it, or go back and say it differently — and that nothing has been written
 * yet. The flow the process already had is untouched until the user adopts.
 *
 * DRILLING INTO A SUBPROCESS.
 *
 * A node may stand for another process. Opening it does not open that process's page — it shows
 * that process's flow in this tab's diagram area, under a breadcrumb back to where the reader came
 * from, because the question being asked is "what happens inside this step", not "take me somewhere
 * else". The trail is in the URL rather than in state here, so the view survives a reload and the
 * browser's back button is the back button; the editor's unsaved work survives it because the visit
 * is a partial reload that asks only for the subprocess.
 *
 * What is shown there is read-only, and deliberately so. The subprocess is somebody's process with
 * its own owner and its own approval, and editing it from inside a parent would be editing a
 * document you did not open. There is one link out, to its own page, where it can be edited as what
 * it is.
 */

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const SMALL_INPUT = 'min-h-9 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const ROW_ADD = `inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300`;
const ROW_DESTRUCTIVE = `inline-flex min-h-9 items-center justify-center rounded-lg px-3 py-1.5 text-sm font-semibold transition ${DESTRUCTIVE_COLOURS}`;
const SUBHEADING = 'text-base font-semibold text-slate-900';

const TEXTAREA = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base leading-6 text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

const NODE_TYPES = ['start', 'step', 'decision', 'end'];

const STRUCTURE_EDITOR_ID = 'process-flow-structure-editor';

export default function ProcessFlowPanel({
    tq,
    item,
    blueprint,
    // Approved revisions, newest first. They outlive the working version, so they are shown even
    // when the process has no flow at the moment.
    revisions = [],
    // Whether the process is published and which revision is in force — derived server-side from
    // the revisions. The working version's own status is not the answer to that question.
    publication = null,
    // One prop per permission the controller gates on, rather than a single "may manage". Saving
    // the flow, vouching for it and starting it over are three decisions the customer may hand to
    // three different roles, so the tab has to be able to offer them one at a time.
    canCreate = false,
    canEdit = false,
    // Creating a knowledge article is a Wiki source action done from Kvalitet, so it has its own
    // answer — quality.create alone does not open it.
    canCreateWikiArticles = false,
    canApprove = false,
    canDelete = false,
    proposal = null,
    flowError = null,
    flowAiAvailable = false,
    subprocessView = null,
    subprocessOptions = [],
    articleDraft = null,
    articleError = null,
}) {
    const tb = tq.blueprint ?? {};
    const nodeTypeLabels = tb.node_types ?? {};

    const [lanes, setLanes] = useState(() => (proposal ?? blueprint)?.lanes ?? []);
    const [nodes, setNodes] = useState(() => (proposal ?? blueprint)?.nodes ?? []);
    const [edges, setEdges] = useState(() => (proposal ?? blueprint)?.edges ?? []);
    const [isDirty, setIsDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    // Why the last "Godkjenn og publiser" was refused: a heading and one line per problem the flow
    // validator found in the stored flow.
    const [approvalErrors, setApprovalErrors] = useState(null);
    // The row-by-row editor is folded away by default. It is the fallback for a correction the
    // description could not express, not the way a flow is normally built — and left open it is
    // the loudest thing on the tab, so the diagram and the steps, which are what the user came to
    // read, start below three tables of keys and dropdowns.
    const [editingStructure, setEditingStructure] = useState(false);
    // Which activity's panel is open, by node key rather than by the node itself: the panel has to
    // show the activity as it is now, and holding the object would leave it showing a label the
    // user has since corrected.
    const [activityKey, setActivityKey] = useState(null);
    // Which step is open for editing from the diagram — by key, for the same reason as activityKey.
    const [editingStepKey, setEditingStepKey] = useState(null);
    // Which arrow a new activity is being put on from the diagram: { from, to, label }.
    const [insertingOn, setInsertingOn] = useState(null);

    // The description the user typed. Seeded from whichever of the three sources knows it: the
    // proposal being reviewed, the attempt that failed, or the flow that was adopted from it.
    const [description, setDescription] = useState(
        () => proposal?.description ?? flowError?.description ?? blueprint?.description ?? '',
    );
    // "Endre beskrivelsen" — the proposal is still in the session, but the user has said they are
    // rewriting rather than adopting, so the editor goes back to the stored flow.
    const [dismissed, setDismissed] = useState(false);
    const [descriptionError, setDescriptionError] = useState(null);
    // Suggestions turned down with "Avvis". Held here as well as persisted, because the suggestion
    // has to be gone on the click — the server is being told so it stays gone next time, which is a
    // different question from what the user is looking at now.
    const [declined, setDeclined] = useState([]);
    // Suggestions answered with "Avklar". Same reason as `declined` and a different question: the
    // round trip rewrites the description and reads it again, which takes a moment, and a user who
    // has just answered something should not watch it sit there being asked.
    const [answered, setAnswered] = useState([]);

    const reviewing = proposal !== null && ! dismissed;

    // What the server stored, as a value. generated_at is to the second, so two saves from the
    // diagram inside one second would otherwise leave the editor holding the flow before the
    // second — and the next save would write that stale flow back.
    const storedFlow = JSON.stringify([blueprint?.lanes ?? null, blueprint?.nodes ?? null, blueprint?.edges ?? null]);

    // The server is authoritative after every round trip — generate, save and approve all come back
    // through props. Resetting on the blueprint's identity rather than on every render is what lets
    // the editor hold unsaved work in between. A proposal arriving is the same kind of event: new
    // props, so the editor reloads from them and whatever was half-edited before is gone.
    useEffect(() => {
        const source = proposal ?? blueprint;

        setLanes(source?.lanes ?? []);
        setNodes(source?.nodes ?? []);
        setEdges(source?.edges ?? []);
        setIsDirty(false);
        setApprovalErrors(null);
        setDismissed(false);
        setDeclined([]);
        // A new flow arrived from the server, so whatever the editor was open for is settled.
        setEditingStructure(false);
        setActivityKey(null);
        setEditingStepKey(null);
        setInsertingOn(null);
        // Cleared rather than carried, so the new reading has the last word. A suggestion answered
        // well is gone because the revised description defines the term and the model stops asking;
        // one the answer did not actually cover comes back, which is the truth about it.
        setAnswered([]);

        if (proposal?.description) {
            setDescription(proposal.description);
        }
    }, [blueprint?.id, blueprint?.generated_at, blueprint?.approved_at, storedFlow, proposal]);

    // Going back to the stored flow has to put the stored flow back in the editor, or "Endre
    // beskrivelsen" would leave the proposal on screen looking saved.
    useEffect(() => {
        if (! dismissed) {
            return;
        }

        setLanes(blueprint?.lanes ?? []);
        setNodes(blueprint?.nodes ?? []);
        setEdges(blueprint?.edges ?? []);
        setIsDirty(false);
    }, [dismissed]);

    const draft = { lanes, nodes, edges };
    const hasFlow = reviewing || blueprint !== null;
    // Once a revision has been approved, removing the flow resets the working version to the
    // revision in force instead, so the action and its dialog say so.
    const publishedRevision = publication?.revision_number ?? null;
    const discards = publishedRevision !== null;

    function edit(setter) {
        return (value) => {
            setter(value);
            setIsDirty(true);
        };
    }

    function save() {
        setSaving(true);
        router.put(`/app/quality/items/${item.id}/blueprint`, draft, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    }

    /**
     * Save one step's text and role from the diagram's dialog — and, for a decision, its branches.
     *
     * The working version is saved as the editor holds it, with that one node changed — the same
     * PUT "Lagre struktur" makes, so it clears an approval the same way and is the same kind of
     * change. The local nodes are not touched first: what the diagram shows next is what the
     * server stored, and a refused save leaves the flow exactly as it was. A decision's branches
     * are its outgoing arrows, so they travel as those arrows replaced, in the same request.
     */
    function saveStep(key, { branches = null, ...change }, { onError }) {
        setSaving(true);
        router.put(`/app/quality/items/${item.id}/blueprint`, {
            lanes,
            nodes: withStepEdited(nodes, key, change),
            edges: branches === null ? edges : withDecisionBranches(edges, key, branches),
        }, {
            preserveScroll: true,
            onError,
            onFinish: () => setSaving(false),
        });
    }

    /**
     * Put a new activity on one arrow from the diagram's "+": from → new → to.
     *
     * Same terms as saveStep — the working version as the editor holds it, with that one change, by
     * the same PUT — so the new step is in the diagram only once the server has stored it.
     */
    function saveInsertion(edge, step, { onError }) {
        setSaving(true);
        router.put(`/app/quality/items/${item.id}/blueprint`, {
            lanes,
            ...withStepInserted({ nodes, edges }, edge, step),
        }, {
            preserveScroll: true,
            onError,
            onFinish: () => setSaving(false),
        });
    }

    function approve() {
        setSaving(true);
        setApprovalErrors(null);
        router.post(`/app/quality/items/${item.id}/blueprint/approve`, {}, {
            preserveScroll: true,
            onError: (errors) => setApprovalErrors({
                heading: errors.blueprint ?? null,
                problems: Object.keys(errors)
                    .filter((key) => key.startsWith('blueprint_problems.'))
                    .sort((a, b) => Number(a.split('.')[1]) - Number(b.split('.')[1]))
                    .map((key) => errors[key]),
            }),
            onFinish: () => setSaving(false),
        });
    }

    /**
     * Remove the flow and nothing else. The server deletes the blueprint, the props come back with
     * `blueprint: null`, and the tab falls through to its own empty state — which is already where
     * a new flow is described or generated, so there is nowhere else to send the user.
     *
     * Once a revision is in force the server resets the working version to it instead, and the
     * description box follows: it would otherwise still hold the discarded text.
     */
    function deleteFlow() {
        setSaving(true);
        router.delete(`/app/quality/items/${item.id}/blueprint`, {
            preserveScroll: true,
            onSuccess: (page) => setDescription(page.props.blueprint?.description ?? ''),
            onFinish: () => {
                setSaving(false);
                setConfirmingDelete(false);
            },
        });
    }

    // `text` is the description to read, and it is never an event: see InterpretCard's button for
    // what happens when a handler with a meaningful first argument is wired to onClick directly.
    function interpret(text = description) {
        setSaving(true);
        setDescriptionError(null);
        router.post(`/app/quality/items/${item.id}/blueprint/interpret`, { description: text }, {
            preserveScroll: true,
            onError: (errors) => setDescriptionError(errors.description ?? null),
            onFinish: () => setSaving(false),
        });
    }

    // "Avvis". Gone from the screen on the click; the request only makes it stay gone next time.
    // `only` keeps this a partial reload, so the proposal on screen — which lives in the flash of
    // the visit that produced it and cannot be flashed again — survives the round trip.
    function declineClarification(question) {
        setDeclined((current) => [...current, question]);

        router.post(`/app/quality/items/${item.id}/blueprint/clarifications/dismiss`, { question, description }, {
            preserveScroll: true,
            preserveState: true,
            only: ['flash'],
        });
    }

    // "Avklar". The server revises the description so the answer is part of it, then reads the
    // revised text — so there is one place the process is written down, the user can see and edit
    // what their answer became, and adopting the result stores a text that actually says what the
    // flow shows. Anything else here would be a second source of truth, or a chat.
    //
    // The description is not touched locally. What comes back is authoritative, and a failed
    // rewrite must leave the box saying exactly what the user wrote.
    function clarify(question, answer) {
        setAnswered((current) => [...current, question]);

        setSaving(true);
        setDescriptionError(null);
        router.post(`/app/quality/items/${item.id}/blueprint/clarifications/answer`, {
            question,
            answer: answer.trim(),
            description,
        }, {
            preserveScroll: true,
            onError: (errors) => setDescriptionError(errors.description ?? null),
            onFinish: () => setSaving(false),
        });
    }

    const trail = subprocessView?.trail ?? [];

    /**
     * Move the diagram area to a trail of subprocesses — or back to the process itself, with none.
     *
     * A partial reload asking only for `subprocess_view`: everything else on the tab stays as it
     * was, which is what keeps unsaved structure edits alive across a drill-down and keeps a
     * proposal — which lives in a flash and cannot be flashed again — on screen behind it.
     */
    function navigateTrail(ids) {
        // A node key identifies an activity within one flow and nowhere else, so an open panel
        // cannot survive a move to a different one.
        setActivityKey(null);

        router.get(`/app/quality/items/${item.id}`, {
            tab: 'flow',
            ...(ids.length > 0 ? { subprocess: ids.join(',') } : {}),
        }, {
            only: ['subprocess_view'],
            preserveState: true,
            preserveScroll: true,
            replace: false,
        });
    }

    const openSubprocess = (subprocess) => navigateTrail([...trail.map((step) => step.id), subprocess.id]);

    // Opens the activity, not a page: an activity is where the knowledge behind a step lives, and
    // the panel is both where the articles it has already produced are listed and where the next
    // one is written.
    const openActivity = (activity) => setActivityKey(activity?.key ?? null);

    // The payload travels as it stands in the editor, corrections included — the user is adopting
    // what they are looking at, not what the model first returned.
    function adopt() {
        setSaving(true);
        router.post(`/app/quality/items/${item.id}/blueprint/adopt`, { ...draft, description }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    }

    // Drilled into a subprocess. Everything that edits THIS process is gone from the tab, because
    // none of it applies to what is on screen — a user looking at Leverandørkontroll must not have
    // Innkjøp's description, structure editor and approval button under it.
    if (trail.length > 0) {
        const shown = subprocessView?.blueprint ?? null;
        // An activity on a subprocess belongs to the subprocess, so the article it produces is
        // created against that item and not the one whose page the reader happens to be on.
        const shownItemId = trail[trail.length - 1].id;

        return (
            <div className="space-y-6">
                <SubprocessFlow
                    tb={tb}
                    rootTitle={item.title}
                    trail={trail}
                    blueprint={shown}
                    onNavigate={navigateTrail}
                    onOpenSubprocess={openSubprocess}
                    onOpenActivity={openActivity}
                />

                <ActivityArticlePanel
                    tb={tb}
                    itemId={shownItemId}
                    activity={activityByKey(shown, activityKey)}
                    canCreate={canCreateWikiArticles}
                    aiAvailable={flowAiAvailable}
                    draft={articleDraft}
                    error={articleError}
                    onClose={() => setActivityKey(null)}
                />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <DescriptionCard tq={tq} tb={tb} item={item} />

            {canEdit && (
                <InterpretCard
                    tb={tb}
                    value={description}
                    setValue={setDescription}
                    onSubmit={interpret}
                    available={flowAiAvailable}
                    hasFlow={blueprint !== null}
                    busy={saving}
                    error={descriptionError}
                />
            )}

            {flowError && ! reviewing && <FlowErrorCard tb={tb} flowError={flowError} />}

            <section className={CARD}>
                {/*
                  * No "generer struktur" button. It called a deterministic generator that seeded a
                  * flow over whatever the process already had — from its steps, or from a worked
                  * ITIL example when it had none — so one click could replace a flow the user had
                  * described, corrected and adopted. A flow is written here only by adopting a
                  * proposal or by saving the editor below.
                  */}
                <div>
                    <h2 className="text-xl font-semibold text-slate-950">{tb.heading ?? 'Prosessflyt'}</h2>
                    <p className="mt-1 max-w-2xl text-base leading-6 text-slate-600">
                        {tb.help ?? 'Flyten viser hvem som gjør hva, i hvilken rekkefølge.'}
                    </p>
                </div>

                {reviewing
                    ? (
                        <ProposalNotice
                            tb={tb}
                            replaces={blueprint !== null}
                            blocking={proposal.blocking_questions ?? []}
                            optional={(proposal.optional_clarifications ?? [])
                                .filter((question) => ! declined.includes(question) && ! answered.includes(question))}
                            onClarify={clarify}
                            onDecline={declineClarification}
                            busy={saving}
                            canEdit={canEdit}
                        />
                    )
                    : (
                        <StatusLine
                            tq={tq}
                            blueprint={blueprint}
                            publication={publication}
                            isDirty={isDirty}
                            current={revisions[0] ?? null}
                        />
                    )}

                {! hasFlow && (
                    <p className="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-base text-slate-600">
                        {publication?.state === 'retired'
                            ? (tb.empty_retired ?? 'Det finnes ingen arbeidsversjon av flyten. De godkjente revisjonene ligger i historikken nedenfor.')
                            : discards
                            ? (tb.empty_published ?? 'Det finnes ingen arbeidsversjon. Gjeldende revisjon :number gjelder fortsatt.')
                                .replace(':number', String(publishedRevision))
                            : (tb.empty ?? 'Ingen flyt er laget for denne prosessen ennå.')}
                    </p>
                )}
            </section>

            {hasFlow && (
                <>
                    <section className={CARD}>
                        <h2 className="text-xl font-semibold text-slate-950">{tb.diagram_heading ?? 'Diagram'}</h2>
                        <div className="mt-4">
                            <ProcessSwimlaneDiagram
                                blueprint={draft}
                                title={`${tb.heading ?? 'Prosessflyt'} — ${item.title}`}
                                emptyText={tb.diagram_empty ?? 'Flyten har ingen steg å tegne.'}
                                tb={tb}
                                // Only a saved reference can be opened: the trail is resolved on the
                                // server against what is stored, so a reference picked in the editor
                                // and not yet saved has nothing to open into. It still shows its
                                // pill, which is the honest state — chosen, not yet in force.
                                onOpenSubprocess={isDirty ? null : openSubprocess}
                                // Unlike a subprocess, the activity panel needs nothing from the
                                // server to open: it reads the node the editor holds.
                                onOpenActivity={openActivity}
                                // Not while a proposal is on screen: saving there would write the
                                // proposal over the working version without it being adopted.
                                onEditStep={canEdit && ! reviewing ? (node) => setEditingStepKey(node.key) : null}
                                onInsertStep={canEdit && ! reviewing ? setInsertingOn : null}
                            />
                        </div>
                    </section>

                    <section className={CARD}>
                        <h2 className="text-xl font-semibold text-slate-950">{tb.steps_heading ?? 'Steg'}</h2>
                        <div className="mt-4">
                            <ProcessFlowStepList
                                tb={tb}
                                blueprint={draft}
                                onOpenSubprocess={isDirty ? null : openSubprocess}
                                onOpenActivity={openActivity}
                            />
                        </div>
                    </section>

                    <section className={CARD}>
                        <h2 className="text-xl font-semibold text-slate-950">{tb.structure_heading ?? 'Struktur'}</h2>
                        <p className="mt-1 max-w-2xl text-base leading-6 text-slate-600">
                            {tb.structure_help
                                ?? 'Roller, noder og forbindelser bak diagrammet. Rediger dem direkte når beskrivelsen ikke treffer.'}
                        </p>

                        <div className="mt-4">
                            <button
                                type="button"
                                className={SECONDARY_ACTION}
                                onClick={() => setEditingStructure((open) => ! open)}
                                aria-expanded={editingStructure}
                                aria-controls={STRUCTURE_EDITOR_ID}
                            >
                                {editingStructure
                                    ? (tb.structure_edit_hide ?? 'Skjul manuell redigering')
                                    : canEdit
                                        ? (tb.structure_edit_open ?? 'Rediger struktur manuelt')
                                        : (tb.structure_show ?? 'Vis struktur')}
                            </button>
                        </div>

                        {editingStructure && (
                            <div id={STRUCTURE_EDITOR_ID} className="mt-6 space-y-8">
                                <LaneEditor
                                    tb={tb}
                                    lanes={lanes}
                                    setLanes={edit(setLanes)}
                                    nodes={nodes}
                                    setNodes={edit(setNodes)}
                                    canEdit={canEdit}
                                />
                                <NodeEditor
                                    tb={tb}
                                    nodeTypeLabels={nodeTypeLabels}
                                    lanes={lanes}
                                    nodes={nodes}
                                    setNodes={edit(setNodes)}
                                    edges={edges}
                                    setEdges={edit(setEdges)}
                                    canEdit={canEdit}
                                    subprocessOptions={subprocessOptions}
                                />
                                <EdgeEditor
                                    tb={tb}
                                    nodes={nodes}
                                    edges={edges}
                                    setEdges={edit(setEdges)}
                                    canEdit={canEdit}
                                />
                            </div>
                        )}

                        {canEdit && reviewing && (
                            <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
                                <button type="button" className={PRIMARY_ACTION} onClick={adopt} disabled={saving}>
                                    {tb.proposal_adopt ?? 'Bruk denne prosessflyten'}
                                </button>
                                <button
                                    type="button"
                                    className={SECONDARY_ACTION}
                                    onClick={() => setDismissed(true)}
                                    disabled={saving}
                                >
                                    {tb.proposal_discard ?? 'Endre beskrivelsen'}
                                </button>
                            </div>
                        )}

                        {(canEdit || canApprove || canDelete) && ! reviewing && (
                            <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
                                {canEdit && (
                                    <button type="button" className={PRIMARY_ACTION} onClick={save} disabled={saving}>
                                        {tb.save ?? 'Lagre struktur'}
                                    </button>
                                )}
                                {canApprove && (
                                    <button
                                        type="button"
                                        className={SECONDARY_ACTION}
                                        onClick={approve}
                                        // Approving unsaved edits would vouch for a flow the database
                                        // does not hold. Save first, then approve what was saved.
                                        // Nothing to publish when the saved flow is what is in force.
                                        disabled={saving || isDirty || (
                                            publication?.revision_number != null && ! publication?.has_unpublished_changes
                                        )}
                                    >
                                        {tb.approve ?? 'Godkjenn og publiser'}
                                    </button>
                                )}
                                {canEdit && (
                                    <p className="text-sm text-slate-500">
                                        {tb.approval_cleared_help ?? 'Endringer du lagrer, er upubliserte til flyten godkjennes og publiseres.'}
                                    </p>
                                )}
                                {/* Pushed to the far end on purpose. Lagre and Godkjenn are two
                                    steps of one sequence; starting the flow over is not a third. */}
                                {canDelete && (
                                    <button
                                        type="button"
                                        className={`ml-auto ${DESTRUCTIVE_ACTION}`}
                                        onClick={() => setConfirmingDelete(true)}
                                        disabled={saving}
                                    >
                                        {discards
                                            ? (tb.discard_working_version ?? 'Forkast arbeidsversjon')
                                            : (tb.delete_flow ?? 'Slett flyt')}
                                    </button>
                                )}
                            </div>
                        )}

                        {approvalErrors && ! reviewing && (
                            <div role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-base text-rose-800">
                                {approvalErrors.heading && <p className="font-semibold">{approvalErrors.heading}</p>}
                                {approvalErrors.problems.length > 0 && (
                                    <ul className="mt-2 list-disc space-y-1 pl-5">
                                        {approvalErrors.problems.map((problem) => <li key={problem}>{problem}</li>)}
                                    </ul>
                                )}
                            </div>
                        )}
                    </section>
                </>
            )}

            {revisions.length > 0 && <RevisionHistory tb={tb} revisions={revisions} />}

            <ActivityArticlePanel
                tb={tb}
                itemId={item.id}
                activity={activityByKey(draft, activityKey)}
                canCreate={canCreateWikiArticles}
                aiAvailable={flowAiAvailable}
                draft={articleDraft}
                error={articleError}
                onClose={() => setActivityKey(null)}
            />

            <StepEditDialog
                tb={tb}
                step={editingStepKey === null ? null : nodes.find((node) => node.key === editingStepKey && isEditableStep(node)) ?? null}
                lanes={lanes}
                nodes={nodes}
                edges={edges}
                problemTexts={tq.flow_problems ?? {}}
                hasUnsavedChanges={isDirty}
                busy={saving}
                onSave={(change, options) => saveStep(editingStepKey, change, options)}
                onOpenActivity={(key) => {
                    setEditingStepKey(null);
                    setActivityKey(key);
                }}
                onClose={() => setEditingStepKey(null)}
            />

            <StepEditDialog
                tb={tb}
                inserting
                // Only while the arrow is still in the flow: the editor may have moved it since.
                step={insertingOn !== null && edges.some((edge) => edge.from === insertingOn.from && edge.to === insertingOn.to && canInsertStepOn(edge, nodes))
                    ? {
                        key: freshStepKey(nodes),
                        type: 'step',
                        label: '',
                        // The role of the step it follows: the likeliest answer, and one click to change.
                        lane: nodes.find((node) => node.key === insertingOn.from)?.lane ?? lanes[0]?.key ?? '',
                        between: [insertingOn.from, insertingOn.to].map((key) => nodes.find((node) => node.key === key)?.label ?? ''),
                    }
                    : null}
                lanes={lanes}
                hasUnsavedChanges={isDirty}
                busy={saving}
                onSave={(change, options) => saveInsertion(insertingOn, { key: freshStepKey(nodes), ...change }, options)}
                onClose={() => setInsertingOn(null)}
            />

            {/* Deleting the flow is not deleting the process, and the dialog's job is to make that
                difference impossible to miss — a user who has just written articles off the back of
                these activities needs to be told, before they press it, that the articles stay. */}
            <ActionDialog
                isOpen={confirmingDelete}
                onClose={() => setConfirmingDelete(false)}
                closeDisabled={saving}
                titleId="process-flow-delete-title"
            >
                <h2 id="process-flow-delete-title" className="text-xl font-semibold tracking-tight text-slate-950">
                    {discards
                        ? (tb.discard_working_version_title ?? 'Forkast arbeidsversjonen?')
                        : (tb.delete_flow_title ?? 'Slett prosessflyten?')}
                </h2>
                <p className="mt-2 text-base leading-6 text-slate-600">{item.title}</p>

                <dl className="mt-5 space-y-4">
                    <div>
                        <dt className="text-sm font-semibold uppercase tracking-wide text-rose-700">
                            {tq.delete_dialog_removed_heading ?? 'Dette slettes'}
                        </dt>
                        <dd className="mt-1 text-base leading-6 text-slate-700">
                            {discards
                                ? (tb.discard_working_version_removed ?? 'Endringene i arbeidsversjonen som ikke er godkjent og publisert, også i beskrivelsen.')
                                : (tb.delete_flow_removed ?? 'Flyten med aktivitetene sine, og beskrivelsen den ble lest ut av.')}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                            {tq.delete_dialog_kept_heading ?? 'Dette beholdes'}
                        </dt>
                        <dd className="mt-1 text-base leading-6 text-slate-700">
                            {discards
                                ? (tb.discard_working_version_kept ?? 'Gjeldende revisjon :number og alle tidligere godkjente revisjoner, prosessen selv, dokumentdataene og kunnskapen i Wiki.')
                                    .replace(':number', String(publishedRevision))
                                : (tb.delete_flow_kept ?? 'Prosessen selv, dokumentdataene og kunnskapen i Wiki — også artiklene aktivitetene har vært kilde til. Det er bare flyten som starter på nytt.')}
                        </dd>
                    </div>
                </dl>

                <p className="mt-4 text-base text-slate-600">
                    {discards
                        ? (tb.discard_working_version_next ?? 'Arbeidsversjonen tilbakestilles til gjeldende revisjon :number, og du kan redigere videre derfra.')
                            .replace(':number', String(publishedRevision))
                        : (tb.delete_flow_next ?? 'Etterpå kan du beskrive eller generere en ny flyt.')}
                </p>

                <div className="mt-6 flex flex-wrap gap-3">
                    <button type="button" className={DESTRUCTIVE_CONFIRM} onClick={deleteFlow} disabled={saving}>
                        {discards
                            ? (saving
                                ? (tb.discard_working_version_discarding ?? 'Forkaster …')
                                : (tb.discard_working_version ?? 'Forkast arbeidsversjon'))
                            : (saving
                                ? (tb.delete_flow_deleting ?? 'Sletter …')
                                : (tb.delete_flow_confirm ?? 'Slett flyt'))}
                    </button>
                    <button
                        type="button"
                        className={SECONDARY_ACTION}
                        onClick={() => setConfirmingDelete(false)}
                        disabled={saving}
                    >
                        {tb.delete_flow_cancel ?? 'Avbryt'}
                    </button>
                </div>
            </ActionDialog>
        </div>
    );
}

/**
 * One activity of a flow, by the key an indicator was clicked on.
 *

 * Resolved through flowReadingOrder() rather than by searching the node list, so the activity the
 * panel describes is the same activity the list and the diagram show — same role, same label, same
 * articles — and a node that has since been deleted resolves to nothing and closes the panel.
 */
function activityByKey(blueprint, key) {
    if (key === null || blueprint === null) {
        return null;
    }

    return flowReadingOrder(blueprint).find((step) => step.key === key) ?? null;
}

/**
 * One step, opened from the diagram: what it says and who does it — and, for a decision, where it
 * branches.
 *
 * Type and subprocess change the shape of the flow and stay in the structure editor; a role is
 * chosen among the flow's own, not created here. A decision's branches are the one exception to
 * "arrows stay in the editor", because a decision is its branches: each is a named outcome pointing
 * at a step that already exists. No step is created from here.
 *
 * The fields are seeded once per opening and owned by the dialog until it saves or closes, so a
 * re-render of the panel does not throw away what the user is typing.
 */
function StepEditDialog({
    tb,
    step,
    inserting = false,
    lanes,
    nodes = [],
    edges = [],
    problemTexts = {},
    hasUnsavedChanges,
    busy,
    onSave,
    onOpenActivity = null,
    onClose,
}) {
    const titleId = inserting ? 'process-step-insert-title' : 'process-step-edit-title';
    const [label, setLabel] = useState('');
    const [lane, setLane] = useState('');
    // Null for anything that is not a decision being edited, so its save leaves the arrows alone.
    const [branches, setBranches] = useState(null);
    const [error, setError] = useState(null);

    const editsBranches = ! inserting && step?.type === 'decision';

    useEffect(() => {
        setLabel(step?.label ?? '');
        setLane(step?.lane ?? lanes[0]?.key ?? '');
        setBranches(editsBranches ? decisionBranches(edges, step.key) : null);
        setError(null);
    }, [step?.key, step?.between?.join('\u0000')]);

    // Only while a decision is open. The branches state outlives the closing render — it is reset
    // by the effect, after it — so it cannot be what decides whether there is a decision to read.
    const shownBranches = editsBranches ? branches : null;
    const targets = editsBranches ? branchTargets(nodes, step.key) : [];
    // The validator's rules, checked before the save rather than at approval: a decision cannot be
    // saved from here with fewer than two named, distinct branches to steps that exist.
    const problems = shownBranches === null ? [] : branchProblems(shownBranches, nodes, step.key);
    const change = shownBranches === null ? { label, lane } : { label, lane, branches: shownBranches };
    const valid = stepEditIsValid(change, lanes) && problems.length === 0;

    function updateBranch(index, patch) {
        setBranches((current) => current.map((branch, at) => (at === index ? { ...branch, ...patch } : branch)));
    }

    function problemText(problem) {
        if (problem === 'branch_without_target') {
            return tb.branch_without_target ?? 'Hver gren må gå til et steg som finnes i flyten.';
        }

        return (problemTexts[problem] ?? problem).replace(':label', label.trim() || step.label);
    }

    function submit(event) {
        event.preventDefault();

        if (! valid || busy) {
            return;
        }

        setError(null);
        // Closed by the panel when the saved flow comes back; kept open with the reason when not.
        onSave(change, {
            onError: (errors) => setError(Object.values(errors)[0] ?? tb.step_edit_failed ?? 'Steget kunne ikke lagres.'),
        });
    }

    return (
        <ActionDialog isOpen={step !== null} onClose={onClose} closeDisabled={busy} titleId={titleId}>
            {step !== null && (
                <form onSubmit={submit}>
                    <h2 id={titleId} className="text-xl font-semibold tracking-tight text-slate-950">
                        {inserting
                            ? (tb.step_insert_heading ?? 'Legg til aktivitet')
                            : step.type === 'decision'
                            ? (tb.step_edit_heading_decision ?? 'Rediger beslutning')
                            : (tb.step_edit_heading ?? 'Rediger steg')}
                    </h2>

                    {inserting && (
                        <p className="mt-2 text-sm leading-5 text-slate-600">
                            {(tb.step_insert_between ?? 'Mellom «:from» og «:to».')
                                .replace(':from', step.between?.[0] ?? '')
                                .replace(':to', step.between?.[1] ?? '')}
                        </p>
                    )}

                    <label className="mt-5 block space-y-1">
                        <span className="block text-sm font-semibold text-slate-700">{tb.step_edit_label ?? 'Aktivitet'}</span>
                        <input
                            type="text"
                            className={INPUT}
                            value={label}
                            maxLength={200}
                            onChange={(event) => setLabel(event.target.value)}
                            disabled={busy}
                        />
                    </label>

                    <label className="mt-4 block space-y-1">
                        <span className="block text-sm font-semibold text-slate-700">{tb.step_edit_lane ?? 'Ansvarlig rolle'}</span>
                        <select
                            className={INPUT}
                            value={lane}
                            onChange={(event) => setLane(event.target.value)}
                            disabled={busy}
                        >
                            {lanes.map((candidate) => (
                                <option key={candidate.key} value={candidate.key}>
                                    {candidate.label || (tb.default_lane ?? 'Uten angitt rolle')}
                                </option>
                            ))}
                        </select>
                    </label>

                    {shownBranches !== null && (
                        <fieldset className="mt-5">
                            <legend className="block text-sm font-semibold text-slate-700">{tb.branches_heading ?? 'Grener'}</legend>
                            <p className="mt-1 text-sm leading-5 text-slate-500">
                                {tb.branches_help ?? 'Hvert utfall av beslutningen går til et steg som allerede finnes i flyten.'}
                            </p>

                            <ul className="mt-3 space-y-2">
                                {shownBranches.map((branch, index) => (
                                    // By position: a branch has no identity of its own until it is saved as an arrow.
                                    <li key={index} className="flex flex-wrap items-end gap-2">
                                        <label className="min-w-[8rem] flex-1 space-y-1">
                                            <span className="block text-xs font-semibold text-slate-600">{tb.branch_label ?? 'Utfall'}</span>
                                            <input
                                                type="text"
                                                className={INPUT}
                                                value={branch.label}
                                                maxLength={60}
                                                placeholder={tb.branch_label_placeholder ?? 'For eksempel Ja'}
                                                onChange={(event) => updateBranch(index, { label: event.target.value })}
                                                disabled={busy}
                                            />
                                        </label>
                                        <label className="min-w-[10rem] flex-[2] space-y-1">
                                            <span className="block text-xs font-semibold text-slate-600">{tb.branch_target ?? 'Går til'}</span>
                                            <select
                                                className={INPUT}
                                                value={branch.to}
                                                onChange={(event) => updateBranch(index, { to: event.target.value })}
                                                disabled={busy}
                                            >
                                                {! targets.some((node) => node.key === branch.to) && (
                                                    <option value={branch.to} disabled>{tb.branch_target_choose ?? 'Velg steg …'}</option>
                                                )}
                                                {targets.map((node) => (
                                                    <option key={node.key} value={node.key}>
                                                        {(node.label || node.key)
                                                            + ((node.type ?? 'step') === 'step' ? '' : ` (${(tb.node_types ?? {})[node.type] ?? node.type})`)}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <button
                                            type="button"
                                            className="rounded-xl px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-40"
                                            onClick={() => setBranches((current) => current.filter((_, at) => at !== index))}
                                            disabled={busy}
                                            aria-label={(tb.branch_remove ?? 'Fjern grenen «:label»').replace(':label', branch.label.trim() || String(index + 1))}
                                        >
                                            {tb.branch_remove_short ?? 'Fjern'}
                                        </button>
                                    </li>
                                ))}
                            </ul>

                            <button
                                type="button"
                                className="mt-3 text-sm font-semibold text-sky-800 hover:underline disabled:opacity-40"
                                onClick={() => setBranches((current) => [...current, { label: '', to: targets[0]?.key ?? '' }])}
                                disabled={busy || targets.length === 0}
                            >
                                + {tb.branch_add ?? 'Legg til gren'}
                            </button>

                            {problems.length > 0 && (
                                <ul className="mt-3 list-disc space-y-1 rounded-xl border border-amber-200 bg-amber-50 py-2 pl-8 pr-3 text-sm text-amber-900">
                                    {problems.map((problem) => <li key={problem}>{problemText(problem)}</li>)}
                                </ul>
                            )}
                        </fieldset>
                    )}

                    <p className="mt-4 text-sm leading-5 text-slate-500">
                        {tb.step_edit_help ?? 'Endringen lagres i arbeidsversjonen. Den gjelder ikke før flyten er godkjent og publisert.'}
                    </p>

                    {/* Saving writes the working version as the editor holds it, so edits made under
                        "Rediger struktur manuelt" and not yet saved go with it. Said before the click. */}
                    {hasUnsavedChanges && (
                        <p className="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {tb.step_edit_unsaved_help ?? 'Du har ulagrede endringer i strukturen. De lagres sammen med dette steget.'}
                        </p>
                    )}

                    {error && (
                        <p role="alert" className="mt-3 text-sm font-medium text-rose-700">{error}</p>
                    )}

                    <div className="mt-6 flex flex-wrap items-center gap-3">
                        <button type="submit" className={PRIMARY_ACTION} disabled={! valid || busy}>
                            {busy
                                ? (tb.step_edit_saving ?? 'Lagrer …')
                                : inserting
                                ? (tb.step_insert_save ?? 'Legg til aktiviteten')
                                : (tb.step_edit_save ?? 'Lagre steget')}
                        </button>
                        <button type="button" className={SECONDARY_ACTION} onClick={onClose} disabled={busy}>
                            {tb.step_edit_cancel ?? 'Avbryt'}
                        </button>
                        {/* The box used to open the activity's knowledge; it still can, one step on. A
                            step not yet added has none. */}
                        {! inserting && typeof onOpenActivity === 'function' && <button
                            type="button"
                            className="ml-auto text-sm font-semibold text-sky-800 hover:underline disabled:opacity-40"
                            onClick={() => onOpenActivity(step.key)}
                            disabled={busy}
                        >
                            {tb.step_edit_articles ?? 'Kunnskap fra steget'} →
                        </button>}
                    </div>
                </form>
            )}
        </ActionDialog>
    );
}

/**
 * ← Hovedprosess / Underprosess
 *
 * The one navigation for a drilled-in reader, sitting directly above the diagram it belongs to.
 * The step back is the visible part: the parent is a link carrying the arrow, because "where do I
 * get out of here" is the question a reader three clicks into somebody else's process actually
 * asks. Where the reader is, is the last crumb, as plain emphasised text.
 *
 * Deeper than one level, the crumbs above the parent are links too — reaching the top is still one
 * click, not three — and the arrow stays on the parent, where it marks the single step back rather
 * than claiming to be the whole way out.
 *
 * It is still a breadcrumb, not a "tilbake" button: navigation goes through the same history-pushing
 * trail navigation as a drill-down, so browser back keeps working.
 */
function SubprocessTrail({ tb, rootTitle, trail, onNavigate }) {
    const ancestors = [{ id: null, title: rootTitle }, ...trail.slice(0, -1)];
    const current = trail[trail.length - 1];

    return (
        <nav
            className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-base"
            aria-label={tb.trail_label ?? 'Hvor du er i prosessen'}
        >
            {ancestors.map((crumb, index) => {
                const parent = index === ancestors.length - 1;

                return (
                    <span key={crumb.id ?? 'root'} className="flex items-center gap-1.5">
                        {index > 0 && <span aria-hidden="true" className="text-slate-400">/</span>}

                        <button
                            type="button"
                            className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500"
                            onClick={() => onNavigate(ancestors.slice(1, index + 1).map((step) => step.id))}
                            aria-label={parent ? (tb.trail_back ?? 'Tilbake til :title').replace(':title', crumb.title) : undefined}
                        >
                            {parent && <span aria-hidden="true">←</span>}
                            {crumb.title}
                        </button>
                    </span>
                );
            })}

            <span aria-hidden="true" className="text-slate-400">/</span>
            <span className="px-1 font-semibold text-slate-950" aria-current="page">{current.title}</span>
        </nav>
    );
}

/**
 * A subprocess, as it is stored on its own process.
 *
 * Read-only on purpose — see the panel's docblock. The one way to change it is the link at the
 * bottom, which goes to the process's own page, where it is the document being edited rather than a
 * step inside somebody else's.
 *
 * It can be drilled into further: a subprocess whose own steps are processes behaves exactly the
 * same, because the trail is just one hop longer.
 */
function SubprocessFlow({ tb, rootTitle, trail, blueprint, onNavigate, onOpenSubprocess, onOpenActivity = null }) {
    const current = trail[trail.length - 1];

    if (blueprint === null) {
        return (
            <section className={CARD}>
                <SubprocessTrail tb={tb} rootTitle={rootTitle} trail={trail} onNavigate={onNavigate} />
                <p className="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-base text-slate-600">
                    {tb.subprocess_without_flow ?? 'Denne underprosessen har ingen flyt ennå.'}
                </p>
                <SubprocessOwnPageLink tb={tb} current={current} />
            </section>
        );
    }

    return (
        <>
            <section className={CARD}>
                <SubprocessTrail tb={tb} rootTitle={rootTitle} trail={trail} onNavigate={onNavigate} />
                <p className="mt-2 max-w-2xl text-base leading-6 text-slate-600">
                    {tb.subprocess_intro ?? 'Dette er underprosessens egen flyt, slik den er lagret på den prosessen.'}
                </p>

                <div className="mt-4">
                    <ProcessSwimlaneDiagram
                        blueprint={blueprint}
                        title={`${tb.heading ?? 'Prosessflyt'} — ${current.title}`}
                        emptyText={tb.diagram_empty ?? 'Flyten har ingen steg å tegne.'}
                        tb={tb}
                        onOpenSubprocess={onOpenSubprocess}
                        onOpenActivity={onOpenActivity}
                    />
                </div>

                <SubprocessOwnPageLink tb={tb} current={current} />
            </section>

            <section className={CARD}>
                <h2 className="text-xl font-semibold text-slate-950">{tb.steps_heading ?? 'Steg'}</h2>
                <div className="mt-4">
                    <ProcessFlowStepList
                        tb={tb}
                        blueprint={blueprint}
                        onOpenSubprocess={onOpenSubprocess}
                        onOpenActivity={onOpenActivity}
                    />
                </div>
            </section>
        </>
    );
}

/**
 * Kept, but quiet.
 *
 * Navigation is the trail above the diagram now, and this link is not navigation — it is the way to
 * go edit the subprocess on its own page. Rendered as loudly as before it read as the way out, and
 * competed with the trail for exactly the reader who was looking for one.
 */
function SubprocessOwnPageLink({ tb, current }) {
    return (
        <p className="mt-4 border-t border-slate-100 pt-4">
            <Link
                href={`/app/quality/items/${current.id}?tab=flow`}
                className="text-sm text-slate-500 transition hover:text-slate-800 hover:underline"
            >
                {(tb.subprocess_open_page ?? 'Åpne :title som egen prosess').replace(':title', current.title)} →
            </Link>
        </p>
    );
}

/**
 * What the process is for, repeated here rather than linked to.
 *
 * The flow is drawn against the purpose — "does this picture do what the document says it does?" —
 * and sending the reader back to another tab to check breaks exactly the comparison this tab is for.
 *
 * It is headed "Formål", which is what the field it mirrors is called under Dokument. It was headed
 * "Prosessbeskrivelse", directly above the field where the process description is actually written,
 * and a reader who went looking for that field found this read-only card first.
 */
function DescriptionCard({ tq, tb, item }) {
    const stepCount = (item.steps ?? []).length;

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tb.description_heading ?? 'Formål'}</h2>

            {item.purpose ? (
                <p className="mt-2 max-w-3xl whitespace-pre-line text-base leading-6 text-slate-700">{item.purpose}</p>
            ) : (
                <p className="mt-2 text-base text-slate-500">
                    {tb.description_empty ?? 'Prosessen har ingen beskrevet hensikt ennå.'}
                </p>
            )}

            <p className="mt-3 text-sm text-slate-500">
                {stepCount > 0
                    ? (tb.steps_summary ?? ':count steg er beskrevet.').replace(':count', String(stepCount))
                    : (tb.steps_summary_empty ?? 'Ingen steg er beskrevet ennå.')}
            </p>

            {item.owner_name && (
                <p className="mt-1 text-sm text-slate-500">
                    {(tq.field_owner ?? 'Eier')}: {item.owner_name}
                </p>
            )}
        </section>
    );
}

/**
 * Where the user describes the process in their own words.
 *
 * It is a plain textarea and a button on purpose. The thing being asked for is a paragraph of
 * Norwegian about how work actually gets done — the user's own competence — and a form that asked
 * for steps, roles and branches separately would be asking them to do the structuring themselves,
 * which is the work Procynia is here to do.
 *
 * Nothing here is a technical representation. The user never sees a node, an edge, a schema or a
 * key; they see their sentence and, afterwards, a picture of it.
 */
function InterpretCard({ tb, value, setValue, onSubmit, available, hasFlow, busy, error }) {
    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tb.ai_heading ?? 'Beskriv prosessen'}</h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                {tb.ai_help ?? 'Skriv med egne ord hvordan prosessen gjennomføres.'}
            </p>

            {! available ? (
                <p className="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-base text-slate-600">
                    {tb.ai_unavailable ?? 'AI-tolkning er ikke tilgjengelig i denne installasjonen.'}
                </p>
            ) : (
                <>
                    {/*
                      * The label is on screen, not only for a screen reader. The card above this
                      * one is headed with the process's purpose, and while this field's own label
                      * was hidden, the only thing on the tab that said "prosessbeskrivelse" was
                      * that read-only card — so the one box you can actually write the description
                      * in looked like it belonged to something else.
                      */}
                    <label className="mt-4 block space-y-1">
                        <span className="block text-sm font-semibold text-slate-700">
                            {tb.ai_label ?? 'Prosessbeskrivelse'}
                        </span>
                        <textarea
                            className={TEXTAREA}
                            rows={6}
                            value={value}
                            placeholder={tb.ai_placeholder ?? ''}
                            aria-label={tb.ai_label ?? 'Prosessbeskrivelse'}
                            aria-invalid={error ? 'true' : undefined}
                            onChange={(event) => setValue(event.target.value)}
                            disabled={busy}
                        />
                    </label>

                    {error && <p className="mt-2 text-sm font-medium text-rose-700">{error}</p>}

                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            className={PRIMARY_ACTION}
                            // Called with no arguments on purpose. onClick hands its handler the
                            // click event, and onSubmit takes the description to interpret — wired
                            // straight through, the event becomes the request payload, and Inertia
                            // walks it looking for files until the stack runs out. Nothing here
                            // wants the event, so nothing here is given it.
                            onClick={() => onSubmit()}
                            // Off only while a reading is actually running. It used to be off
                            // until this component's own copy of the text was non-empty, which
                            // made the one control on the tab depend on client state the user
                            // cannot see or correct: anything that kept `value` empty left a
                            // button that could not be pressed and said nothing about why. The
                            // floor on the description is enforced where it is authoritative —
                            // the request is refused before any model is called, and the reason
                            // comes back into the field's error line below.
                            disabled={busy}
                        >
                            {busy ? (tb.ai_working ?? 'Leser beskrivelsen …') : (tb.ai_submit ?? 'Generer prosessflyt')}
                        </button>

                        {/* Said before the button is pressed, not after: the reassurance is only
                            worth anything to someone deciding whether to press it. */}
                        {hasFlow && (
                            <p className="text-sm text-slate-500">
                                {tb.ai_replace_warning ?? 'Du ser forslaget før noe erstattes.'}
                            </p>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}

/**
 * The description was read, but what came back does not hold together as a process.
 *
 * The problems are listed rather than summarised because each one is a sentence the user can act
 * on — "beslutningen «Er bestillingen stor?» har bare én vei videre" tells them exactly which
 * sentence of theirs to finish. A generic failure message would leave them re-reading the whole
 * description looking for the bit Procynia did not understand.
 */
function FlowErrorCard({ tb, flowError }) {
    return (
        <section className={`${CARD} border-amber-300 bg-amber-50`}>
            <h2 className="text-lg font-semibold text-amber-900">
                {tb.error_heading ?? 'Flyten kunne ikke brukes'}
            </h2>
            <p className="mt-1 text-base leading-6 text-amber-900">{flowError.message}</p>

            {(flowError.problems ?? []).length > 0 && (
                <ul className="mt-3 list-disc space-y-1 pl-5 text-base leading-6 text-amber-900">
                    {flowError.problems.map((problem) => <li key={problem}>{problem}</li>)}
                </ul>
            )}
        </section>
    );
}

/**
 * This is a proposal, and it is not saved.
 *
 * Stated at the top of the flow rather than beside the button, because the user is about to spend a
 * minute reading a diagram and has to know what they are reading before they start.
 *
 * THE TWO QUESTION LISTS.
 *
 * Blocking questions are things the description does not say and the flow cannot be believed
 * without — shown in amber, above the structure, because a guess the user never agreed to must not
 * be able to hide inside the proposal.
 *
 * Optional clarifications are the opposite: a term the process decides on and the description never
 * defines. They change nothing about the flow and are deliberately quiet — a plain panel below the
 * blocking one — because the failure mode this feature has to avoid is the user reading a list of
 * suggestions as a list of chores and tuning their description forever instead of using the flow.
 *
 * Each one carries its own two answers rather than sitting in a bullet list, because a suggestion
 * the user cannot do anything about is a suggestion they learn to read past. "Avvis" is what makes
 * the quiet panel safe to keep showing: a note that can be turned off for good is a note nobody has
 * to tune their description to silence.
 *
 * Neither list disables anything. "Bruk denne prosessflyten" is available with questions on screen,
 * and the copy in both panels says so, because a user who believes they have to answer first will
 * answer first.
 */
function ProposalNotice({ tb, replaces, blocking, optional, onClarify, onDecline, busy, canEdit }) {
    return (
        <div className="mt-4 space-y-4">
            <div className="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                <p className="text-base font-semibold text-sky-950">
                    {tb.proposal_heading ?? 'Forslag til prosessflyt'}
                </p>
                <p className="mt-1 text-base leading-6 text-sky-900">
                    {tb.proposal_intro ?? 'Ingenting er lagret ennå.'}
                </p>
                {replaces && (
                    <p className="mt-2 text-sm text-sky-800">
                        {tb.proposal_replaces ?? 'Flyten som er lagret nå, blir erstattet når du tar forslaget i bruk.'}
                    </p>
                )}
            </div>

            {blocking.length > 0 && (
                <div className="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                    <p className="text-base font-semibold text-amber-950">
                        {tb.blocking_questions_heading ?? 'Dette kommer ikke fram av beskrivelsen'}
                    </p>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-base leading-6 text-amber-900">
                        {blocking.map((question) => <li key={question}>{question}</li>)}
                    </ul>
                    <p className="mt-2 text-sm text-amber-800">
                        {tb.blocking_questions_help ?? 'Procynia har ikke gjettet på svarene.'}
                    </p>
                </div>
            )}

            {optional.length > 0 && (
                <ClarificationSuggestions
                    tb={tb}
                    questions={optional}
                    onClarify={onClarify}
                    onDecline={onDecline}
                    busy={busy}
                    canEdit={canEdit}
                />
            )}
        </div>
    );
}

/**
 * One suggestion, two answers: say what the term means, or say it does not need one.
 *
 * "Avvis" removes it here and now. It is also sent to the server, which is what stops the same note
 * coming back on the next generation — but the screen does not wait for that, because a user who
 * dismisses something and watches it sit there will click it again.
 *
 * "Avklar" opens one field. The answer is woven into the process description — into the sentence
 * that raised it, by a rewrite — and the revised description is read again, so the clarification
 * ends up in the one text the process is written down in rather than in a conversation beside it.
 * The question is never written into that text; an answered suggestion leaves no trace except a
 * description that now says what it means. That is also why this is not a wizard: there is no
 * sequence to work through, no state between the suggestions, and no step that has to be completed.
 * Answer one, answer none, dismiss the rest — the flow is adoptable either way.
 */
function ClarificationSuggestions({ tb, questions, onClarify, onDecline, busy, canEdit }) {
    const [answering, setAnswering] = useState(null);
    const [answer, setAnswer] = useState('');

    function open(question) {
        setAnswering(question);
        setAnswer('');
    }

    function close() {
        setAnswering(null);
        setAnswer('');
    }

    return (
        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5">
            <p className="text-base font-semibold text-slate-900">
                {tb.optional_clarifications_heading ?? 'Verdt å presisere'}
            </p>
            <p className="mt-1 text-sm leading-6 text-slate-600">
                {tb.optional_clarifications_help ?? 'Du kan ta flyten i bruk uten å svare på dette.'}
            </p>

            <ul className="mt-3 space-y-3">
                {questions.map((question) => (
                    <li key={question} className="rounded-xl border border-slate-200 bg-white p-4">
                        <p className="text-base leading-6 text-slate-800">{question}</p>

                        {answering === question ? (
                            <div className="mt-3 space-y-2">
                                <label className="block text-sm font-semibold text-slate-700" htmlFor="clarification-answer">
                                    {tb.clarification_answer_label ?? 'Svar'}
                                </label>
                                <textarea
                                    id="clarification-answer"
                                    className={TEXTAREA}
                                    rows={2}
                                    value={answer}
                                    onChange={(event) => setAnswer(event.target.value)}
                                    placeholder={tb.clarification_answer_placeholder ?? ''}
                                />
                                <p className="text-sm text-slate-500">
                                    {tb.clarification_answer_help ?? 'Procynia skriver om beskrivelsen slik at svaret inngår i den, og tolker flyten på nytt. Spørsmålet blir ikke stående i beskrivelsen.'}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        className={ROW_ADD}
                                        disabled={busy || answer.trim() === ''}
                                        onClick={() => {
                                            onClarify(question, answer);
                                            close();
                                        }}
                                    >
                                        {tb.clarification_submit ?? 'Bruk svaret'}
                                    </button>
                                    <button type="button" className={ROW_ADD} onClick={close} disabled={busy}>
                                        {tb.clarification_cancel ?? 'Avbryt'}
                                    </button>
                                </div>
                            </div>
                        ) : canEdit && (
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button type="button" className={ROW_ADD} onClick={() => open(question)} disabled={busy}>
                                    {tb.clarification_clarify ?? 'Avklar'}
                                </button>
                                <button
                                    type="button"
                                    className={ROW_ADD}
                                    onClick={() => onDecline(question)}
                                    disabled={busy}
                                >
                                    {tb.clarification_dismiss ?? 'Avvis'}
                                </button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Where the process stands: not published, the revision in force, or retired — and, separately,
 * whether the working version has moved on from what is in force. Unsaved edits count as
 * unpublished changes too; they are not even saved yet.
 */
function StatusLine({ tq, blueprint, publication, isDirty, current }) {
    const tb = tq.blueprint ?? {};
    const tp = tq.publication ?? {};
    const sourceLabels = tb.sources ?? {};
    const state = publication?.state ?? 'unpublished';
    const hasChanges = current !== null && state !== 'retired'
        && (publication?.has_unpublished_changes || isDirty);

    return (
        <div className="mt-4 flex flex-wrap items-center gap-3">
            <StatusBadge tone={state === 'retired' || state === 'unpublished' ? 'slate' : 'green'}>
                {state === 'retired' && (tp.retired ?? 'Utgått')}
                {state === 'unpublished' && (tp.unpublished ?? 'Ikke publisert')}
                {(state === 'current' || state === 'current_with_changes')
                    && (tp.flow_current ?? 'Gjeldende revisjon :number')
                        .replace(':number', String(publication?.revision_number ?? '—'))}
            </StatusBadge>

            {hasChanges && (
                <StatusBadge tone="amber">
                    {tp.unpublished_changes ?? 'Arbeidsversjonen har upubliserte endringer'}
                </StatusBadge>
            )}

            {blueprint && (
                <StatusBadge tone="slate">{sourceLabels?.[blueprint.source] ?? blueprint.source}</StatusBadge>
            )}

            <span className="text-sm text-slate-500">
                {state === 'unpublished' && (tp.unpublished_help
                    ?? 'Prosessen blir gjeldende når flyten er godkjent og publisert første gang.')}
                {state === 'retired' && current && (tp.retired_help
                    ?? 'Prosessen er utgått. Siste revisjon (:number) er beholdt i historikken, men gjelder ikke lenger.')
                    .replace(':number', String(current.revision_number))}
                {state !== 'unpublished' && state !== 'retired' && current
                    && (tb.history_meta ?? 'Godkjent av :name :date')
                        .replace(':name', current.approved_by_name ?? '—')
                        .replace(':date', current.approved_at ?? '—')}
            </span>

            {blueprint?.generated_at && (
                <span className="text-sm text-slate-400">
                    {(tb.generated_notice ?? 'Sist generert :date.').replace(':date', blueprint.generated_at)}
                </span>
            )}
        </div>
    );
}

/**
 * What has been approved, newest first. A record, not an editor: no diff and no rollback yet.
 */
function RevisionHistory({ tb, revisions }) {
    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tb.history_heading ?? 'Godkjente revisjoner'}</h2>
            <ul className="mt-4 divide-y divide-slate-100">
                {revisions.map((revision) => (
                    <li key={revision.revision_number} className="flex flex-wrap items-baseline gap-x-3 py-2">
                        <span className="text-base font-semibold text-slate-900">
                            {(tb.history_row ?? 'Revisjon :number').replace(':number', String(revision.revision_number))}
                        </span>
                        <span className="text-sm text-slate-500">
                            {(tb.history_meta ?? 'Godkjent av :name :date')
                                .replace(':name', revision.approved_by_name ?? '—')
                                .replace(':date', revision.approved_at ?? '—')}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * The lanes, in the order they are drawn top to bottom.
 *
 * Deleting a lane takes its nodes with it, which is why the editor owns `setNodes` too. The
 * alternative — leaving the nodes behind in a lane that no longer exists — is exactly the dangling
 * state the backend would silently repair by moving them somewhere the user did not choose.
 */
function LaneEditor({ tb, lanes, setLanes, nodes, setNodes, canEdit }) {
    function update(index, value) {
        setLanes(lanes.map((lane, i) => (i === index ? { ...lane, label: value } : lane)));
    }

    function remove(index) {
        const removed = lanes[index];

        setLanes(lanes.filter((_, i) => i !== index));
        setNodes(nodes.filter((node) => node.lane !== removed.key));
    }

    function add() {
        setLanes([...lanes, { key: `lane-${Date.now()}`, label: '' }]);
    }

    return (
        <div>
            <h3 className={SUBHEADING}>{tb.lanes_heading ?? 'Roller'}</h3>

            {lanes.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">{tb.lanes_empty ?? 'Ingen roller er definert.'}</p>
            ) : (
                <ul className="mt-3 space-y-2">
                    {lanes.map((lane, index) => (
                        <li key={lane.key} className="flex items-center gap-3">
                            <input
                                className={INPUT}
                                value={lane.label ?? ''}
                                aria-label={tb.lane_label ?? 'Rolle'}
                                onChange={(event) => update(index, event.target.value)}
                                disabled={! canEdit}
                            />
                            {canEdit && (
                                <button type="button" className={ROW_DESTRUCTIVE} onClick={() => remove(index)}>
                                    {tb.remove_row ?? 'Fjern'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canEdit && (
                <button type="button" className={`mt-3 ${ROW_ADD}`} onClick={add}>
                    {tb.add_lane ?? 'Legg til rolle'}
                </button>
            )}
        </div>
    );
}

/**
 * The nodes: what happens, who does it, and what kind of thing it is.
 *
 * Removing a node removes the arrows that reached it, here rather than on the server, so the
 * diagram beside the editor is correct immediately instead of after a save.
 */
function NodeEditor({ tb, nodeTypeLabels, lanes, nodes, setNodes, edges, setEdges, canEdit, subprocessOptions = [] }) {
    function update(index, field, value) {
        setNodes(nodes.map((node, i) => (i === index ? { ...node, [field]: value } : node)));
    }

    /**
     * Point a step at another process, or at none.
     *
     * The id is what gets saved; the matching option is copied onto the node as well so the pill
     * appears on the diagram on the change rather than after the save. The server rewrites it with
     * what the reference actually resolves to on the way back, and that answer wins.
     */
    function setSubprocess(index, rawId) {
        const id = rawId === '' ? null : Number(rawId);
        const option = subprocessOptions.find((candidate) => candidate.id === id) ?? null;

        setNodes(nodes.map((node, i) => (i === index
            ? {
                ...node,
                subprocess_quality_item_id: id,
                subprocess: option === null ? null : {
                    id: option.id,
                    title: option.title,
                    code: option.code ?? null,
                    step_count: option.step_count ?? 0,
                },
            }
            : node)));
    }

    function remove(index) {
        const removed = nodes[index];

        setNodes(nodes.filter((_, i) => i !== index));
        setEdges(edges.filter((edge) => edge.from !== removed.key && edge.to !== removed.key));
    }

    function add() {
        setNodes([...nodes, {
            key: `node-${Date.now()}`,
            lane: lanes[0]?.key ?? '',
            type: 'step',
            label: '',
            description: null,
            subprocess_quality_item_id: null,
        }]);
    }

    return (
        <div>
            <h3 className={SUBHEADING}>{tb.nodes_heading ?? 'Noder'}</h3>

            {nodes.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">{tb.nodes_empty ?? 'Ingen noder er definert.'}</p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className={`w-full text-left text-sm ${subprocessOptions.length > 0 ? 'min-w-[820px]' : 'min-w-[640px]'}`}>
                        <thead className="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="pb-2 pr-3">{tb.node_label ?? 'Tekst'}</th>
                                <th className="pb-2 pr-3">{tb.node_lane ?? 'Rolle'}</th>
                                <th className="pb-2 pr-3">{tb.node_type ?? 'Type'}</th>
                                {/* Only offered where there is something to offer: a customer with
                                    one process has no other process to point at, and an empty
                                    dropdown is a question with no answers. */}
                                {subprocessOptions.length > 0 && (
                                    <th className="pb-2 pr-3">{tb.node_subprocess ?? 'Underprosess'}</th>
                                )}
                                {canEdit && <th className="pb-2" />}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {nodes.map((node, index) => (
                                <tr key={node.key}>
                                    <td className="py-2 pr-3">
                                        <input
                                            className={SMALL_INPUT}
                                            value={node.label ?? ''}
                                            onChange={(event) => update(index, 'label', event.target.value)}
                                            disabled={! canEdit}
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <select
                                            className={SMALL_INPUT}
                                            value={node.lane ?? ''}
                                            onChange={(event) => update(index, 'lane', event.target.value)}
                                            disabled={! canEdit}
                                        >
                                            {lanes.map((lane) => (
                                                <option key={lane.key} value={lane.key}>{lane.label}</option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="py-2 pr-3">
                                        <select
                                            className={SMALL_INPUT}
                                            value={node.type ?? 'step'}
                                            onChange={(event) => update(index, 'type', event.target.value)}
                                            disabled={! canEdit}
                                        >
                                            {NODE_TYPES.map((type) => (
                                                <option key={type} value={type}>
                                                    {nodeTypeLabels?.[type] ?? type}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    {subprocessOptions.length > 0 && (
                                        <td className="py-2 pr-3">
                                            <select
                                                className={SMALL_INPUT}
                                                value={node.subprocess_quality_item_id ?? ''}
                                                aria-label={tb.node_subprocess ?? 'Underprosess'}
                                                onChange={(event) => setSubprocess(index, event.target.value)}
                                                disabled={! canEdit}
                                            >
                                                <option value="">{tb.node_subprocess_none ?? '— ingen —'}</option>
                                                {subprocessOptions.map((option) => (
                                                    <option key={option.id} value={option.id}>{option.title}</option>
                                                ))}
                                            </select>
                                        </td>
                                    )}
                                    {canEdit && (
                                        <td className="py-2">
                                            <button
                                                type="button"
                                                className={ROW_DESTRUCTIVE}
                                                onClick={() => remove(index)}
                                            >
                                                {tb.remove_row ?? 'Fjern'}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {canEdit && lanes.length > 0 && (
                <button type="button" className={`mt-3 ${ROW_ADD}`} onClick={add}>
                    {tb.add_node ?? 'Legg til node'}
                </button>
            )}
        </div>
    );
}

/**
 * The arrows. `label` is the outcome a decision branches on — "Ja" / "Nei" — and is what makes a
 * branch readable; it is blank for an ordinary next step, where naming it would be noise.
 */
function EdgeEditor({ tb, nodes, edges, setEdges, canEdit }) {
    function update(index, field, value) {
        setEdges(edges.map((edge, i) => (i === index ? { ...edge, [field]: value } : edge)));
    }

    function remove(index) {
        setEdges(edges.filter((_, i) => i !== index));
    }

    function add() {
        setEdges([...edges, {
            from: nodes[0]?.key ?? '',
            to: nodes[Math.min(1, nodes.length - 1)]?.key ?? '',
            label: null,
        }]);
    }

    return (
        <div>
            <h3 className={SUBHEADING}>{tb.edges_heading ?? 'Forbindelser'}</h3>

            {edges.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">{tb.edges_empty ?? 'Ingen forbindelser er definert.'}</p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full min-w-[640px] text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="pb-2 pr-3">{tb.edge_from ?? 'Fra'}</th>
                                <th className="pb-2 pr-3">{tb.edge_to ?? 'Til'}</th>
                                <th className="pb-2 pr-3">{tb.edge_label ?? 'Utfall'}</th>
                                {canEdit && <th className="pb-2" />}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {edges.map((edge, index) => (
                                <tr key={`${edge.from}->${edge.to}#${edge.label ?? ''}#${index}`}>
                                    <td className="py-2 pr-3">
                                        <NodeSelect
                                            nodes={nodes}
                                            value={edge.from}
                                            onChange={(value) => update(index, 'from', value)}
                                            disabled={! canEdit}
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <NodeSelect
                                            nodes={nodes}
                                            value={edge.to}
                                            onChange={(value) => update(index, 'to', value)}
                                            disabled={! canEdit}
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <input
                                            className={SMALL_INPUT}
                                            value={edge.label ?? ''}
                                            placeholder={tb.edge_label_placeholder ?? ''}
                                            onChange={(event) => update(index, 'label', event.target.value)}
                                            disabled={! canEdit}
                                        />
                                    </td>
                                    {canEdit && (
                                        <td className="py-2">
                                            <button
                                                type="button"
                                                className={ROW_DESTRUCTIVE}
                                                onClick={() => remove(index)}
                                            >
                                                {tb.remove_row ?? 'Fjern'}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {canEdit && nodes.length > 1 && (
                <button type="button" className={`mt-3 ${ROW_ADD}`} onClick={add}>
                    {tb.add_edge ?? 'Legg til forbindelse'}
                </button>
            )}
        </div>
    );
}

function NodeSelect({ nodes, value, onChange, disabled }) {
    return (
        <select
            className={SMALL_INPUT}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
            disabled={disabled}
        >
            {nodes.map((node) => (
                <option key={node.key} value={node.key}>{node.label || node.key}</option>
            ))}
        </select>
    );
}

/**
 * The fixed structure of an article drafted from an activity.
 *
 * The same seven sections, in the same order, as the backend assembles a drafted article from — see
 * ProcessActivityArticleAiClient::SECTIONS. Here they do one job: an article written by hand starts
 * as the same skeleton an AI draft arrives in, so the two are the same kind of document and a reader
 * opening either one in Wiki finds what they are looking for in the same place.
 *
 * Headings come from the customer's own translations, so the skeleton is in the language the article
 * will be written in.
 */
/**
 * Ingest-kjøringer som har stanset uten å produsere sider. Alt annet er underveis.
 *
 * Statusene er Wikis egne (EnterpriseWikiIngestRun). Vi speiler bare de tre som betyr «dette
 * kommer ikke til å bli en side uten at noen gjør noe» — resten er mellomstadier brukeren ikke
 * trenger å kjenne til.
 */
const STALLED_RUN_STATUSES = ['failed', 'escalated', 'cancelled'];

const ARTICLE_SECTIONS = [
    'purpose',
    'timing',
    'responsibility',
    'procedure',
    'criteria',
    'documentation',
    'process_context',
];

const ARTICLE_SECTION_FALLBACKS = {
    purpose: 'Formål',
    timing: 'Når aktiviteten utføres',
    responsibility: 'Ansvar',
    procedure: 'Fremgangsmåte',
    criteria: 'Viktige vurderinger og kriterier',
    documentation: 'Dokumentasjon og resultat',
    process_context: 'Relatert prosesskontekst',
};

function articleSkeleton(tb) {
    const headings = tb.article_sections ?? {};

    return ARTICLE_SECTIONS
        .map((section) => `## ${headings[section] ?? ARTICLE_SECTION_FALLBACKS[section]}\n\n`)
        .join('\n');
}

/**
 * One activity, and the knowledge it is the source of.
 *
 * THE DIRECTION THIS PANEL RUNS IN.
 *
 * An activity on a prosessflyt is where the virksomhet knows something that nobody has written
 * down. So the panel is not a place to attach an article that already exists — it is the place the
 * next one is written from. "Opprett kunnskapsartikkel" drafts it from where the activity sits in
 * the process — the role, the step before, the condition that sends the work there, what judges the
 * result — in the fixed structure every activity article has; the user reads and corrects the
 * draft; what is created is an ordinary Enterprise Wiki page in draft, and the user is taken to it,
 * because everything after that — editing, review, approval, publication — happens in Wiki.
 *
 * NOTHING OF THE ARTICLE LIVES HERE. The list above the button is read fresh from Wiki on every
 * page load: a title and how far the page has got through publication, nothing of what it says. The
 * panel cannot drift out of date with the article, because it holds none of it.
 *
 * A dialog rather than a third column: it is opened deliberately, worked in, and closed, and the
 * flow behind it is what the user came to the tab for.
 */
function ActivityArticlePanel({
    tb,
    itemId,
    activity,
    // Writing an article off an activity creates a new Wiki source, so it is quality.create —
    // the same permission as registering a new styrende dokument, not the one for editing one.
    canCreate,
    aiAvailable,
    draft = null,
    error = null,
    onClose,
}) {
    const titleId = 'process-activity-articles-title';
    const articles = activity?.articles ?? [];

    // The draft under the user's hands. Seeded from what came back from the server and then owned
    // entirely by them: what is created is the text in these two fields, never the model's, because
    // they may have rewritten every word of it.
    const [title, setTitle] = useState('');
    const [markdown, setMarkdown] = useState('');
    const [writing, setWriting] = useState(false);
    const [busy, setBusy] = useState(false);

    // A draft belongs to the activity it was asked for. One arriving for a different step must not
    // appear in this one's editor, which is what the key comparison is for.
    const mine = draft !== null && activity !== null && draft.activity_key === activity.key;
    const failed = error !== null && activity !== null && error.activity_key === activity.key;

    useEffect(() => {
        if (! mine) {
            return;
        }

        setTitle(draft.title ?? '');
        setMarkdown(draft.markdown ?? '');
        setWriting(true);
        setBusy(false);
    }, [mine, draft?.title, draft?.markdown]);

    useEffect(() => {
        if (failed) {
            setBusy(false);
        }
    }, [failed]);

    // Closing settles the draft: it was never stored, and leaving it in the editor would offer it
    // again beside a different step.
    function close() {
        setWriting(false);
        setTitle('');
        setMarkdown('');
        setBusy(false);
        onClose();
    }

    function requestDraft() {
        setBusy(true);

        router.post(`/app/quality/items/${itemId}/activities/article-draft`, {
            activity_key: activity.key,
        }, {
            // The panel is local state, and the draft comes back as a prop beside it — so the visit
            // must not reset the component that is going to show it.
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    }

    // Writing it by hand is a first-class way to do this, not a fallback: the point is to get the
    // knowledge into Wiki, and a user who knows what it says does not need a draft first. They start
    // from the same structure a draft arrives in — an empty box and a drafted article should not
    // produce two different kinds of page — and every word of it is theirs to change or delete.
    function writeByHand() {
        setTitle('');
        setMarkdown(articleSkeleton(tb));
        setWriting(true);
    }

    function create() {
        setBusy(true);

        router.post(`/app/quality/items/${itemId}/activities/articles`, {
            activity_key: activity.key,
            title,
            markdown,
        }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    }

    // Whether the form is submittable, which is a different question from whether this person
    // may create at all — that is the canCreate prop, and the dialog never offers the form without it.
    const canSubmit = title.trim() !== '' && markdown.trim() !== '' && ! busy;

    return (
        <ActionDialog isOpen={activity !== null} onClose={close} titleId={titleId}>
            {activity !== null && (
                <div className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-[24px] bg-white p-6 shadow-xl">
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {activity.role || (tb.default_lane ?? 'Uten angitt rolle')}
                    </p>
                    <h2 id={titleId} className="mt-1 text-xl font-semibold text-slate-950">{activity.label}</h2>

                    {activity.description && (
                        <p className="mt-2 text-base leading-6 text-slate-600">{activity.description}</p>
                    )}

                    <h3 className="mt-6 text-base font-semibold text-slate-900">
                        {tb.articles_heading ?? 'Kunnskap fra denne aktiviteten'}
                    </h3>

                    {articles.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">
                            {tb.articles_empty ?? 'Denne aktiviteten har ikke gitt noen kunnskap ennå.'}
                        </p>
                    ) : (
                        <ul className="mt-3 space-y-2">
                            {articles.map((entry, index) => (
                                <li
                                    key={entry.page_id ?? `source-${index}`}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3"
                                >
                                    {/* A source that Wiki has not yet turned into pages has nothing
                                        to open, so it is shown as text rather than as a dead link. */}
                                    {entry.url ? (
                                        <>
                                            <div className="min-w-0">
                                                <a
                                                    href={entry.url}
                                                    className="text-base font-semibold text-slate-900 underline-offset-2 hover:underline"
                                                >
                                                    {entry.title}
                                                </a>
                                                {entry.publication?.state_label && (
                                                    <p className="mt-0.5 text-xs text-slate-500">{entry.publication.state_label}</p>
                                                )}
                                            </div>

                                            <a href={entry.url} className="text-sm font-semibold text-slate-600 hover:text-slate-950">
                                                {tb.articles_open_page ?? 'Åpne i Wiki'} →
                                            </a>
                                        </>
                                    ) : (
                                        <div className="min-w-0">
                                            <p className="text-base font-semibold text-slate-900">{entry.title}</p>
                                            <p className="mt-0.5 text-xs text-slate-500">
                                                {STALLED_RUN_STATUSES.includes(entry.status)
                                                    ? (tb.articles_pending_failed
                                                        ?? 'Wiki kom ikke i mål med denne artikkelen. Se Wiki → Kildedokumenter.')
                                                    : (tb.articles_pending
                                                        ?? 'Wiki bygger kunnskapssidene av denne artikkelen nå.')}
                                            </p>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {failed && (
                        <p className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            {error.message}
                        </p>
                    )}

                    {canCreate && ! writing && (
                        <div className="mt-6 border-t border-slate-100 pt-5">
                            <p className="text-sm leading-5 text-slate-600">
                                {tb.articles_help
                                    ?? 'Skriv ned kunnskapen bak dette steget. Procynia lager et utkast du kan rette, og artikkelen legges inn som kilde i Enterprise Wiki — som bygger kunnskapssidene av den på vanlig måte.'}
                            </p>

                            <p className="mt-2 text-sm leading-5 text-slate-500">
                                {tb.articles_structure_help
                                    ?? 'Artikkelen følger en fast struktur: Formål, Når aktiviteten utføres, Ansvar, Fremgangsmåte, Viktige vurderinger og kriterier, Dokumentasjon og resultat, Relatert prosesskontekst.'}
                            </p>

                            <div className="mt-4 flex flex-wrap gap-3">
                                {aiAvailable && (
                                    <button type="button" className={PRIMARY_ACTION} onClick={requestDraft} disabled={busy}>
                                        {busy
                                            ? (tb.articles_drafting ?? 'Lager utkast …')
                                            // With knowledge already listed above, the button adds
                                            // to it rather than reading as the first article.
                                            : articles.length > 0
                                                ? (tb.articles_draft_another ?? 'Opprett ny kunnskapsartikkel')
                                                : (tb.articles_draft ?? 'Opprett kunnskapsartikkel')}
                                    </button>
                                )}

                                <button type="button" className={SECONDARY_ACTION} onClick={writeByHand}>
                                    {tb.articles_write ?? 'Skriv artikkelen selv'}
                                </button>
                            </div>
                        </div>
                    )}

                    {canCreate && writing && (
                        <div className="mt-6 border-t border-slate-100 pt-5">
                            <p className="text-sm leading-5 text-slate-600">
                                {tb.articles_review_help
                                    ?? 'Les gjennom og rett teksten før du legger den inn. Det som legges inn, er det som står her — Wiki bygger kunnskapssidene av teksten og sender dem til vanlig gjennomgang.'}
                            </p>

                            <p className="mt-2 text-sm leading-5 text-slate-500">
                                {tb.articles_fill_in_help
                                    ?? 'Står det «Må fylles inn» et sted, er det kunnskap Procynia ikke fant i prosessen. Fyll det inn selv — ikke la det stå.'}
                            </p>

                            <label className="mt-4 block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="activity-article-title">
                                {tb.articles_title ?? 'Tittel'}
                            </label>
                            <input
                                id="activity-article-title"
                                type="text"
                                className={`mt-1 ${INPUT}`}
                                value={title}
                                maxLength={255}
                                onChange={(event) => setTitle(event.target.value)}
                            />

                            <label className="mt-4 block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="activity-article-markdown">
                                {tb.articles_body ?? 'Artikkel'}
                            </label>
                            <textarea
                                id="activity-article-markdown"
                                className={`mt-1 ${TEXTAREA}`}
                                rows={16}
                                value={markdown}
                                onChange={(event) => setMarkdown(event.target.value)}
                            />

                            <div className="mt-5 flex flex-wrap justify-end gap-3">
                                <button type="button" className={SECONDARY_ACTION} onClick={() => setWriting(false)}>
                                    {tb.articles_cancel ?? 'Avbryt'}
                                </button>
                                <button type="button" className={PRIMARY_ACTION} onClick={create} disabled={! canSubmit}>
                                    {tb.articles_create ?? 'Legg artikkelen inn i Wiki'}
                                </button>
                            </div>
                        </div>
                    )}

                    <div className="mt-6 flex justify-end border-t border-slate-100 pt-5">
                        <button type="button" className={SECONDARY_ACTION} onClick={close}>
                            {tb.articles_close ?? 'Lukk'}
                        </button>
                    </div>
                </div>
            )}
        </ActionDialog>
    );
}
