<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeDocumentCategory;
use App\Models\KnowledgeDocumentTopic;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseSettingsController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        return Inertia::render('App/CustomerEnvironment/KnowledgeBase', [
            'pageTitle' => __('procynia.knowledge_base_settings.page_title'),
            'pageSubtitle' => __('procynia.knowledge_base_settings.page_subtitle'),
            'scopeNote' => __('procynia.knowledge_base_settings.scope_note'),
            'documentCategories' => $this->categoryListPayload($customerId),
            'documentTopics' => $this->topicListPayload($customerId),
            'routes' => [
                'index' => route('app.customer-environment.knowledge-base.index'),
                'category_store' => route('app.customer-environment.knowledge-base.categories.store'),
                'topic_store' => route('app.customer-environment.knowledge-base.topics.store'),
            ],
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);
        $payload = $this->validatedCatalogPayload($request, $customerId, 'category');
        $topicIds = $this->validatedTopicIds($request, $customerId);

        $record = KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customerId,
            'name' => $payload['name'],
            'description' => $payload['description'],
            'sort_order' => 0,
            'is_active' => $payload['is_active'],
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);

        if ($topicIds !== null) {
            $record->topics()->sync($topicIds);
        }

        return redirect()
            ->route('app.customer-environment.knowledge-base.index')
            ->with('success', __('procynia.knowledge_base_settings.category_created'));
    }

    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);
        $record = $this->scopedCategory($customerId, $category);
        $payload = $this->validatedCatalogPayload($request, $customerId, 'category', $record->id);
        $topicIds = $this->validatedTopicIds($request, $customerId);

        $record->forceFill([
            'name' => $payload['name'],
            'description' => $payload['description'],
            'is_active' => $payload['is_active'],
            'updated_by_user_id' => $actor->id,
        ])->save();

        if ($topicIds !== null) {
            $record->topics()->sync($topicIds);
        }

        return redirect()
            ->route('app.customer-environment.knowledge-base.index')
            ->with('success', __('procynia.knowledge_base_settings.category_updated'));
    }

    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);
        $record = $this->scopedCategory($customerId, $category);

        $record->forceFill([
            'updated_by_user_id' => $actor->id,
        ])->save();
        $record->delete();

        return redirect()
            ->route('app.customer-environment.knowledge-base.index')
            ->with('success', __('procynia.knowledge_base_settings.category_deleted'));
    }

    public function storeTopic(Request $request): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);
        $payload = $this->validatedCatalogPayload($request, $customerId, 'topic');

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $customerId,
            'name' => $payload['name'],
            'description' => $payload['description'],
            'sort_order' => 0,
            'is_active' => $payload['is_active'],
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);

        return redirect()
            ->route('app.customer-environment.knowledge-base.index')
            ->with('success', __('procynia.knowledge_base_settings.topic_created'));
    }

    public function updateTopic(Request $request, int $topic): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);
        $record = $this->scopedTopic($customerId, $topic);
        $payload = $this->validatedCatalogPayload($request, $customerId, 'topic', $record->id);

        $record->forceFill([
            'name' => $payload['name'],
            'description' => $payload['description'],
            'is_active' => $payload['is_active'],
            'updated_by_user_id' => $actor->id,
        ])->save();

        return redirect()
            ->route('app.customer-environment.knowledge-base.index')
            ->with('success', __('procynia.knowledge_base_settings.topic_updated'));
    }

    public function destroyTopic(Request $request, int $topic): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);
        $record = $this->scopedTopic($customerId, $topic);

        $record->forceFill([
            'updated_by_user_id' => $actor->id,
        ])->save();
        $record->delete();

        return redirect()
            ->route('app.customer-environment.knowledge-base.index')
            ->with('success', __('procynia.knowledge_base_settings.topic_deleted'));
    }

    /**
     * Purpose: Resolve the authenticated customer context for the settings workspace.
     * Inputs: Incoming request carrying the current authenticated user.
     * Returns: The current user and customer id.
     * Side effects: Aborts with HTTP 403 if the customer context is unavailable or the user is not a system owner.
     */
    private function systemOwnerContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $user->isSystemOwner()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    /**
     * Purpose: Render the current customer's document categories as a frontend payload.
     * Inputs: The current customer id.
     * Returns: A stable list of category records.
     * Side effects: None.
     */
    private function categoryListPayload(int $customerId): array
    {
        return KnowledgeDocumentCategory::query()
            ->forCustomer($customerId)
            ->with([
                'topics' => fn ($query) => $query
                    ->orderByRaw('LOWER(knowledge_document_topics.name)')
                    ->orderBy('knowledge_document_topics.id'),
            ])
            ->ordered()
            ->get()
            ->map(fn (KnowledgeDocumentCategory $category): array => $this->categoryPayload($category))
            ->values()
            ->all();
    }

    /**
     * Purpose: Render the current customer's topics as a frontend payload.
     * Inputs: The current customer id.
     * Returns: A stable list of topic records.
     * Side effects: None.
     */
    private function topicListPayload(int $customerId): array
    {
        return KnowledgeDocumentTopic::query()
            ->forCustomer($customerId)
            ->ordered()
            ->get()
            ->map(fn (KnowledgeDocumentTopic $topic): array => $this->topicPayload($topic))
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert a category record into a frontend payload.
     * Inputs: A customer-scoped category.
     * Returns: A frontend-ready payload for the category list.
     * Side effects: None.
     */
    private function categoryPayload(KnowledgeDocumentCategory $category): array
    {
        $topics = $category->topics
            ->map(static fn (KnowledgeDocumentTopic $topic): array => [
                'id' => $topic->id,
                'name' => $topic->name,
            ])
            ->values()
            ->all();

        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'is_active' => (bool) $category->is_active,
            'status_label' => $category->is_active ? __('procynia.knowledge_base_settings.active') : __('procynia.knowledge_base_settings.inactive'),
            'topic_ids' => $category->topics->pluck('id')->values()->all(),
            'topics' => $topics,
            'update_url' => route('app.customer-environment.knowledge-base.categories.update', ['category' => $category->id]),
            'destroy_url' => route('app.customer-environment.knowledge-base.categories.destroy', ['category' => $category->id]),
            'created_at' => optional($category->created_at)?->toIso8601String(),
            'updated_at' => optional($category->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Purpose: Convert a topic record into a frontend payload.
     * Inputs: A customer-scoped topic.
     * Returns: A frontend-ready payload for the topic list.
     * Side effects: None.
     */
    private function topicPayload(KnowledgeDocumentTopic $topic): array
    {
        return [
            'id' => $topic->id,
            'name' => $topic->name,
            'description' => $topic->description,
            'is_active' => (bool) $topic->is_active,
            'status_label' => $topic->is_active ? __('procynia.knowledge_base_settings.active') : __('procynia.knowledge_base_settings.inactive'),
            'update_url' => route('app.customer-environment.knowledge-base.topics.update', ['topic' => $topic->id]),
            'destroy_url' => route('app.customer-environment.knowledge-base.topics.destroy', ['topic' => $topic->id]),
            'created_at' => optional($topic->created_at)?->toIso8601String(),
            'updated_at' => optional($topic->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Purpose: Validate and normalize a category or topic payload.
     * Inputs: The current frontend request, the active customer id, and the catalog type.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedCatalogPayload(Request $request, int $customerId, string $catalogType, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'customer_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ]);

        $name = Str::squish((string) $validated['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('procynia.knowledge_base_settings.name')]),
            ]);
        }

        $description = $this->normalizeText($validated['description'] ?? null);
        $isActive = (bool) $validated['is_active'];

        if ($isActive) {
            $this->ensureUniqueCatalogName($customerId, $name, $catalogType, $ignoreId);
        }

        return [
            'name' => $name,
            'description' => $description,
            'is_active' => $isActive,
        ];
    }

    /**
     * Purpose: Validate topic links for a category when the request includes them.
     * Inputs: The current request and customer id.
     * Returns: The approved topic ids, or null when the request does not touch topic links.
     * Side effects: Throws validation errors when topic links are invalid or cross-customer.
     */
    private function validatedTopicIds(Request $request, int $customerId): ?array
    {
        if (! $request->exists('topic_ids')) {
            return null;
        }

        $validated = $request->validate([
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer'],
        ]);

        $topicIds = collect($validated['topic_ids'] ?? [])
            ->filter(static fn ($value): bool => $value !== null && $value !== '')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        $approvedTopicIds = KnowledgeDocumentTopic::query()
            ->forCustomer($customerId)
            ->active()
            ->whereIn('id', $topicIds)
            ->pluck('id')
            ->all();

        sort($topicIds);
        sort($approvedTopicIds);

        if ($topicIds !== $approvedTopicIds) {
            throw ValidationException::withMessages([
                'topic_ids' => __('procynia.knowledge_base_settings.validation.invalid_topic_selection'),
            ]);
        }

        return $topicIds;
    }

    /**
     * Purpose: Ensure that an active catalog name is unique within a customer.
     * Inputs: The customer id, normalized name, catalog type, and optional id to ignore.
     * Returns: None.
     * Side effects: Throws a validation error when an active duplicate exists.
     */
    private function ensureUniqueCatalogName(int $customerId, string $name, string $catalogType, ?int $ignoreId = null): void
    {
        $query = match ($catalogType) {
            'category' => KnowledgeDocumentCategory::query(),
            'topic' => KnowledgeDocumentTopic::query(),
            default => null,
        };

        abort_unless($query !== null, 500, 'Ukjent katalogtype.');

        $query->where('customer_id', $customerId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => $catalogType === 'category'
                    ? __('procynia.knowledge_base_settings.validation.duplicate_category_name')
                    : __('procynia.knowledge_base_settings.validation.duplicate_topic_name'),
            ]);
        }
    }

    /**
     * Purpose: Normalize optional text input.
     * Inputs: A raw input string.
     * Returns: The cleaned string or null when blank.
     * Side effects: None.
     */
    private function normalizeText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Purpose: Resolve one visible category for the current customer.
     * Inputs: The current customer id and the category id.
     * Returns: The matching category record.
     * Side effects: Throws a 404 when the item is outside the current customer scope.
     */
    private function scopedCategory(int $customerId, int $categoryId): KnowledgeDocumentCategory
    {
        return KnowledgeDocumentCategory::query()
            ->forCustomer($customerId)
            ->whereKey($categoryId)
            ->firstOrFail();
    }

    /**
     * Purpose: Resolve one visible topic for the current customer.
     * Inputs: The current customer id and the topic id.
     * Returns: The matching topic record.
     * Side effects: Throws a 404 when the item is outside the current customer scope.
     */
    private function scopedTopic(int $customerId, int $topicId): KnowledgeDocumentTopic
    {
        return KnowledgeDocumentTopic::query()
            ->forCustomer($customerId)
            ->whereKey($topicId)
            ->firstOrFail();
    }
}
