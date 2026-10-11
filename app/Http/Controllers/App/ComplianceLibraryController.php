<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ComplianceFramework;
use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\Library\ComplianceFrameworkActivationService;
use App\Services\Compliance\Library\ComplianceFrameworkPresenter;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etterlevelse og revisjon → Kravbibliotek: which standardised requirement packages exist, what one
 * contains, and «Aktiver for virksomheten» — offered on each standard's card in the library, never
 * on the standard's own page, which is for exploring it.
 *
 * The library is Procynia's and the same for every customer, so reading it needs only
 * compliance.view. Everything about the customer's own register — whether the framework is
 * activated, how many requirements it holds, each requirement's relevance — is read through
 * ComplianceAccessService, so
 * another customer's activation is invisible. Activating writes requirements into the register and
 * needs compliance.edit, the same as registering a requirement by hand.
 */
class ComplianceLibraryController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ComplianceAccessService $access,
        private readonly ComplianceFrameworkActivationService $activation,
        private readonly ComplianceFrameworkPresenter $presenter,
    ) {}

    public function index(): Response
    {
        $user = $this->authorizedUser();
        $sources = $this->activatedSources($user);

        $frameworks = ComplianceFramework::query()
            ->withCount([
                'requirementNodes',
                'requirementNodes as control_nodes_count' => fn ($query) => $query->where('applicability_rule', ComplianceFrameworkNode::RULE_CONTROL),
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (ComplianceFramework $framework) use ($sources): array {
                $source = $sources->get((int) $framework->id);

                return $this->presenter->framework($framework) + [
                    'requirement_count' => (int) $framework->requirement_nodes_count,
                    'has_controls' => (int) $framework->control_nodes_count > 0,
                    'activated' => $source !== null,
                    // How many of its requirements are in the register — the card says no more than
                    // that; where they stand is Etterlevelsesvurdering's.
                    'register_count' => $source !== null ? (int) $source->requirements_count : null,
                    'register_url' => $source !== null ? route('app.compliance.requirements.index', ['source' => $source->id]) : null,
                    'assessments_url' => $source !== null ? route('app.compliance.assessments.index', ['source' => $source->id]) : null,
                    'activate_url' => route('app.compliance.library.activate', ['frameworkId' => $framework->id]),
                ];
            })
            ->all();

        return Inertia::render('App/Compliance/Library/Index', [
            'frameworks' => $frameworks,
            'permissions' => ['can_activate' => $this->access->canEdit($user)],
        ]);
    }

    public function show(int $frameworkId): Response
    {
        $user = $this->authorizedUser();
        $framework = ComplianceFramework::query()->findOrFail($frameworkId);
        $source = $this->activatedSources($user)->get((int) $framework->id);
        $nodes = $framework->nodes()->get();

        return Inertia::render('App/Compliance/Library/Show', [
            'framework' => $this->presenter->framework($framework) + [
                'requirement_count' => $nodes->filter(fn ($node): bool => $node->isRequirement() && ! $node->isWithdrawn())->count(),
                'chapter_count' => $nodes->where('depth', 1)->count(),
                'has_controls' => $nodes->contains(fn ($node): bool => $node->isControl() && ! $node->isWithdrawn()),
            ],
            'nodes' => $this->presenter->nodes($nodes, $user, $source !== null ? $this->presenter->register($source, $user) : []),
            'activation' => $source !== null ? [
                'source_id' => (int) $source->id,
                'activated_at' => $source->created_at?->toIso8601String(),
                'register_url' => route('app.compliance.requirements.index', ['source' => $source->id]),
                'assessments_url' => route('app.compliance.assessments.index', ['source' => $source->id]),
            ] : null,
        ]);
    }

    /**
     * «Aktiver for virksomheten», from the standard's card in Kravbiblioteket. Idempotent: activating
     * again — a double click, a repeated request — only brings the register up to date. Back to the
     * library, where the card now reads Aktivert.
     */
    public function activate(int $frameworkId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $framework = ComplianceFramework::query()->findOrFail($frameworkId);
        abort_unless($this->access->canEdit($user), 403);

        $alreadyActivated = $this->activatedSources($user)->has((int) $framework->id);
        $this->activation->activate($framework, $user);

        return redirect()
            ->route('app.compliance.library.index')
            ->with('success', __($alreadyActivated ? 'procynia.compliance.library.flash.already_activated' : 'procynia.compliance.library.flash.activated', ['name' => $framework->reference]));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    /** @return Collection<int, ComplianceSource> the customer's activations, keyed by framework id */
    private function activatedSources(User $user): Collection
    {
        return $this->access->visibleSources($user)
            ->whereNotNull('compliance_sources.framework_id')
            ->withCount('requirements')
            ->get()
            ->keyBy(fn (ComplianceSource $source): int => (int) $source->framework_id);
    }
}
