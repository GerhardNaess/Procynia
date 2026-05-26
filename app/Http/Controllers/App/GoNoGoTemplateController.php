<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GoNoGoAssessmentCriterion;
use App\Models\GoNoGoAssessmentTemplate;
use App\Models\User;
use App\Services\GoNoGo\GoNoGoDefaultTemplateService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GoNoGoTemplateController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly GoNoGoDefaultTemplateService $defaultTemplateService,
    ) {
    }

    public function index(Request $request): Response
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        // Ensure the customer always has at least one default template
        $this->defaultTemplateService->ensureDefaultExists($customerId);

        $templates = GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $customerId)
            ->withCount('criteria')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (GoNoGoAssessmentTemplate $t): array => [
                'id'           => $t->id,
                'name'         => $t->name,
                'description'  => $t->description,
                'is_default'   => $t->is_default,
                'is_active'    => $t->is_active,
                'criteria_count' => (int) $t->criteria_count,
                'edit_url'     => route('app.go-no-go-templates.edit', ['template' => $t->id]),
                'set_default_url' => route('app.go-no-go-templates.set-default', ['template' => $t->id]),
                'toggle_active_url' => route('app.go-no-go-templates.toggle-active', ['template' => $t->id]),
            ])
            ->all();

        return Inertia::render('App/GoNoGo/Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        GoNoGoAssessmentTemplate::query()->create([
            'customer_id' => $customerId,
            'name'        => Str::squish($validated['name']),
            'description' => $this->normalizeText($validated['description'] ?? null),
            'is_default'  => false,
            'is_active'   => true,
            'created_by'  => $actor->id,
            'updated_by'  => $actor->id,
        ]);

        return redirect()->route('app.go-no-go-templates.index')
            ->with('success', 'Malen ble opprettet.');
    }

    public function edit(Request $request, int $template): Response
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $record = $this->scopedTemplate($customerId, $template);

        $criteria = $record->criteria()
            ->get()
            ->map(fn (GoNoGoAssessmentCriterion $c): array => $this->criterionPayload($c))
            ->all();

        return Inertia::render('App/GoNoGo/Templates/Edit', [
            'template' => [
                'id'          => $record->id,
                'name'        => $record->name,
                'description' => $record->description,
                'is_default'  => $record->is_default,
                'is_active'   => $record->is_active,
                'update_url'  => route('app.go-no-go-templates.update', ['template' => $record->id]),
                'toggle_active_url' => route('app.go-no-go-templates.toggle-active', ['template' => $record->id]),
                'set_default_url'   => route('app.go-no-go-templates.set-default', ['template' => $record->id]),
                'criteria_store_url' => route('app.go-no-go-templates.criteria.store', ['template' => $record->id]),
            ],
            'criteria' => $criteria,
        ]);
    }

    public function update(Request $request, int $template): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $record = $this->scopedTemplate($customerId, $template);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $record->fill([
            'name'        => Str::squish($validated['name']),
            'description' => $this->normalizeText($validated['description'] ?? null),
            'updated_by'  => $actor->id,
        ])->save();

        return back()->with('success', 'Malen ble oppdatert.');
    }

    public function toggleActive(Request $request, int $template): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $record = $this->scopedTemplate($customerId, $template);

        // Cannot deactivate the only active default template
        if ($record->is_default && $record->is_active) {
            return back()->with('error', 'Standardmalen kan ikke deaktiveres. Sett en annen mal som standard først.');
        }

        $record->forceFill([
            'is_active'  => ! $record->is_active,
            'updated_by' => $actor->id,
        ])->save();

        $message = $record->is_active ? 'Malen ble aktivert.' : 'Malen ble deaktivert.';

        return back()->with('success', $message);
    }

    public function setDefault(Request $request, int $template): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $record = $this->scopedTemplate($customerId, $template);

        abort_unless($record->is_active, 422, 'Kun aktive maler kan settes som standard.');

        // Remove default flag from all other templates for this customer
        GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $customerId)
            ->where('id', '!=', $record->id)
            ->update(['is_default' => false]);

        $record->forceFill([
            'is_default' => true,
            'updated_by' => $actor->id,
        ])->save();

        return back()->with('success', "{$record->name} er nå standardmal.");
    }

    // ── Criteria ──────────────────────────────────────────────────────────────

    public function storeCriterion(Request $request, int $template): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $record = $this->scopedTemplate($customerId, $template);

        $validated = $request->validate($this->criterionRules());

        $maxSortOrder = $record->criteria()->max('sort_order') ?? 0;

        GoNoGoAssessmentCriterion::query()->create(array_merge(
            ['template_id' => $record->id, 'sort_order' => $maxSortOrder + 1],
            $this->normalizeCriterionData($validated),
        ));

        return back()->with('success', 'Vurderingspunkt ble lagt til.');
    }

    public function updateCriterion(Request $request, int $template, int $criterion): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $record = $this->scopedTemplate($customerId, $template);
        $criterionRecord = $this->scopedCriterion($record->id, $criterion);

        $validated = $request->validate(array_merge(
            $this->criterionRules(),
            ['sort_order' => ['required', 'integer', 'min:1', 'max:999']],
        ));

        $criterionRecord->fill($this->normalizeCriterionData($validated))->save();

        return back()->with('success', 'Vurderingspunkt ble oppdatert.');
    }

    public function toggleActiveCriterion(Request $request, int $template, int $criterion): RedirectResponse
    {
        [$actor, $customerId] = $this->systemOwnerContext($request);

        $record = $this->scopedTemplate($customerId, $template);
        $criterionRecord = $this->scopedCriterion($record->id, $criterion);

        $criterionRecord->forceFill(['is_active' => ! $criterionRecord->is_active])->save();

        $message = $criterionRecord->is_active ? 'Punktet ble aktivert.' : 'Punktet ble deaktivert.';

        return back()->with('success', $message);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function systemOwnerContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User && $user->isSystemOwner() && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    private function scopedTemplate(int $customerId, int $templateId): GoNoGoAssessmentTemplate
    {
        return GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $customerId)
            ->whereKey($templateId)
            ->firstOrFail();
    }

    private function scopedCriterion(int $templateId, int $criterionId): GoNoGoAssessmentCriterion
    {
        return GoNoGoAssessmentCriterion::query()
            ->where('template_id', $templateId)
            ->whereKey($criterionId)
            ->firstOrFail();
    }

    private function criterionRules(): array
    {
        return [
            'title'                    => ['required', 'string', 'max:255'],
            'short_description'        => ['nullable', 'string', 'max:500'],
            'help_what_is_assessed'    => ['nullable', 'string', 'max:2000'],
            'help_why_it_matters'      => ['nullable', 'string', 'max:2000'],
            'help_what_to_investigate' => ['nullable', 'string', 'max:2000'],
            'help_positive_indicators' => ['nullable', 'string', 'max:2000'],
            'help_warning_signs'       => ['nullable', 'string', 'max:2000'],
            'help_example_assessment'  => ['nullable', 'string', 'max:2000'],
            'weight'                   => ['required', 'integer', 'min:1', 'max:5'],
            'is_score_reversed'        => ['required', 'boolean'],
            'is_active'                => ['sometimes', 'boolean'],
        ];
    }

    private function normalizeCriterionData(array $validated): array
    {
        return [
            'title'                    => Str::squish($validated['title']),
            'short_description'        => $this->normalizeText($validated['short_description'] ?? null),
            'help_what_is_assessed'    => $this->normalizeText($validated['help_what_is_assessed'] ?? null),
            'help_why_it_matters'      => $this->normalizeText($validated['help_why_it_matters'] ?? null),
            'help_what_to_investigate' => $this->normalizeText($validated['help_what_to_investigate'] ?? null),
            'help_positive_indicators' => $this->normalizeText($validated['help_positive_indicators'] ?? null),
            'help_warning_signs'       => $this->normalizeText($validated['help_warning_signs'] ?? null),
            'help_example_assessment'  => $this->normalizeText($validated['help_example_assessment'] ?? null),
            'weight'                   => (int) $validated['weight'],
            'is_score_reversed'        => (bool) ($validated['is_score_reversed'] ?? false),
            'sort_order'               => isset($validated['sort_order']) ? (int) $validated['sort_order'] : null,
            'is_active'                => (bool) ($validated['is_active'] ?? true),
        ];
    }

    private function criterionPayload(GoNoGoAssessmentCriterion $c): array
    {
        return [
            'id'                       => $c->id,
            'title'                    => $c->title,
            'short_description'        => $c->short_description,
            'help_what_is_assessed'    => $c->help_what_is_assessed,
            'help_why_it_matters'      => $c->help_why_it_matters,
            'help_what_to_investigate' => $c->help_what_to_investigate,
            'help_positive_indicators' => $c->help_positive_indicators,
            'help_warning_signs'       => $c->help_warning_signs,
            'help_example_assessment'  => $c->help_example_assessment,
            'weight'                   => $c->weight,
            'is_score_reversed'        => $c->is_score_reversed,
            'sort_order'               => $c->sort_order,
            'is_active'                => $c->is_active,
            'update_url'               => route('app.go-no-go-templates.criteria.update', ['template' => $c->template_id, 'criterion' => $c->id]),
            'toggle_active_url'        => route('app.go-no-go-templates.criteria.toggle-active', ['template' => $c->template_id, 'criterion' => $c->id]),
        ];
    }

    private function normalizeText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
