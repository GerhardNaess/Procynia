<?php

namespace App\Http\Controllers\App;

use App\Exceptions\Ai\AiCostControlException;
use App\Exceptions\EnterpriseWikiWithdrawalNotRepresentableException;
use App\Http\Controllers\Controller;
use App\Jobs\Quality\ProjectQualityItemToGraph;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityActivityControl;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityTool;
use App\Models\User;
use App\Services\Ai\Quality\ProcessActivityArticleAiClient;
use App\Services\Ai\Quality\ProcessFlowChangeAiClient;
use App\Services\Ai\Quality\ProcessFlowInterpretationAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Quality\Exceptions\ProcessFlowInterpretationException;
use App\Services\Quality\QualityActivityArticleService;
use App\Services\Quality\QualityActivityControlService;
use App\Services\Quality\QualityAttentionService;
use App\Services\Quality\QualityFlowClarificationService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Services\Quality\QualityProcessDescriptionClarifier;
use App\Services\Quality\QualityProcessFlowChangeProposer;
use App\Services\Quality\QualityProcessFlowInterpreter;
use App\Services\Quality\QualityProcessSubprocessService;
use App\Services\Quality\QualityToolService;
use App\Support\Ai\AiCostControlPresenter;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use App\Support\EnterpriseWikiDocumentFileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kvalitet — the virksomhet's styrende dokumenter as objects of their own.
 *
 * The module owns its content now. A policy, a process, a control is a row here, with its own owner,
 * number, status and review cycle, and it exists whether or not anybody has written it up in the
 * Wiki. Wiki is reached into for the knowledge behind a document — see the link endpoints — and
 * learns nothing from being reached into: no quality type is ever written onto a Wiki page.
 *
 * Entitlement is not checked here. Every route in this controller is named under `app.quality.`,
 * and config/procynia_modules.php maps that prefix to the `quality` module, so EnsureModuleIsEnabled
 * has already refused the request if the customer has not bought it — the write actions included.
 */
class QualityController extends Controller
{
    private const TABS = ['overview', 'processes', 'controls', 'tools'];

    /**
     * The tabs on one document's page.
     *
     * `document` is everything the document IS — its governance, its structure, its files, the Wiki
     * behind it. `flow` is the one view that is not text: how the process actually runs. Only a
     * process has it, and a process that has it still has everything else, so the flow is a second
     * tab rather than a replacement for the first.
     *
     * Server-driven, like the module's own tabs: the tab is in the URL, so a redirect after
     * generating or approving comes back to the tab the user was standing on.
     */
    private const DETAIL_TABS = ['document', 'flow'];

