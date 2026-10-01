<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use App\Services\Quality\QualityItemService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

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
    private const TABS = ['overview', 'processes', 'controls', 'checklists'];

    /**
     * Which types each tab shows. Oversikt deliberately shows all of them: it is the whole document
     * hierarchy in one place, and the only tab policies, procedures and arbeidsinstrukser appear on.
     *
     * @var array<string, list<string>>
     */
    private const TAB_TYPES = [
        'overview' => QualityItem::TYPES,
        'processes' => [QualityItem::TYPE_PROCESS],
        'controls' => [QualityItem::TYPE_CONTROL],
        'checklists' => [QualityItem::TYPE_CHECKLIST],
    ];

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly QualityItemService $items,
        private readonly EnterpriseWikiPublicationStatusService $publicationStatus,
        private readonly EnterpriseWikiDocumentUploadService $documentUploads,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';

        return Inertia::render('App/Quality/Index', [
            'active_tab' => $tab,
            // Creating a styrende dokument and drawing a relation between two are statements about
            // the kvalitetssystem, so they use the same authority that already vouches for Wiki
            // content — System Owner, or a role the customer has given Wiki claim approval to. No
            // new permission was introduced.
            'can_manage' => $user?->canApproveWikiClaims() ?? false,
            'items' => $this->itemRows($customerId, self::TAB_TYPES[$tab]),
            'type_counts' => $this->typeCounts($customerId),
            'quality_types' => QualityItem::TYPES,
            'statuses' => QualityItem::STATUSES,
            'relation_types' => $this->relationTypeMatrix(),
            'relations' => $tab === 'overview' ? $this->relationRows($customerId) : [],
            'relation_item_options' => $tab === 'overview' ? $this->relationItemOptions($customerId) : [],
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

        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $item->loadMissing(['owner', 'processSteps', 'processIo', 'checklistItems', 'controlDetail']);

        return Inertia::render('App/Quality/Item', [
            'item' => $this->itemDetail($item),
            'can_manage' => $user?->canApproveWikiClaims() ?? false,
            'statuses' => QualityItem::STATUSES,
            'frequencies' => QualityControlDetail::FREQUENCIES,
            'link_types' => QualityItemWikiLink::LINK_TYPES,
            'owner_options' => $this->ownerOptions($customerId),
            'wiki_links' => $this->wikiLinkRows($item, $user),
            'wiki_page_options' => $this->wikiPageOptions($customerId, $user, $request),
            'wiki_search' => trim((string) $request->query('wiki_search', '')),
            'document_relation_types' => QualityItemDocument::RELATION_TYPES,
            'documents' => $this->documentRows($item),
            'document_options' => $this->documentOptions($customerId, $request),
            'document_search' => trim((string) $request->query('document_search', '')),
            'relations' => $this->relationsForItem($customerId, (int) $item->id),
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);

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

        return redirect()
            ->route('app.quality.items.show', ['item' => $item->id])
            ->with('success', __('procynia.quality.flash.item_created'));
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

        $this->authorizeManagement($user);
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

        return back()->with('success', __('procynia.quality.flash.item_updated'));
    }

    public function destroyItem(QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);
        $this->assertOwnedByCustomer((int) $item->customer_id, $customerId);

        $this->items->deleteItem((int) $customerId, $item);

        return redirect()
            ->route('app.quality.index')
            ->with('success', __('procynia.quality.flash.item_deleted'));
    }

    /**
     * Process structure, written as a set. See QualityItemService::replaceProcessSteps for why the
     * whole list travels rather than one row at a time.
     */
    public function updateStructure(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);
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

    public function storeRelation(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);

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

        $this->authorizeManagement($user);
        $this->assertOwnedByCustomer((int) $relation->customer_id, $customerId);

        $this->items->unrelate((int) $customerId, $relation);

        return back()->with('success', __('procynia.quality.flash.unrelated'));
    }

    public function storeWikiLink(Request $request, QualityItem $item): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);
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

        $this->authorizeManagement($user);
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

        $this->authorizeManagement($user);
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

        $this->authorizeManagement($user);
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

        $this->authorizeManagement($user);
        $this->assertOwnedByCustomer((int) $link->customer_id, $customerId);

        $this->items->unlinkDocument((int) $customerId, $link);

        return back()->with('success', __('procynia.quality.flash.document_unlinked'));
    }

    // -----------------------------------------------------------------
    // Payloads
    // -----------------------------------------------------------------

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function itemRows(?int $customerId, array $types): array
    {
        $typeOrder = array_flip(QualityItem::TYPES);

        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_type', $types)
            ->with(['owner:id,name'])
            ->withCount('wikiLinks')
            ->get()
            ->map(static fn (QualityItem $item): array => [
                'id' => (int) $item->id,
                'quality_type' => $item->quality_type,
                'title' => $item->title,
                'code' => $item->code,
                'status' => $item->status,
                'owner_name' => $item->owner?->name,
                'next_review_at' => $item->next_review_at?->toDateString(),
                'wiki_link_count' => (int) $item->wiki_links_count,
                'url' => route('app.quality.items.show', ['item' => $item->id]),
            ])
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
     * @return list<array<string, mixed>>
     */
    private function relationRows(?int $customerId): array
    {
        return QualityItemRelation::query()
            ->where('customer_id', $customerId)
            ->with(['fromItem:id,title,quality_type', 'toItem:id,title,quality_type'])
            ->orderBy('relation_type')
            ->orderBy('id')
            ->get()
            ->map(static fn (QualityItemRelation $relation): array => [
                'id' => (int) $relation->id,
                'relation_type' => $relation->relation_type,
                'from_item_id' => (int) $relation->from_item_id,
                'from_title' => $relation->fromItem?->title,
                'to_item_id' => (int) $relation->to_item_id,
                'to_title' => $relation->toItem?->title,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationItemOptions(?int $customerId): array
    {
        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->orderBy('title')
            ->get(['id', 'title', 'code', 'quality_type'])
            ->map(static fn (QualityItem $item): array => [
                'id' => (int) $item->id,
                'title' => $item->title,
                'code' => $item->code,
                'quality_type' => $item->quality_type,
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
     * The relation matrix, shipped to the client so the relation form can offer only legal pairs.
     * The backend still enforces it — this only keeps the form from proposing work the service will
     * refuse.
     *
     * @return list<array<string, mixed>>
     */
    private function relationTypeMatrix(): array
    {
        return array_map(static fn (string $type): array => [
            'key' => $type,
            'pairs' => array_map(
                static fn (array $pair): array => ['from' => $pair[0], 'to' => $pair[1]],
                QualityItemRelation::TYPE_MATRIX[$type],
            ),
            'from_types' => QualityItemRelation::allowedFromTypes($type),
            'to_types' => QualityItemRelation::allowedToTypes($type),
        ], QualityItemRelation::TYPES);
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

    private function authorizeManagement(?User $user): void
    {
        abort_unless($user?->canApproveWikiClaims() ?? false, 403);
    }

    private function assertOwnedByCustomer(int $rowCustomerId, ?int $customerId): void
    {
        abort_unless($customerId !== null && $rowCustomerId === $customerId, 404);
    }
}