    /**
     * Which types each tab shows. Oversikt deliberately shows all of them: it is the whole
     * kvalitetssystem in one place, and the only tab policies, procedures and arbeidsinstrukser
     * appear on. The page lists them in separate sections — styrende dokumenter, prosesser and
     * kontroller are different kinds of object, even though all three are stored as quality items.
     *
     * @var array<string, list<string>>
     */
    private const TAB_TYPES = [
        'overview' => QualityItem::TYPES,
        'processes' => [QualityItem::TYPE_PROCESS],
        'controls' => [QualityItem::TYPE_CONTROL],
        // Verktøy is a library of files, not of quality items — see QualityToolService.
        'tools' => [],
    ];

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly QualityItemService $items,
        private readonly EnterpriseWikiPublicationStatusService $publicationStatus,
        private readonly EnterpriseWikiDocumentUploadService $documentUploads,
        private readonly QualityProcessBlueprintService $blueprints,
        private readonly QualityProcessFlowInterpreter $flowInterpreter,
        private readonly QualityFlowClarificationService $flowClarifications,
        private readonly QualityProcessDescriptionClarifier $flowClarifier,
        private readonly QualityProcessFlowChangeProposer $flowChanges,
        private readonly QualityProcessSubprocessService $subprocesses,
        private readonly QualityActivityArticleService $activityArticles,
        private readonly QualityActivityControlService $activityControls,
        private readonly CustomerPermissionService $permissions,
        private readonly QualityToolService $tools,
        private readonly QualityAttentionService $attention,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        // Reading the kvalitetssystem is itself a permission the customer grants. The module
        // entitlement says the virksomhet has Kvalitet; this says this person works in it.
        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_VIEW);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';

        return Inertia::render('App/Quality/Index', [
            'active_tab' => $tab,
            // What this person may do here, resolved from the customer's own roles. The page uses
            // it to decide what to offer; the gates above decide what is accepted.
            'permissions' => $this->permissionPayload($user),
            'items' => self::TAB_TYPES[$tab] === [] ? [] : $this->itemRows($customerId, self::TAB_TYPES[$tab], $user),
            // Oversikt opens on what needs attention: fixed rules over the rows above, recomputed
            // on every read and never stored. See QualityAttentionService.
            'attention' => $tab === 'overview' && $customerId !== null ? $this->attention->findings((int) $customerId) : [],
            // A control is not a document: it is listed by what it checks, who carries it out, how
            // often, how, and whether evidence has been recorded — on Kontroller, and in its own
            // section on Oversikt.
            'control_register' => in_array($tab, ['overview', 'controls'], true) ? $this->controlRegister($customerId) : (object) [],
            // Verktøy: the library, each tool with the controls carried out with it. The archive
            // picker lets a file the virksomhet already has become a tool without a second upload.
            'tools' => $tab === 'tools' && $customerId !== null ? $this->tools->library((int) $customerId) : [],
            'tool_categories' => QualityTool::CATEGORIES,
            'tool_document_options' => $tab === 'tools' ? $this->documentOptions($customerId, $request) : [],
            'document_search' => trim((string) $request->query('document_search', '')),
            'type_counts' => $this->typeCounts($customerId),
            'quality_types' => QualityItem::TYPES,
            'statuses' => QualityItem::STATUSES,
            'owner_options' => $this->ownerOptions($customerId),
        ]);
    }

    /**
     * One quality item, with the structure its type carries and the Wiki pages behind it.
     */
    public function show(Request $request, QualityItem $item): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_VIEW);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $item->loadMissing(['owner', 'processSteps', 'processIo', 'checklistItems', 'controlDetail']);

        $tab = $this->detailTab($request, $item);
        $isControl = $item->quality_type === QualityItem::TYPE_CONTROL;
        $subprocessView = $this->subprocessView($customerId, $item, $request);
        $publication = $customerId !== null && $item->quality_type === QualityItem::TYPE_PROCESS
            ? $this->blueprints->publicationState((int) $customerId, $item)
            : null;

        // Read here and handed on only through the domain props below. The page never receives the
        // generic list: "Fra", "Til" and a relation type are the model's words, not the user's.
        $relations = $this->relationsForItem($customerId, (int) $item->id);

        return Inertia::render('App/Quality/Item', [
            'item' => $this->itemDetail($item),
            'active_tab' => $tab,
            // Only a process has a flow, and the React page uses this to decide whether the tab
            // strip exists at all — a policy's page is unchanged by any of this.
            'has_flow' => $item->quality_type === QualityItem::TYPE_PROCESS,
            'blueprint' => $this->blueprintPayload($customerId, $item),
            // Whether the process is published, which revision is in force and whether the working
            // version has moved on — read from the revisions, never from the status field.
            'process_publication' => $publication,
            // Approved revisions, newest first. Separate from `blueprint` because they outlive it:
            // the working version can be edited back to draft, or deleted, and what was approved
            // before is still here.
            'blueprint_revisions' => $customerId !== null && $item->quality_type === QualityItem::TYPE_PROCESS
                ? $this->blueprints->history((int) $customerId, $item)
                : [],
            // Drill-down. The trail is in the URL, so the diagram area is server-driven exactly as
            // the tab is: a subprocess view survives a reload, a back button and a shared link, and
            // it is read from the subprocess's own blueprint every time it is opened.
            'subprocess_view' => $subprocessView,
            // `?activity=` opens one activity's panel on arrival — the way back from a control in
            // the register to where it is applied. A key the flow does not have opens nothing.
            'focus_activity_key' => $tab === 'flow' && is_string($request->query('activity'))
                ? $request->query('activity')
                : null,
            // Where a control is applied, so its page can lead back to the processes using it.
            'control_placements' => $customerId !== null && $item->quality_type === QualityItem::TYPE_CONTROL
                ? ($this->activityControls->placementsByControl((int) $customerId, [(int) $item->id])[(int) $item->id] ?? [])
                : [],
            'subprocess_options' => $this->subprocessOptions($customerId, $item, $user),
            // A draft article, flashed by the redirect that produced it. Nothing is stored until
            // the user has read it and pressed create — see QualityActivityArticleService.
            'activity_article_draft' => $this->flashedActivityArticleState($item, $subprocessView, 'activity_article_draft'),
            'activity_article_error' => $this->flashedActivityArticleState($item, $subprocessView, 'activity_article_error'),
            // A proposal is not stored, so it travels in the session across the one redirect
            // between interpreting a description and seeing the result. Reloading the page drops
            // it, which is the honest behaviour: nothing was adopted.
            'flow_proposal' => $this->flashedFlowState($item, 'flow_proposal'),
            'flow_error' => $this->flashedFlowState($item, 'flow_error'),
            // A proposed change to the flow that exists, on the same terms as a proposed flow: in
            // the session for one redirect, never stored, gone on the next visit.
            'flow_change_proposal' => $this->flashedFlowState($item, 'flow_change_proposal'),
            'flow_change_error' => $this->flashedFlowState($item, 'flow_change_error'),
            'flow_ai_available' => $item->quality_type === QualityItem::TYPE_PROCESS
                && ProcessFlowInterpretationAiClient::isAvailable(),
            'permissions' => $this->permissionPayload($user),
            'statuses' => $this->statusOptions($item, $publication),
            'frequencies' => QualityControlDetail::FREQUENCIES,
            'link_types' => QualityItemWikiLink::LINK_TYPES,
            'owner_options' => $this->ownerOptions($customerId),
            'wiki_links' => $this->wikiLinkRows($item, $user),
            'wiki_page_options' => $this->wikiPageOptions($customerId, $user, $request),
            'wiki_search' => trim((string) $request->query('wiki_search', '')),
            // On a control, evidence has its own section and its own form (name, description,
            // optional file), so the general document list leaves that capacity out rather than
            // offering a second way to record the same thing. Tools are the same: they have their
            // own section, and are never listed or chosen in the general one.
            'document_relation_types' => $isControl
                ? array_values(array_diff(QualityItemDocument::GENERAL_RELATION_TYPES, [QualityItemDocument::RELATION_TYPE_EVIDENCE]))
                : QualityItemDocument::GENERAL_RELATION_TYPES,
            'documents' => array_values(array_filter(
                $this->documentRows($item),
                static fn (array $row): bool => $row['relation_type'] !== QualityItemDocument::RELATION_TYPE_TOOL
                    && (! $isControl || $row['relation_type'] !== QualityItemDocument::RELATION_TYPE_EVIDENCE),
            )),
            'control_evidence' => $isControl ? $this->controlEvidenceRows($item) : [],
            'control_tools' => $isControl && $customerId !== null ? $this->tools->forControl((int) $customerId, $item) : [],
            'control_tool_options' => $isControl && $customerId !== null ? $this->tools->optionsForControl((int) $customerId, $item) : [],
            'document_options' => $this->documentOptions($customerId, $request),
            'document_search' => trim((string) $request->query('document_search', '')),
            // A process's styrende dokumenter are the policies that govern it — the incoming side
            // of the same `governs` rows the overview edits, never a separate store. Linking and
            // unlinking post to storeRelation()/destroyRelation() like every other relation.
            'governing_documents' => $item->quality_type === QualityItem::TYPE_PROCESS
                ? $this->governingDocuments($relations)
                : [],
            'governing_document_options' => $item->quality_type === QualityItem::TYPE_PROCESS
                ? $this->governingDocumentOptions($customerId, $relations)
                : [],
            // The other end of the same rows: on a policy, the processes it governs. Read-only
            // here; the link is made and removed on the process, under Styrende dokumenter.
            'governed_processes' => $item->quality_type === QualityItem::TYPE_POLICY
                ? $this->governedProcesses($relations)
                : [],
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_CREATE);

        $validated = $request->validate([
            'quality_type' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'review_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'last_reviewed_at' => ['nullable', 'date'],
            // The document itself, optional: a styrende dokument is registered the moment the
            // organisation decides it has one, which is routinely before anybody has written the
            // file. The same formats storeDocument() accepts, because this is the same store —
            // widening it here would put files into it that the ingest pipeline cannot read.
            'file' => ['nullable', 'file', 'mimes:pdf,docx', 'max:20480'],
        ]);

        $item = $this->items->createItem((int) $customerId, $validated, $user);

        // Deliberately after the item exists. createItem() is what rejects an unknown type, status
        // or owner, and uploading first would mean storing a file for a form that is about to come
        // back with a validation error. The reverse failure — a stored item whose upload failed — is
        // both rarer and visible: the user lands on the item page and sees no document there, with
        // the per-item upload ready to retry.
        if ($request->hasFile('file')) {
            $this->attachUploadedDocument($request->file('file'), (int) $customerId, $item, $user);
        }

        // A new process has nothing in force until its flow is described and approved, so that is
        // where the user is sent. Every other type has no flow and lands on its document.
        $route = $item->quality_type === QualityItem::TYPE_PROCESS
            ? ['item' => $item->id, 'tab' => 'flow']
            : ['item' => $item->id];

        return redirect()
            ->route('app.quality.items.show', $route)
            ->with('success', __($this->itemFlashKey($item, 'created')));
    }

    /**
     * Put the file from the create form into the virksomhet's store and hang it on the new item.
     *
     * The upload is the existing one — EnterpriseWikiDocumentUploadService, the same service
     * storeDocument() uses — so the file lands on the same private customer path under the same
     * SHA-256 identity, extracted the same way, and a file the customer already has is attached
     * rather than written twice.
     *
     * The relation is always `source`: the file arriving with the form is the document being
     * registered, not a template it uses or a record it leaves behind. Attaching a file in any
     * other capacity stays on the item page, where there is a field to say which.
     */
    private function attachUploadedDocument(
        UploadedFile $file,
        int $customerId,
        QualityItem $item,
        ?User $user,
    ): bool {
        // The uploader owns what they upload, when their role may own a document at all. No new
        // permission is introduced: an ownerless document is a state the store already supports,
        // and reassigning the owner stays where it is, in Wiki → Kildedokumenter.
        $ownerUserId = ($user?->canBeEnterpriseWikiDocumentOwner() ?? false) ? $user->id : null;

        $result = $this->documentUploads->store($customerId, $file, $ownerUserId, $user?->id);

        $this->items->linkDocument(
            $customerId,
            $item,
            $result['document'],
            QualityItemDocument::RELATION_TYPE_SOURCE,
            null,
            $user,
        );

        return (bool) $result['reused'];
    }

    public function updateItem(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string'],
            'review_interval_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'last_reviewed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $this->items->updateItem((int) $customerId, $item, $validated, $user);

        return back()->with('success', __($this->itemFlashKey($item, 'updated')));
    }

    /**
     * Deleting a styrende dokument.
     *
     * The redirect lands on the Kvalitet index, and keeps the tab the request carries. Deleting is
     * reachable from the list as well as from the document's own page, and a user who removed a
     * process from Prosesser means to go on looking at Prosesser — bouncing them to Oversikt would
     * make them find their way back before they could delete the next one. An unknown tab is
     * dropped rather than refused: the validity rule is the index's own, in self::TABS.
     */
    public function destroyItem(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_DELETE);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : null;
        $backToIndex = fn (): RedirectResponse => redirect()
            ->route('app.quality.index', $tab !== null ? ['tab' => $tab] : []);

        // The knowledge a process produced outlives the process by default. Removing it too is a
        // second decision, taken in the same dialog and never implied by the first.
        $deleteWikiPages = $request->boolean('delete_wiki_pages');
        $producedPages = $deleteWikiPages
            ? $this->items->producedWikiPages((int) $customerId, $item)
            : collect();

        // Deleting a Wiki page is Wiki's authority, not Kvalitet's: managing the kvalitetssystem
        // does not carry the right to remove a page from the Wiki. Checked page by page with the
        // Wiki's own rule, and all-or-nothing — a partial delete would leave the user believing
        // the process's knowledge was gone when some of it is still there.
        $undeletable = $producedPages->first(
            fn (EnterpriseWikiPage $page): bool => ! $user->canDeleteEnterpriseWikiPage($page),
        );

        if ($undeletable instanceof EnterpriseWikiPage) {
            return $backToIndex()->with('error', __('procynia.quality.errors.wiki_pages_not_deletable'));
        }

        try {
            $result = $this->items->deleteItem((int) $customerId, $item, $deleteWikiPages ? $user : null);
        } catch (EnterpriseWikiWithdrawalNotRepresentableException $e) {
            // Wiki fails closed, and so does this: the pages are untouched and the process is still
            // here, which is the half worth keeping.
            Log::warning('[PROCYNIA][QUALITY] Process deletion refused — Wiki could not let go of the pages it produced.', [
                'quality_item_id' => $item->id,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return $backToIndex()->with('error', __('procynia.quality.errors.wiki_page_deletion_failed'));
        }

        $message = __($this->itemFlashKey($item, 'deleted'));

        if ($result['wiki_pages_deleted'] > 0) {
            $message .= ' '.trans_choice(
                'procynia.quality.flash.item_deleted_wiki_pages',
                $result['wiki_pages_deleted'],
                ['count' => $result['wiki_pages_deleted']],
            );
        }

        return $backToIndex()->with('success', $message);
    }

    /**
     * Process structure, written as a set. See QualityItemService::replaceProcessSteps for why the
     * whole list travels rather than one row at a time.
     */
    public function updateStructure(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate([
            'steps' => ['sometimes', 'array'],
            'steps.*.title' => ['nullable', 'string', 'max:255'],
            'steps.*.description' => ['nullable', 'string', 'max:5000'],
            'steps.*.responsibility' => ['nullable', 'string', 'max:255'],
            'inputs' => ['sometimes', 'array'],
            'inputs.*.label' => ['nullable', 'string', 'max:255'],
            'inputs.*.description' => ['nullable', 'string', 'max:2000'],
            'outputs' => ['sometimes', 'array'],
            'outputs.*.label' => ['nullable', 'string', 'max:255'],
            'outputs.*.description' => ['nullable', 'string', 'max:2000'],
            'checklist_items' => ['sometimes', 'array'],
            'checklist_items.*.text' => ['nullable', 'string', 'max:2000'],
            'checklist_items.*.guidance' => ['nullable', 'string', 'max:2000'],
            'checklist_items.*.is_required' => ['nullable', 'boolean'],
            'control' => ['sometimes', 'array'],
            'control.criterion' => ['nullable', 'string', 'max:5000'],
            'control.responsibility' => ['nullable', 'string', 'max:255'],
            'control.frequency' => ['nullable', 'string'],
            'control.method' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('steps', $validated)) {
            $this->items->replaceProcessSteps((int) $customerId, $item, $validated['steps']);
        }

        if (array_key_exists('inputs', $validated)) {
            $this->items->replaceProcessIo((int) $customerId, $item, 'input', $validated['inputs']);
        }

        if (array_key_exists('outputs', $validated)) {
            $this->items->replaceProcessIo((int) $customerId, $item, 'output', $validated['outputs']);
        }

        if (array_key_exists('checklist_items', $validated)) {
            $this->items->replaceChecklistItems((int) $customerId, $item, $validated['checklist_items']);
        }

        if (array_key_exists('control', $validated)) {
            $this->items->updateControlDetail((int) $customerId, $item, $validated['control']);
        }

        return back()->with('success', __('procynia.quality.flash.structure_updated'));
    }

    // -----------------------------------------------------------------
    // Prosessflyt
    // -----------------------------------------------------------------

    /**
     * Save an edited flow.
     *
     * The whole blueprint travels, for the same reason the structure does: lanes, nodes and edges
     * are edited together and a node moved between lanes takes its edges with it. Validation here
     * is only about size and shape — what makes a payload drawable is
     * QualityProcessBlueprintService::normalise(), and it is the only thing allowed to decide that.
     */
    public function updateBlueprint(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate($this->blueprintRules());

        $this->blueprints->store(
            (int) $customerId,
            $item,
            $validated,
            QualityProcessBlueprint::SOURCE_MANUAL,
            $user,
        );

        return back()->with('success', __('procynia.quality.flash.blueprint_saved'));
    }

    /**
     * Read a plain-language description of the process into a proposed flow.
     *
     * NOTHING IS WRITTEN HERE. The proposal comes back to the user as a proposal: a diagram, the
     * steps behind it, and whatever the model could not work out from the text. They correct it,
     * adopt it or throw it away, and until they adopt it the flow they already had is untouched.
     * Overwriting first and apologising afterwards would mean the price of asking the question is
     * losing the answer you already had.
     *
     * Reproducibility lives in that same decision: once a flow is adopted it is data, and showing
     * it again redraws it from the stored payload. This endpoint is reached only when the user
     * presses the button.
     */
    public function interpretFlow(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        if (! ProcessFlowInterpretationAiClient::isAvailable()) {
            throw ValidationException::withMessages([
                'description' => __('procynia.quality.errors.flow_ai_disabled'),
            ]);
        }

        $validated = $request->validate([
            // A floor as well as a ceiling: two words cannot describe a process, and letting them
            // through only spends a provider call to tell the user something a rule can.
            'description' => ['required', 'string', 'min:30', 'max:8000'],
        ], [
            // Said in the user's own language and in terms of the field they are looking at. The
            // button is no longer withheld until the browser thinks the field has enough in it, so
            // this is the message an empty or thin description actually comes back with — it has
            // to tell the user what to do next, not name a rule.
            'description.required' => __('procynia.quality.errors.flow_description_required'),
            'description.min' => __('procynia.quality.errors.flow_description_too_short'),
            'description.max' => __('procynia.quality.errors.flow_description_too_long'),
        ]);

        try {
            $proposal = $this->flowInterpreter->interpret(
                $item,
                $validated['description'],
                $this->customerContext->resolveLanguageCode($user),
            );
        } catch (ProcessFlowInterpretationException|AiCostControlException $exception) {
            return $this->flowFailed($item, $user, $exception, $validated['description']);
        }

        return $this->flowProposed($item, $proposal);
    }

    /**
     * Ask for a change to the flow the process already has, in plain language.
     *
     * NOTHING IS WRITTEN HERE either. The model is shown the working version and returns operations
     * — add this step, reroute that arrow — which QualityProcessFlowChangeProposer applies to a copy
     * and validates. What reaches the user is the list of changes and the flow they would produce.
     * The working version is untouched until a later step accepts the change, and discarding the
     * proposal is nothing more than not accepting it.
     *
     * There has to be a flow to change. A process without one is described from scratch through
     * interpretFlow(), which is the right tool for that and a different question.
     */
    public function proposeFlowChange(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        if (! ProcessFlowChangeAiClient::isAvailable()) {
            throw ValidationException::withMessages([
                'instruction' => __('procynia.quality.errors.flow_ai_disabled'),
            ]);
        }

        $validated = $request->validate([
            'instruction' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'instruction.required' => __('procynia.quality.errors.flow_change_instruction_required'),
            'instruction.min' => __('procynia.quality.errors.flow_change_instruction_too_short'),
            'instruction.max' => __('procynia.quality.errors.flow_change_instruction_too_long'),
        ]);

        $blueprint = $this->blueprints->forItem((int) $customerId, $item);

        if ($blueprint === null) {
            throw ValidationException::withMessages([
                'instruction' => __('procynia.quality.errors.flow_change_without_flow'),
            ]);
        }

        try {
            $proposal = $this->flowChanges->propose(
                (int) $customerId,
                $item,
                $blueprint,
                $validated['instruction'],
                $this->customerContext->resolveLanguageCode($user),
            );
        } catch (ProcessFlowInterpretationException|AiCostControlException $exception) {
            return back()->with('flow_change_error', [
                'quality_item_id' => (int) $item->id,
                'message' => $exception instanceof AiCostControlException
                    ? app(AiCostControlPresenter::class)->message($exception, $user?->customer)
                    : $exception->getMessage(),
                'problems' => $exception instanceof ProcessFlowInterpretationException ? $exception->problems : [],
                'instruction' => $validated['instruction'],
            ]);
        }

        return back()->with('flow_change_proposal', [
            'quality_item_id' => (int) $item->id,
            'instruction' => $proposal['instruction'],
            'summary' => $proposal['summary'],
            'changes' => $proposal['changes'],
            'questions' => $proposal['questions'],
            // The flow the change would produce, for the preview — and the version it was made
            // against, so accepting it later can tell whether the working version has moved on.
            'lanes' => $proposal['payload']['lanes'],
            'nodes' => $proposal['payload']['nodes'],
            'edges' => $proposal['payload']['edges'],
            'base_hash' => $proposal['base_hash'],
            // What "Godta endringer" posts back. Re-applied server-side, never trusted as a flow.
            'operations' => $proposal['operations'],
        ]);
    }

    /**
     * "Godta endringer": put a proposed change into the working version, all of it at once.
     *
     * The browser sends the operations and the fingerprint of the version they were proposed
     * against — not the flow it previewed. QualityProcessFlowChangeProposer::accept() refuses when
     * the working version has moved on, and otherwise re-applies the operations and validates the
     * result with the validators the proposal passed. No model is called here.
     *
     * What it stores is an ordinary working version, through the same store() every edit goes
     * through: the approval is cleared, no revision is written, and the description is left alone
     * (null). Publishing remains "Godkjenn og publiser", by a person, later. `ai` is the source
     * because that is where this version of the flow came from — the same word adopting a
     * proposed flow writes.
     */
    public function acceptFlowChange(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        $validated = $request->validate([
            // Only so a refusal can put the instruction back in the field for the next proposal.
            'instruction' => ['nullable', 'string', 'max:2000'],
            'base_hash' => ['required', 'string', 'size:40'],
            'operations' => ['required', 'array', 'min:1', 'max:'.ProcessFlowChangeAiClient::MAX_OPERATIONS],
            'operations.*.op' => ['required', 'string', Rule::in(ProcessFlowChangeAiClient::OPS)],
            'operations.*.step' => ['nullable', 'string', 'max:80'],
            'operations.*.type' => ['nullable', 'string', Rule::in(ProcessFlowChangeAiClient::STEP_TYPES)],
            'operations.*.role' => ['nullable', 'string', 'max:120'],
            'operations.*.label' => ['nullable', 'string', 'max:200'],
            'operations.*.description' => ['nullable', 'string', 'max:2000'],
            'operations.*.from' => ['nullable', 'string', 'max:80'],
            'operations.*.to' => ['nullable', 'string', 'max:80'],
            'operations.*.condition' => ['nullable', 'string', 'max:60'],
        ]);

        $blueprint = $this->blueprints->forItem((int) $customerId, $item);

        if ($blueprint === null) {
            throw ValidationException::withMessages([
                'instruction' => __('procynia.quality.errors.flow_change_without_flow'),
            ]);
        }

        // Every field present, as the proposer reads them: the same flat shape the AI client hands it.
        $operations = array_map(static fn (array $operation): array => [
            'op' => $operation['op'],
            'step' => $operation['step'] ?? null,
            'type' => $operation['type'] ?? null,
            'role' => $operation['role'] ?? null,
            'label' => $operation['label'] ?? null,
            'description' => $operation['description'] ?? null,
            'from' => $operation['from'] ?? null,
            'to' => $operation['to'] ?? null,
            'condition' => $operation['condition'] ?? null,
        ], $validated['operations']);

        try {
            $payload = $this->flowChanges->accept((int) $customerId, $item, $blueprint, $operations, $validated['base_hash']);
        } catch (ProcessFlowInterpretationException $exception) {
            return back()->with('flow_change_error', [
                'quality_item_id' => (int) $item->id,
                'message' => $exception->getMessage(),
                'problems' => $exception->problems,
                'instruction' => (string) ($validated['instruction'] ?? ''),
            ]);
        }

        $this->blueprints->store(
            (int) $customerId,
            $item,
            $payload,
            QualityProcessBlueprint::SOURCE_AI,
            $user,
        );

        return back()->with('success', __('procynia.quality.flash.blueprint_change_accepted'));
    }

    /**
     * "Bruk svaret" on one of the suggestions beside a proposal.
     *
     * WHY THE ANSWER IS NOT SIMPLY ADDED TO THE DESCRIPTION.
     *
     * It was, and it produced a description that grew a transcript at the bottom: the question
     * Procynia asked, then the sentence the user typed, then the next question, then the next
     * sentence. A process description is read by the people carrying the process out. What they
     * need is one text saying how the work is done — so the answer is woven into the sentence that
     * raised it, by QualityProcessDescriptionClarifier, and the question is never written down at
     * all.
     *
     * TWO CALLS, ONE DECISION.
     *
     * The rewrite and the re-reading happen together because to the user they are one action: they
     * answered a question and want to see what it did to the flow. The order matters — the
     * description is revised first, and the flow is read from the revised text, so the proposal on
     * screen and the description in the box are the same statement about the process.
     *
     * NO FLOW IS WRITTEN HERE. The revised description travels back as part of the proposal and
     * becomes the process's description if and when the user adopts the flow. A rewrite that cannot
     * be used — or a reading that fails afterwards — leaves the description exactly as the user
     * wrote it, which is what lets the browser put the suggestion back rather than lose it.
     *
     * WHAT IS WRITTEN IS THAT THE QUESTION WAS ANSWERED.
     *
     * Only once both calls have come back, so a failure still leaves nothing behind and the
     * suggestion is there to try again. Relying on the rewrite alone to retire the question was the
     * earlier behaviour and it did not hold: the revised description defines the term, the next
     * reading does not always agree that it does, and the user watches Procynia ask the thing they
     * just answered. See QualityFlowClarificationService for why the record is made against the
     * revised text and when it lapses.
     */
    public function answerFlowClarification(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        if (! ProcessFlowInterpretationAiClient::isAvailable()) {
            throw ValidationException::withMessages([
                'description' => __('procynia.quality.errors.flow_ai_disabled'),
            ]);
        }

        $validated = $request->validate([
            // The same floor and ceiling as interpreting, because that is what happens next.
            'description' => ['required', 'string', 'min:30', 'max:8000'],
            'question' => ['required', 'string', 'max:500'],
            // A definition is a sentence or two. The ceiling is there so a pasted document cannot
            // arrive through this field and become the process description.
            'answer' => ['required', 'string', 'max:2000'],
        ]);

        $languageCode = $this->customerContext->resolveLanguageCode($user);

        try {
            $description = $this->flowClarifier->integrate(
                $item,
                $validated['description'],
                $validated['question'],
                $validated['answer'],
                $languageCode,
            );

            $proposal = $this->flowInterpreter->interpret($item, $description, $languageCode);
        } catch (ProcessFlowInterpretationException|AiCostControlException $exception) {
            // The description the user wrote, not the rewrite — whatever failed, nothing they typed
            // was changed, and the box has to go on saying so.
            return $this->flowFailed($item, $user, $exception, $validated['description']);
        }

        $this->flowClarifications->resolve($item, $validated['question'], $description, $user);

        // The reading happened before the answer was recorded, so this proposal can still be
        // carrying the question that was just answered. Running the same filter over it again is
        // not a special case for this endpoint — it is the filter every proposal passes, applied
        // once the record it consults exists.
        $proposal['optional_clarifications'] = $this->flowClarifications->remaining(
            $item,
            $description,
            $proposal['optional_clarifications'],
        );

        return $this->flowProposed($item, $proposal);
    }

    /**
     * A proposal on its way to the screen.
     *
     * @param  array{payload: array<string, mixed>, blocking_questions: list<string>, optional_clarifications: list<string>, description: string}  $proposal
     */
    private function flowProposed(QualityItem $item, array $proposal): RedirectResponse
    {
        return back()->with('flow_proposal', [
            'quality_item_id' => (int) $item->id,
            'lanes' => $proposal['payload']['lanes'],
            'nodes' => $proposal['payload']['nodes'],
            'edges' => $proposal['payload']['edges'],
            // Two lists, not one. What blocks adoption and what would merely sharpen the flow are
            // different news to the user, and neither of them stops them adopting it.
            'blocking_questions' => $proposal['blocking_questions'],
            'optional_clarifications' => $proposal['optional_clarifications'],
            'description' => $proposal['description'],
        ]);
    }

    /**
     * Why the flow could not be produced, and the description it was produced from.
     *
     * Flashed rather than thrown as a validation error: the description is well-formed, so marking
     * the field invalid would be wrong, and the problem list needs more room than an error bag
     * gives it.
     *
     * A commercial stop is the one case with its own sentence per reason — quota, entitlement,
     * suspension and the platform stop are different news, and AiCostControlPresenter is what knows
     * which. It is shown in the same place as every other reason, because to the user it is the
     * same moment.
     */
    private function flowFailed(
        QualityItem $item,
        ?User $user,
        ProcessFlowInterpretationException|AiCostControlException $exception,
        string $description,
    ): RedirectResponse {
        return back()->with('flow_error', [
            'quality_item_id' => (int) $item->id,
            'message' => $exception instanceof AiCostControlException
                ? app(AiCostControlPresenter::class)->message($exception, $user?->customer)
                : $exception->getMessage(),
            'problems' => $exception instanceof ProcessFlowInterpretationException ? $exception->problems : [],
            'description' => $description,
        ]);
    }

    /**
     * "Avvis" on one of the suggestions beside a proposal.
     *
     * The suggestion is gone from the screen before this request is made — the browser removes it
     * on the click, because a user dismissing a note should not watch it sit there while a round
     * trip happens. What this endpoint does is make it stay gone: the next time the process is
     * interpreted, QualityFlowClarificationService drops the question again, for as long as the
     * description it was dismissed against is still the description being read.
     *
     * It writes nothing about the flow. A dismissal is an answer about what Procynia should say,
     * not about how the process runs, so it cannot touch the blueprint and no proposal is created,
     * changed or adopted by it. Blocking questions have no equivalent on purpose.
     */
    public function dismissFlowClarification(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            // What it was dismissed against. Optional because the dismissal is still meaningful
            // without it — it simply never lapses. See QualityFlowClarificationService.
            'description' => ['nullable', 'string', 'max:8000'],
        ]);

        $this->flowClarifications->dismiss(
            $item,
            $validated['question'],
            $validated['description'] ?? null,
            $user,
        );

        return back();
    }

    /**
     * Adopt a proposal — as corrected — as the process's flow.
     *
     * The payload travels back from the browser rather than being held server-side, because the
     * user may have edited it in between and the edited version is the one they are agreeing to.
     * That makes it untrusted input, which is exactly what every other write to a blueprint already
     * is: it goes through QualityProcessBlueprintService::normalise() on the same terms as a manual
     * save. The endpoint's own contribution is the provenance — a flow stored here says `ai`,
     * because that is where it came from, and nothing else in the system can claim that word.
     */
    public function adoptFlowProposal(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate($this->blueprintRules() + [
            'description' => ['nullable', 'string', 'max:8000'],
        ]);

        $this->blueprints->store(
            (int) $customerId,
            $item,
            $validated,
            QualityProcessBlueprint::SOURCE_AI,
            $user,
            $validated['description'] ?? null,
        );

        return back()->with('success', __('procynia.quality.flash.blueprint_adopted'));
    }

    /**
     * Size and shape only. What makes a payload drawable is
     * QualityProcessBlueprintService::normalise(), and what makes an interpreted one coherent is
     * QualityProcessFlowValidator — neither belongs in a request rule.
     *
     * @return array<string, list<string>>
     */
    private function blueprintRules(): array
    {
        return [
            'lanes' => ['present', 'array', 'max:12'],
            'lanes.*.key' => ['nullable', 'string', 'max:80'],
            'lanes.*.label' => ['nullable', 'string', 'max:120'],
            'nodes' => ['present', 'array', 'max:80'],
            'nodes.*.key' => ['nullable', 'string', 'max:80'],
            'nodes.*.lane' => ['nullable', 'string', 'max:80'],
            'nodes.*.type' => ['nullable', 'string', 'max:20'],
            'nodes.*.label' => ['nullable', 'string', 'max:200'],
            'nodes.*.description' => ['nullable', 'string', 'max:2000'],
            // The process this step opens into. Whether it may point there at all — same customer,
            // actually a process, no cycle — is QualityProcessSubprocessService's call, not a rule's.
            'nodes.*.subprocess_quality_item_id' => ['nullable', 'integer'],
            'edges' => ['present', 'array', 'max:200'],
            'edges.*.from' => ['nullable', 'string', 'max:80'],
            'edges.*.to' => ['nullable', 'string', 'max:80'],
            'edges.*.label' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * Vouch for the flow as it stands.
     *
     * Same authority as every other statement about the kvalitetssystem — no new permission. The
     * stored flow must pass the flow validator first; a valid approval is recorded as an immutable
     * revision, and a later edit clears the working version's approval but never the revision.
     */
    public function approveBlueprint(QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_APPROVE);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $this->blueprints->approve((int) $customerId, $item, $user);

        return back()->with('success', __('procynia.quality.flash.blueprint_approved'));
    }

    /**
     * "Slett flyt" — the flow alone.
     *
     * Deliberately not the same action as deleting the process, and deliberately not reachable from
     * the same place. This is a kvalitetsleder saying the flow is wrong and they want to describe it
     * again; the process, its document data and the Wiki knowledge its activities produced are all
     * still wanted. Removing the whole process is the Kvalitet list's job.
     *
     * Afterwards the Flyt tab falls back to its empty state, which is where a new flow is described
     * or generated — so `back()` lands the user exactly where the next step is.
     *
     * Once a revision has been approved the same request is "Forkast arbeidsversjon": the working
     * version is reset to the latest revision rather than removed, so the Flyt tab still shows the
     * process in force and editing carries on from it. The revisions are not touched.
     */
    public function destroyBlueprint(QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_DELETE);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $published = $this->blueprints->latestRevision((int) $customerId, $item) !== null;

        $this->blueprints->delete((int) $customerId, $item);

        return back()->with('success', __($published
            ? 'procynia.quality.flash.blueprint_discarded'
            : 'procynia.quality.flash.blueprint_deleted'));
    }

    /**
     * "Opprett kunnskapsartikkel" — step one of two.
     *
     * An activity is a place where the virksomhet knows something that is not written down. This
     * drafts the article from the process, the activity and the role, and hands it back for the
     * user to read and correct. NOTHING IS STORED: the draft travels in a flash across the one
     * redirect, exactly as a flow proposal does, and reloading the page drops it — which is the
     * honest behaviour, because nothing was created.
     */
    public function draftActivityArticle(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_CREATE);
        $this->authorizeActivityArticleHandover($user);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        if (! ProcessActivityArticleAiClient::isAvailable()) {
            throw ValidationException::withMessages([
                'activity_key' => __('procynia.quality.errors.flow_ai_disabled'),
            ]);
        }

        $validated = $request->validate([
            'activity_key' => ['required', 'string', 'max:80'],
        ]);

        $blueprint = $this->blueprints->forItem((int) $customerId, $item)
            ?? abort(404);

        $activityKey = (string) $validated['activity_key'];

        if ($this->activityArticles->activity($blueprint, $activityKey) === null) {
            abort(404);
        }

        try {
            $drafted = $this->activityArticles->draft(
                $item,
                $blueprint,
                $activityKey,
                $this->customerContext->resolveLanguageCode($user),
            );
        } catch (ProcessFlowInterpretationException|AiCostControlException $exception) {
            return back()->with('activity_article_error', [
                'quality_item_id' => (int) $item->id,
                'activity_key' => $activityKey,
                'message' => $exception instanceof AiCostControlException
                    ? app(AiCostControlPresenter::class)->message($exception, $user?->customer)
                    : $exception->getMessage(),
            ]);
        }

        return back()->with('activity_article_draft', [
            'quality_item_id' => (int) $item->id,
            'activity_key' => $activityKey,
            'title' => $drafted['title'],
            'markdown' => $drafted['markdown'],
        ]);
    }

    /**
     * Step two: the article the user settled on, as an ordinary Enterprise Wiki source.
     *
     * What is created is a source document in the customer's Wiki, and the ordinary ingest run is
     * started on it — the same run an uploaded policy gets. Which pages it becomes, and of which
     * types, is the maintainer decision's to make, not Kvalitet's. Kvalitet keeps one row saying
     * which activity the source came out of, and the user stays on the flow: there is no page to
     * send them to yet, and inventing one would be the very shortcut this replaced.
     *
     * The text sent is the user's, not the model's: they may have rewritten every word of the
     * draft, or written it from nothing. That is why this endpoint takes a title and a body and
     * does not consult the draft at all.
     */
    public function storeActivityArticle(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_CREATE);
        $this->authorizeActivityArticleHandover($user);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        $validated = $request->validate([
            'activity_key' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:'.ProcessActivityArticleAiClient::MAX_TITLE_LENGTH],
            'markdown' => ['required', 'string', 'max:'.ProcessActivityArticleAiClient::MAX_MARKDOWN_LENGTH],
        ], [
            'title.required' => __('procynia.quality.errors.article_title_required'),
            'markdown.required' => __('procynia.quality.errors.article_markdown_required'),
        ]);

        $blueprint = $this->blueprints->forItem((int) $customerId, $item)
            ?? abort(404);

        $activityKey = (string) $validated['activity_key'];

        if ($this->activityArticles->activity($blueprint, $activityKey) === null) {
            abort(404);
        }

        if ($this->activityArticles->countForActivity((int) $customerId, (int) $item->id, $activityKey)
            >= QualityActivityArticleService::MAX_PER_ACTIVITY) {
            throw ValidationException::withMessages([
                'activity_key' => __('procynia.quality.errors.article_limit_reached'),
            ]);
        }

        $this->activityArticles->create(
            $item,
            $blueprint,
            $activityKey,
            (string) $validated['title'],
            (string) $validated['markdown'],
            $user,
        );

        // The flow's activities and what they have produced are a relation the graph answers
        // questions about, so a created article has to reach it. afterCommit for the same reason
        // saving a flow does: the job reads SQL. The pages themselves arrive later, with the run —
        // Wiki projects each one as it is generated, and the next projection of this process picks
        // up the edges to them.
        ProjectQualityItemToGraph::dispatch((int) $item->id)->afterCommit();

        return back()->with('success', __('procynia.quality.flash.article_queued'));
    }

    /**
     * Places a new control on an activity of this process's flow.
     *
     * The control is registered as an ordinary `control` quality item — see
     * QualityActivityControlService. quality.edit, because it changes how the process is run; the
     * flow itself is not written.
     */
    public function storeActivityControl(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);
        $this->assertProcess($item);

        $validated = $request->validate([
            'activity_key' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'criterion' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => __('procynia.quality.errors.control_title_required'),
        ]);

        $blueprint = $this->blueprints->forItem((int) $customerId, $item)
            ?? abort(404);

        $activityKey = (string) $validated['activity_key'];

        abort_unless($this->activityControls->hasActivity($blueprint, $activityKey), 404);

        $this->activityControls->add(
            $item,
            $blueprint,
            $activityKey,
            (string) $validated['title'],
            $validated['criterion'] ?? null,
            $user,
        );

        return back()->with('success', __('procynia.quality.flash.control_added'));
    }

    /** Takes a control off its activity. The control stays in the register. */
    public function destroyActivityControl(QualityActivityControl $control): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $control->customer_id, $customerId);

        $this->activityControls->remove($control);

        return back()->with('success', __('procynia.quality.flash.control_removed'));
    }

    public function storeRelation(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);

        $validated = $request->validate([
            'from_item_id' => ['required', 'integer'],
            'to_item_id' => ['required', 'integer'],
            'relation_type' => ['required', 'string'],
        ]);

        $items = QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', [$validated['from_item_id'], $validated['to_item_id']])
            ->get()
            ->keyBy('id');

        $fromItem = $items->get($validated['from_item_id']);
        $toItem = $items->get($validated['to_item_id']);

        abort_if($fromItem === null || $toItem === null, 404);

        $this->items->relate((int) $customerId, $fromItem, $toItem, $validated['relation_type'], $user);

        return back()->with('success', __('procynia.quality.flash.related'));
    }

    public function destroyRelation(QualityItemRelation $relation): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $relation->customer_id, $customerId);

        $this->items->unrelate((int) $customerId, $relation);

        return back()->with('success', __('procynia.quality.flash.unrelated'));
    }

    public function storeWikiLink(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate([
            'enterprise_wiki_page_id' => ['required', 'integer'],
            'link_type' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->findOrFail($validated['enterprise_wiki_page_id']);

        $this->items->linkWikiPage(
            (int) $customerId,
            $item,
            $page,
            $validated['link_type'] ?? QualityItemWikiLink::LINK_TYPE_DOCUMENTS,
            $validated['note'] ?? null,
            $user,
        );

        return back()->with('success', __('procynia.quality.flash.wiki_linked'));
    }

    public function destroyWikiLink(QualityItemWikiLink $link): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $link->customer_id, $customerId);

        $this->items->unlinkWikiPage((int) $customerId, $link);

        return back()->with('success', __('procynia.quality.flash.wiki_unlinked'));
    }

    /**
     * Attach a file the virksomhet already has.
     *
     * Separate from storeWikiLink() and deliberately so: a Wiki link reaches the knowledge written
     * down about a subject, this reaches a file the document consists of, uses or leaves behind.
     */
    public function storeDocumentLink(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate([
            'enterprise_wiki_document_id' => ['required', 'integer'],
            'relation_type' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->findOrFail($validated['enterprise_wiki_document_id']);

        $this->items->linkDocument(
            (int) $customerId,
            $item,
            $document,
            $validated['relation_type'] ?? QualityItemDocument::RELATION_TYPE_SOURCE,
            $validated['note'] ?? null,
            $user,
        );

        return back()->with('success', __('procynia.quality.flash.document_linked'));
    }

    /**
     * Record evidence that a control is met.
     *
     * Composing the control's documentation, so it rides on edit — the same permission attaching
     * an existing file does. No upload here: the optional file is one the store already holds.
     */
    public function storeControlEvidence(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'enterprise_wiki_document_id' => ['nullable', 'integer'],
        ]);

        $document = isset($validated['enterprise_wiki_document_id'])
            ? EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->findOrFail($validated['enterprise_wiki_document_id'])
            : null;

        $this->items->addControlEvidence(
            (int) $customerId,
            $item,
            $validated['title'],
            $validated['description'] ?? null,
            $document,
            $user,
        );

        return back()->with('success', __('procynia.quality.flash.evidence_added'));
    }

    /**
     * Upload a file and attach it in one step.
     *
     * The upload itself is the existing one — EnterpriseWikiDocumentUploadService is literally what
     * Wiki → Kildedokumenter runs, so the file lands in the same private, customer-scoped place,
     * under the same SHA-256 identity, extracted the same way. Kvalitet only differs in what it
     * does about a file the customer already has: Wiki refuses the upload, Kvalitet attaches the
     * copy it already holds, because a second copy of the same bytes is never the right answer and
     * the user's intent — "this file belongs to this document" — is satisfied either way.
     *
     * Validation accepts the same formats Wiki does. Widening it here would put files into the
     * shared store that the Wiki ingest pipeline cannot read.
     */
    public function storeDocument(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_CREATE);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx', 'max:20480'],
            'relation_type' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // The uploader owns what they upload, when their role may own a document at all. No new
        // permission is introduced: an ownerless document is a state the store already supports,
        // and reassigning the owner stays where it is, in Wiki → Kildedokumenter.
        $ownerUserId = ($user?->canBeEnterpriseWikiDocumentOwner() ?? false) ? $user->id : null;

        $result = $this->documentUploads->store((int) $customerId, $validated['file'], $ownerUserId, $user?->id);

        $this->items->linkDocument(
            (int) $customerId,
            $item,
            $result['document'],
            $validated['relation_type'] ?? QualityItemDocument::RELATION_TYPE_SOURCE,
            $validated['note'] ?? null,
            $user,
        );

        return back()->with('success', __($result['reused']
            ? 'procynia.quality.flash.document_reused'
            : 'procynia.quality.flash.document_uploaded'));
    }

    /**
     * Remove the connection, not the file.
     *
     * Deleting the document itself is a different act with a different authority and lives in
     * Wiki → Kildedokumenter, where the deletion service knows what else is built on it.
     */
    public function destroyDocumentLink(QualityItemDocument $link): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $link->customer_id, $customerId);

        $this->items->unlinkDocument((int) $customerId, $link);

        return back()->with('success', __('procynia.quality.flash.document_unlinked'));
    }

    /**
     * Put a document into the Verktøy library: a new upload, or a file the archive already has.
     *
     * The upload is the same one storeDocument() runs — EnterpriseWikiDocumentUploadService — so the
     * file lands in the virksomhet's archive and nowhere else, and bytes it already holds are reused
     * rather than written twice. Registering a tool is composing the kvalitetssystem, so it rides on
     * quality.edit.
     */
    public function storeTool(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', Rule::in(QualityTool::CATEGORIES)],
            'file' => ['nullable', 'required_without:enterprise_wiki_document_id', 'file', 'mimes:pdf,docx', 'max:20480'],
            'enterprise_wiki_document_id' => ['nullable', 'required_without:file', 'integer'],
        ]);

        if ($request->hasFile('file')) {
            $ownerUserId = ($user?->canBeEnterpriseWikiDocumentOwner() ?? false) ? $user->id : null;
            $document = $this->documentUploads->store((int) $customerId, $validated['file'], $ownerUserId, $user?->id)['document'];
        } else {
            $document = EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->findOrFail($validated['enterprise_wiki_document_id']);
        }

        $this->tools->register((int) $customerId, $document, $validated, $user);

        return back()->with('success', __('procynia.quality.flash.tool_registered'));
    }

    /**
     * Open or download a tool's file.
     *
     * Kvalitet's own route rather than Wiki's download, because reading a tool is reading the
     * kvalitetssystem: quality.view is what a person needs to carry out a control, and Wiki access
     * is a separate grant they may not have. It reaches only files registered as tools, so it is no
     * way into the rest of the archive.
     */
    public function toolFile(Request $request, QualityTool $tool): BinaryFileResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_VIEW);
        $this->assertOwnedByCustomer((int) $tool->customer_id, $customerId);

        $document = $tool->document;
        abort_if($document === null, 404);

        return EnterpriseWikiDocumentFileResponse::make($document, $request->boolean('download'));
    }

    /**
     * Say that a control is carried out with a tool from the library. Removal is
     * destroyDocumentLink() — the use is a document-link row, and the file stays.
     */
    public function storeControlTool(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizePermission($user, CustomerPermissionCatalog::QUALITY_EDIT);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $validated = $request->validate([
            'quality_tool_id' => ['required', 'integer'],
        ]);

        $tool = QualityTool::query()
            ->where('customer_id', $customerId)
            ->findOrFail($validated['quality_tool_id']);

        $this->tools->linkToControl((int) $customerId, $item, $tool, $user);

        return back()->with('success', __('procynia.quality.flash.tool_linked'));
    }

    // -----------------------------------------------------------------
    // Payloads
    // -----------------------------------------------------------------

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function itemRows(?int $customerId, array $types, ?User $user): array
    {
        $typeOrder = array_flip(QualityItem::TYPES);

        $items = QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_type', $types)
            ->with(['owner:id,name'])
            ->withCount('wikiLinks')
            ->get();

        $producedPages = $this->producedWikiPagesByItem($customerId, $items);
        $publications = $customerId === null ? [] : $this->blueprints->publicationStates($customerId, $items);

        return $items
            ->map(function (QualityItem $item) use ($producedPages, $publications, $user): array {
                /** @var Collection<int, EnterpriseWikiPage> $pages */
                $pages = $producedPages[(int) $item->id] ?? collect();

                return [
                    'id' => (int) $item->id,
                    'quality_type' => $item->quality_type,
                    'title' => $item->title,
                    'code' => $item->code,
                    'status' => $item->status,
                    'publication' => $publications[(int) $item->id] ?? null,
                    'owner_name' => $item->owner?->name,
                    'next_review_at' => $item->next_review_at?->toDateString(),
                    'wiki_link_count' => (int) $item->wiki_links_count,
                    // What the delete dialog has to say before it asks. The count is the knowledge
                    // this process produced; the flag is whether this reader may remove it, so the
                    // dialog never offers a choice the controller would refuse.
                    'produced_wiki_page_count' => $pages->count(),
                    'can_delete_produced_wiki_pages' => $pages->isNotEmpty()
                        && $user instanceof User
                        && $pages->every(fn (EnterpriseWikiPage $page): bool => $user->canDeleteEnterpriseWikiPage($page)),
                    'url' => route('app.quality.items.show', ['item' => $item->id]),
                ];
            })
            // Governing documents first, then alphabetically within each type — the order a
            // kvalitetshåndbok is read in, not the order rows happened to be created in.
            ->sortBy(static fn (array $row): string => sprintf(
                '%02d|%s',
                $typeOrder[$row['quality_type']] ?? 99,
                mb_strtolower((string) $row['title']),
            ))
            ->values()
            ->all();
    }

    /**
     * The flash message for a created, updated or deleted item. A process and a control are not
     * documents, so neither is ever confirmed as one; policy, procedure, work instruction and
     * checklist are the governing documents and are.
     */
    private function itemFlashKey(QualityItem $item, string $verb): string
    {
        $subject = match ($item->quality_type) {
            QualityItem::TYPE_PROCESS => 'process',
            QualityItem::TYPE_CONTROL => 'control',
            default => 'item',
        };

        return "procynia.quality.flash.{$subject}_{$verb}";
    }

    /**
     * What each control checks, who carries it out, how often and how, whether evidence has been
     * recorded, and where it is applied — keyed by control id.
     *
     * Every control of the customer has an entry, placed or not: a control that is on no activity
     * any more is still in the register, with an empty list, until somebody deletes it. Evidence is
     * counted as QualityAttentionService counts it: any `evidence` row, file or no file.
     *
     * An object, so an empty register reaches the page as {} rather than [].
     *
     * @return array<int, array{criterion: ?string, responsibility: ?string, frequency: ?string, method: ?string, evidence_count: int, placements: list<array<string, mixed>>}>|object
     */
    private function controlRegister(?int $customerId): array|object
    {
        if ($customerId === null) {
            return (object) [];
        }

        $controls = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_CONTROL)
            ->with('controlDetail')
            ->get();
        $placements = $this->activityControls->placementsByControl($customerId);
        $evidenceCounts = QualityItemDocument::query()
            ->where('customer_id', $customerId)
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_EVIDENCE)
            ->whereIn('quality_item_id', $controls->modelKeys())
            ->selectRaw('quality_item_id, count(*) as aggregate')
            ->groupBy('quality_item_id')
            ->pluck('aggregate', 'quality_item_id');

        $register = [];

        foreach ($controls as $control) {
            $register[(int) $control->id] = [
                'criterion' => $control->controlDetail?->criterion,
                'responsibility' => $control->controlDetail?->responsibility,
                'frequency' => $control->controlDetail?->frequency,
                'method' => $control->controlDetail?->method,
                'evidence_count' => (int) ($evidenceCounts[$control->id] ?? 0),
                'placements' => $placements[(int) $control->id] ?? [],
            ];
        }

        return $register === [] ? (object) [] : $register;
    }

    /**
     * The Wiki pages each listed item produced, keyed by item id.
     *
     * Resolved only for the items that have a provenance row at all — one query to find them,
     * rather than a full resolution per row of a list where most rows have produced nothing.
     *
     * @param  Collection<int, QualityItem>  $items
     * @return array<int, Collection<int, EnterpriseWikiPage>>
     */
    private function producedWikiPagesByItem(?int $customerId, Collection $items): array
    {
        if ($customerId === null || $items->isEmpty()) {
            return [];
        }

        $itemIds = QualityActivityWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $items->pluck('id'))
            ->distinct()
            ->pluck('quality_item_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $byItem = [];

        foreach ($items as $item) {
            if (in_array((int) $item->id, $itemIds, true)) {
                $byItem[(int) $item->id] = $this->items->producedWikiPages($customerId, $item);
            }
        }

        return $byItem;
    }

    /**
     * Which tab the page opens on.
     *
     * A non-process can never be on `flow`, whatever the URL says — a stale link from a document
     * that used to be a process must not land on an empty tab.
     */
    private function detailTab(Request $request, QualityItem $item): string
    {
        $requested = (string) $request->query('tab', 'document');

        if (! in_array($requested, self::DETAIL_TABS, true)) {
            return 'document';
        }

        if ($requested === 'flow' && $item->quality_type !== QualityItem::TYPE_PROCESS) {
            return 'document';
        }

        return $requested;
    }

    /**
     * The flow, or null when the process has none yet.
     *
     * The payload is shipped as stored — it was normalised on the way in, so what the editor loads
     * is exactly what the diagram is drawn from, and there is no second normalisation to disagree
     * with the first.
     *
     * @return array<string, mixed>|null
     */
    private function blueprintPayload(?int $customerId, QualityItem $item): ?array
    {
        if ($customerId === null || $item->quality_type !== QualityItem::TYPE_PROCESS) {
            return null;
        }

        $blueprint = $this->blueprints->forItem($customerId, $item);

        if ($blueprint === null) {
            return null;
        }

        $blueprint->loadMissing(['generatedBy:id,name', 'approvedBy:id,name']);

        return [
            'id' => (int) $blueprint->id,
            'status' => $blueprint->status,
            'source' => $blueprint->source,
            'lanes' => $blueprint->lanes(),
            'nodes' => $this->nodeRows($customerId, $blueprint),
            'edges' => $blueprint->edges(),
            'description' => $blueprint->description,
            'generated_at' => $blueprint->generated_at?->toDateTimeString(),
            'generated_by_name' => $blueprint->generatedBy?->name,
            'approved_at' => $blueprint->approved_at?->toDateTimeString(),
            'approved_by_name' => $blueprint->approvedBy?->name,
        ];
    }

    /**
     * The nodes as the page needs them: what is stored, plus what the reference resolves to now.
     *
     * `subprocess_quality_item_id` is the stored truth and the only thing written back. `subprocess`
     * is read fresh here — title, code and how many steps the other flow holds today — so the
     * parent's diagram shows the subprocess as it currently is. Nothing about it is cached on the
     * parent, which is the whole reason the node holds a reference rather than a copy.
     *
     * @return list<array<string, mixed>>
     */
    private function nodeRows(int $customerId, QualityProcessBlueprint $blueprint): array
    {
        $nodes = $blueprint->nodes();

        // The articles this flow's activities have produced, read once for the whole flow. Nothing
        // about them is stored on the node: the provenance rows say which activity produced which
        // page, and the page's title and publication state are read fresh here, so an article shows
        // as it currently is in Wiki. A page that has been deleted took its provenance row with it
        // and is simply not here.
        $articles = $this->activityArticles->describeForItem($customerId, (int) $blueprint->quality_item_id);
        // The controls placed on each activity, read the same way: by key, fresh from the control
        // items, never stored on the node.
        $controls = $this->activityControls->describeForItem($customerId, (int) $blueprint->quality_item_id);

        return array_map(
            fn (array $node): array => $node + [
                'subprocess' => $this->subprocesses->describe(
                    $customerId,
                    $node['subprocess_quality_item_id'] ?? null,
                ),
                'articles' => $articles[(string) ($node['key'] ?? '')] ?? [],
                'controls' => $controls[(string) ($node['key'] ?? '')] ?? [],
            ],
            $nodes,
        );
    }

    /**
     * The subprocess the reader has drilled into, or null when they are looking at the process
     * itself.
     *
     * `?subprocess=12,45` is the path taken, not a list of things to show — each hop is checked
     * against the blueprint above it, so the only flows reachable here are the ones this process
     * actually links to. A broken hop truncates rather than fails; see the service.
     *
     * @return array{trail: list<array{id: int, title: string}>, blueprint: array<string, mixed>|null}|null
     */
    private function subprocessView(?int $customerId, QualityItem $item, Request $request): ?array
    {
        if ($customerId === null || $item->quality_type !== QualityItem::TYPE_PROCESS) {
            return null;
        }

        $requested = array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) $request->query('subprocess', '')),
        )));

        if ($requested === []) {
            return null;
        }

        $trail = $this->subprocesses->trail($customerId, $item, array_slice($requested, 0, 10));

        if ($trail === []) {
            return null;
        }

        return [
            'trail' => array_map(
                static fn (QualityItem $step): array => [
                    'id' => (int) $step->id,
                    'title' => (string) $step->title,
                ],
                $trail,
            ),
            'blueprint' => $this->blueprintPayload($customerId, $trail[array_key_last($trail)]),
        ];
    }

    /**
     * The processes a node on this flow may be pointed at.
     *
     * Only sent to someone who can edit the flow — it is the content of one dropdown in the manual
     * structure editor, and a reader has no use for a list of processes they cannot choose.
     *
     * @return list<array{id: int, title: string, code: ?string, step_count: int}>
     */
    private function subprocessOptions(?int $customerId, QualityItem $item, ?User $user): array
    {
        if ($customerId === null
            || $item->quality_type !== QualityItem::TYPE_PROCESS
            || ! $this->may($user, CustomerPermissionCatalog::QUALITY_EDIT)) {
            return [];
        }

        return $this->subprocesses->options($customerId, $item);
    }

    /**
     * The statuses the metadata form may offer. A process is limited by its publication — see
     * QualityItem::processStatusesFor() — and keeps its stored status in the list even when that
     * predates the rule, so the select shows what is stored rather than silently picking another.
     *
     * @param  array{state: string, revision_number: ?int, has_unpublished_changes: bool}|null  $publication
     * @return list<string>
     */
    private function statusOptions(QualityItem $item, ?array $publication): array
    {
        if ($item->quality_type !== QualityItem::TYPE_PROCESS) {
            return QualityItem::STATUSES;
        }

        $allowed = QualityItem::processStatusesFor(($publication['revision_number'] ?? null) !== null);

        return array_values(array_filter(
            QualityItem::STATUSES,
            static fn (string $status): bool => in_array($status, $allowed, true) || $status === $item->status,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemDetail(QualityItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'quality_type' => $item->quality_type,
            'title' => $item->title,
            'code' => $item->code,
            'purpose' => $item->purpose,
            'status' => $item->status,
            'owner_user_id' => $item->owner_user_id !== null ? (int) $item->owner_user_id : null,
            'owner_name' => $item->owner?->name,
            'review_interval_months' => $item->review_interval_months,
            'last_reviewed_at' => $item->last_reviewed_at?->toDateString(),
            'next_review_at' => $item->next_review_at?->toDateString(),
            'steps' => $item->processSteps
                ->map(static fn ($step): array => [
                    'id' => (int) $step->id,
                    'position' => (int) $step->position,
                    'title' => $step->title,
                    'description' => $step->description,
                    'responsibility' => $step->responsibility,
                ])
                ->values()
                ->all(),
            'inputs' => $this->ioRows($item, 'input'),
            'outputs' => $this->ioRows($item, 'output'),
            'checklist_items' => $item->checklistItems
                ->map(static fn ($entry): array => [
                    'id' => (int) $entry->id,
                    'position' => (int) $entry->position,
                    'text' => $entry->text,
                    'guidance' => $entry->guidance,
                    'is_required' => (bool) $entry->is_required,
                ])
                ->values()
                ->all(),
            'control' => $item->controlDetail !== null ? [
                'criterion' => $item->controlDetail->criterion,
                'responsibility' => $item->controlDetail->responsibility,
                'frequency' => $item->controlDetail->frequency,
                'method' => $item->controlDetail->method,
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ioRows(QualityItem $item, string $direction): array
    {
        return $item->processIo
            ->where('direction', $direction)
            ->map(static fn ($entry): array => [
                'id' => (int) $entry->id,
                'position' => (int) $entry->position,
                'label' => $entry->label,
                'description' => $entry->description,
            ])
            ->values()
            ->all();
    }

    /**
     * The Wiki pages behind one quality item.
     *
     * Read access follows Wiki's own: a page the reader could not open in Wiki is not listed here
     * either. The link row still exists — this is a visibility filter, not a deletion — so another
     * reader with wider access sees the full picture.
     *
     * @return list<array<string, mixed>>
     */
    private function wikiLinkRows(QualityItem $item, ?User $user): array
    {
        return QualityItemWikiLink::query()
            ->where('quality_item_id', $item->id)
            ->whereHas('page', fn ($query) => $query->whereIn('status', $this->visibleStatuses($user)))
            ->with(['page.currentVersion'])
            ->orderBy('id')
            ->get()
            ->map(fn (QualityItemWikiLink $link): array => [
                'id' => (int) $link->id,
                'link_type' => $link->link_type,
                'note' => $link->note,
                'page_id' => (int) $link->enterprise_wiki_page_id,
                'page_title' => $link->page?->title,
                'page_url' => $link->page !== null
                    ? route('app.wiki.show', ['slug' => $link->page->slug])
                    : null,
                // The same presenter the Wiki list and the Wiki page use, so a document cannot read
                // as approved in Kvalitet and in review in Wiki.
                'publication' => $link->page !== null
                    ? $this->publicationStatus->forPage($link->page, $link->page->currentVersion)
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Wiki pages that can be attached.
     *
     * Capped and searchable rather than complete: a mature Wiki has thousands of pages, and unlike
     * the retired classification model there is nothing to work through here — this is a picker.
     * Already-linked pages are not excluded, because a page may legitimately back the same item in
     * two ways; the unique index refuses a true duplicate.
     *
     * @return list<array<string, mixed>>
     */
    private function wikiPageOptions(?int $customerId, ?User $user, Request $request): array
    {
        $search = trim((string) $request->query('wiki_search', ''));

        $query = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $this->visibleStatuses($user));

        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $query->where(fn ($sub) => $sub
                ->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$searchLower}%"])
            );
        }

        return $query
            ->orderBy('title')
            ->limit(50)
            ->get(['id', 'title', 'slug', 'page_type', 'status'])
            ->map(static fn (EnterpriseWikiPage $page): array => [
                'page_id' => (int) $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'page_type' => $page->page_type,
                'status' => $page->status,
            ])
            ->values()
            ->all();
    }

    /**
     * The files attached to one quality item.
     *
     * No visibility filter, unlike wikiLinkRows(): a document is customer-scoped and has no
     * per-reader status, so anyone who may open the quality item may see what is attached to it.
     * Whether they may delete the file is a separate question, answered in Wiki.
     *
     * @return list<array<string, mixed>>
     */
    private function documentRows(QualityItem $item): array
    {
        return QualityItemDocument::query()
            ->where('quality_item_id', $item->id)
            ->with(['document:id,original_filename,document_status,owner_user_id,created_at', 'document.owner:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(static fn (QualityItemDocument $link): bool => $link->document !== null)
            ->map(static fn (QualityItemDocument $link): array => [
                'id' => (int) $link->id,
                'relation_type' => $link->relation_type,
                'note' => $link->note,
                'document_id' => (int) $link->enterprise_wiki_document_id,
                'filename' => $link->document?->original_filename,
                'document_status' => $link->document?->document_status,
                'owner_name' => $link->document?->owner?->name,
                'uploaded_at' => $link->document?->created_at?->toDateString(),
                'download_url' => $link->document !== null
                    ? route('app.wiki.sources.download', ['document' => $link->document->id])
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The evidence recorded on one control, file or no file.
     *
     * Unlike documentRows(), a row without a file is kept: evidence names itself, and the file is
     * optional. Removal posts to destroyDocumentLink() — it is the same row.
     *
     * @return list<array<string, mixed>>
     */
    private function controlEvidenceRows(QualityItem $item): array
    {
        return QualityItemDocument::query()
            ->where('quality_item_id', $item->id)
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_EVIDENCE)
            ->with(['document:id,original_filename', 'createdBy:id,name'])
            ->orderBy('id')
            ->get()
            ->map(static fn (QualityItemDocument $evidence): array => [
                'id' => (int) $evidence->id,
                // Evidence attached as a plain file before evidence had a name falls back to it.
                'title' => $evidence->title ?? $evidence->document?->original_filename,
                'description' => $evidence->note,
                'filename' => $evidence->document?->original_filename,
                'download_url' => $evidence->document !== null
                    ? route('app.wiki.sources.download', ['document' => $evidence->document->id])
                    : null,
                'document_removed' => $evidence->document_removed_at !== null,
                'added_by' => $evidence->createdBy?->name,
                'added_at' => $evidence->created_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Files that can be attached.
     *
     * Capped and searchable for the same reason the Wiki page picker is: this is a picker into a
     * store that grows without bound, not a worklist. Already-attached files are not excluded — one
     * file may legitimately be both the source and the template — and the unique index refuses a
     * true duplicate.
     *
     * @return list<array<string, mixed>>
     */
    private function documentOptions(?int $customerId, Request $request): array
    {
        $search = trim((string) $request->query('document_search', ''));

        $query = EnterpriseWikiDocument::query()->where('customer_id', $customerId);

        if ($search !== '') {
            $query->whereRaw('LOWER(original_filename) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        return $query
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'original_filename', 'document_status', 'created_at'])
            ->map(static fn (EnterpriseWikiDocument $document): array => [
                'document_id' => (int) $document->id,
                'filename' => $document->original_filename,
                'document_status' => $document->document_status,
                'uploaded_at' => $document->created_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Both directions of every relation touching one item.
     *
     * Stored rows are one-directional — see QualityItemRelation — so the incoming side is produced
     * here for presentation only. A row knows which end it is looking from, because "styres av" and
     * "styrer" are not the same statement.
     *
     * @return list<array<string, mixed>>
     */
    private function relationsForItem(?int $customerId, int $itemId): array
    {
        return QualityItemRelation::query()
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->where('from_item_id', $itemId)->orWhere('to_item_id', $itemId))
            ->with(['fromItem:id,title,quality_type,code', 'toItem:id,title,quality_type,code'])
            ->orderBy('id')
            ->get()
            ->map(static function (QualityItemRelation $relation) use ($itemId): array {
                $outgoing = (int) $relation->from_item_id === $itemId;
                $other = $outgoing ? $relation->toItem : $relation->fromItem;

                return [
                    'id' => (int) $relation->id,
                    'relation_type' => $relation->relation_type,
                    'direction' => $outgoing ? 'outgoing' : 'incoming',
                    'other_item_id' => (int) ($outgoing ? $relation->to_item_id : $relation->from_item_id),
                    'other_title' => $other?->title,
                    'other_code' => $other?->code,
                    'other_quality_type' => $other?->quality_type,
                    'other_url' => $other !== null
                        ? route('app.quality.items.show', ['item' => $other->id])
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The policies governing one process, read off its relations.
     *
     * @param  list<array<string, mixed>>  $relations  {@see relationsForItem()}
     * @return list<array<string, mixed>>
     */
    private function governingDocuments(array $relations): array
    {
        return array_values(array_filter(
            $relations,
            static fn (array $relation): bool => $relation['direction'] === 'incoming'
                && $relation['relation_type'] === QualityItemRelation::TYPE_GOVERNS,
        ));
    }

    /**
     * The processes one policy governs, read off its relations.
     *
     * @param  list<array<string, mixed>>  $relations  {@see relationsForItem()}
     * @return list<array<string, mixed>>
     */
    private function governedProcesses(array $relations): array
    {
        return array_values(array_filter(
            $relations,
            static fn (array $relation): bool => $relation['direction'] === 'outgoing'
                && $relation['relation_type'] === QualityItemRelation::TYPE_GOVERNS,
        ));
    }

    /**
     * The policies that may still be linked to a process: the customer's own, minus those already
     * governing it. The types come from the matrix, so widening `governs` widens the picker.
     *
     * @param  list<array<string, mixed>>  $relations  {@see relationsForItem()}
     * @return list<array<string, mixed>>
     */
    private function governingDocumentOptions(?int $customerId, array $relations): array
    {
        $linkedIds = array_column($this->governingDocuments($relations), 'other_item_id');

        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_type', QualityItemRelation::allowedFromTypes(QualityItemRelation::TYPE_GOVERNS))
            ->whereNotIn('id', $linkedIds)
            ->orderBy('title')
            ->get(['id', 'title', 'code', 'quality_type'])
            ->map(static fn (QualityItem $option): array => [
                'id' => (int) $option->id,
                'title' => $option->title,
                'code' => $option->code,
                'quality_type' => $option->quality_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function typeCounts(?int $customerId): array
    {
        $counts = QualityItem::query()
            ->where('customer_id', $customerId)
            ->selectRaw('quality_type, COUNT(*) AS total')
            ->groupBy('quality_type')
            ->pluck('total', 'quality_type');

        $result = [];

        foreach (QualityItem::TYPES as $type) {
            $result[$type] = (int) ($counts[$type] ?? 0);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownerOptions(?int $customerId): array
    {
        if ($customerId === null) {
            return [];
        }

        return User::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => $user->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function visibleStatuses(?User $user): array
    {
        return $user?->visibleEnterpriseWikiPageStatuses() ?? [];
    }

    /**
     * Whether the user holds one of Kvalitet's permissions.
     *
     * The answer comes from CustomerPermissionService and from nowhere else — it is the one place
     * that resolves a customer's own roles, and System Owner's unconditional grant lives there
     * rather than being restated here.
     */
    private function may(?User $user, string $permissionKey): bool
    {
        return $user instanceof User && $this->permissions->has($user, $permissionKey);
    }

    /**
     * The authoritative gate. Every write below opens with one of these, before the tenant check
     * and before validation: a permission the user does not hold is a 403 whatever the payload
     * says.
     *
     * A permission is never a substitute for object security. assertOwnedByCustomer() still runs,
     * the Wiki's own authority still decides what happens to a Wiki page, and a process still has
     * to be a process — quality.edit says the user may edit the kvalitetssystem, not that this
     * particular row is theirs to touch.
     */
    private function authorizePermission(?User $user, string $permissionKey): void
    {
        abort_unless($this->may($user, $permissionKey), 403);
    }

    /**
     * The seam out of Kvalitet and into Wiki, as a permission question.
     *
     * Handing an activity's knowledge to Wiki creates an Enterprise Wiki source document — see
     * QualityActivityArticleService — so it is a Wiki source action performed from Kvalitet, and
     * it asks for both sides: quality.create for the Kvalitet half, wiki.source.manage for the
     * Wiki half. Neither on its own is enough, because neither module alone is being changed.
     *
     * Nothing else in Kvalitet is affected. Attaching an existing Wiki page, uploading a document
     * against a process and every other Kvalitet action keep exactly the authorization they had.
     */
    private function authorizeActivityArticleHandover(?User $user): void
    {
        abort_unless($this->may($user, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE), 403);
    }

    /**
     * What the React pages are allowed to offer.
     *
     * The same five answers the gates above give, so a button the page shows is a request the
     * controller accepts and a button it hides is one the controller would refuse. The UI hides;
     * the controller is what enforces.
     *
     * @return array<string, bool>
     */
    private function permissionPayload(?User $user): array
    {
        return [
            'can_create' => $this->may($user, CustomerPermissionCatalog::QUALITY_CREATE),
            'can_edit' => $this->may($user, CustomerPermissionCatalog::QUALITY_EDIT),
            'can_approve' => $this->may($user, CustomerPermissionCatalog::QUALITY_APPROVE),
            'can_delete' => $this->may($user, CustomerPermissionCatalog::QUALITY_DELETE),
            // The cross-module one: what the activity article panel may offer. Both halves, so a
            // button it shows is a request authorizeActivityArticleHandover() accepts.
            'can_create_wiki_articles' => $this->may($user, CustomerPermissionCatalog::QUALITY_CREATE)
                && $this->may($user, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE),
        ];
    }

    /**
     * A flow belongs to a process and to nothing else. The tab is already hidden for the other
     * types, so reaching here means a hand-made request rather than a mistake in the UI.
     */
    private function assertProcess(QualityItem $item): void
    {
        if ($item->quality_type !== QualityItem::TYPE_PROCESS) {
            throw ValidationException::withMessages([
                'quality_type' => __('procynia.quality.errors.blueprint_requires_process'),
            ]);
        }
    }

    /**
     * A proposal or an interpretation failure, flashed by the redirect that produced it.
     *
     * Checked against the item it was produced for: the flash survives one request, and that one
     * request is not guaranteed to be the page it came from — a user who presses back and opens a
     * different process would otherwise be shown a proposal about the one they left.
     *
     * @return array<string, mixed>|null
     */
    private function flashedFlowState(QualityItem $item, string $key): ?array
    {
        $state = session($key);

        if (! is_array($state) || ($state['quality_item_id'] ?? null) !== (int) $item->id) {
            return null;
        }

        return $state;
    }

    /**
     * A draft article, or the reason there is none, belonging to the flow on screen.
     *
     * Not flashedFlowState(): an activity on a subprocess belongs to the subprocess's own item, and
     * the page being rendered is the parent's. Both are legitimate owners of what was flashed, and
     * matching only the parent would silently drop every draft produced while drilled in.
     *
     * @param  array{trail: list<array{id: int, title: string}>, blueprint: array<string, mixed>|null}|null  $subprocessView
     * @return array<string, mixed>|null
     */
    private function flashedActivityArticleState(QualityItem $item, ?array $subprocessView, string $key): ?array
    {
        $state = session($key);

        if (! is_array($state)) {
            return null;
        }

        $trail = $subprocessView['trail'] ?? [];
        $shownItemId = $trail === []
            ? (int) $item->id
            : (int) ($trail[array_key_last($trail)]['id'] ?? $item->id);

        return ($state['quality_item_id'] ?? null) === $shownItemId ? $state : null;
    }

    private function assertOwnedByCustomer(int $rowCustomerId, ?int $customerId): void
    {
        abort_unless($customerId !== null && $rowCustomerId === $customerId, 404);
    }
}
