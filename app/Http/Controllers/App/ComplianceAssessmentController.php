<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceAssessmentOverview;
use App\Services\Compliance\ComplianceStatusResolver;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etterlevelse og revisjon → Etterlevelsesvurdering: the worklist of the ongoing compliance work —
 * which requirements have been assessed, which are met, and where the gaps are, across every
 * standard and kravkilde the customer has.
 *
 * Read-only. A requirement is assessed on its own page (ComplianceRequirementController::assess),
 * which the worklist opens directly. Every row is reached through
 * ComplianceAccessService::visibleRequirements(), and each status comes from
 * ComplianceStatusResolver and ComplianceAssessmentOverview — the same answer as on the requirement
 * itself, never a second calculation.
 *
 * Only active requirements are part of the work. The figures follow the Standard filter; the
 * status filter and the search narrow the list only, so the figures always describe the whole of
 * what is chosen.
 */
class ComplianceAssessmentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ComplianceAccessService $access,
        private readonly ComplianceStatusResolver $status,
        private readonly ComplianceAssessmentOverview $overview,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $sources = $this->access->visibleSources($user)
            ->orderBy('name')
            ->orderBy('version')
            ->orderBy('id')
            ->get();
        $sourceIds = $sources->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        // Only a source the user can read is a filter; anything else is ignored rather than answered.
        $sourceId = (int) $request->query('source', 0);
        $sourceId = in_array($sourceId, $sourceIds, true) ? $sourceId : 0;
        $filter = in_array($request->query('status'), ComplianceAssessmentOverview::FILTERS, true) ? (string) $request->query('status') : '';
        $search = trim((string) $request->query('search', ''));
        $needle = mb_strtolower($search);

        $requirements = $this->access->visibleRequirements($user)
            ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
            ->inRegisterOrder()
            ->with(['source:id,name,version,framework_id', 'frameworkNode:id,applicability_rule'])
            ->get();

        $latest = $this->status->latestForRequirements(
            (int) $user->customer_id,
            $requirements->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        $total = $this->overview->emptyStats();
        $perSource = [];
        $rows = [];
        $canAssess = $this->access->canAssess($user);

        foreach ($requirements as $requirement) {
            $id = (int) $requirement->id;
            $summary = $this->status->summarize($requirement, $latest->get($id));
            $group = $this->overview->group($requirement, $latest->get($id));
            $overdue = (bool) $summary['is_overdue'];
            $requirementSource = (int) $requirement->source_id;

            $perSource[$requirementSource] ??= $this->overview->emptyStats();
            $this->overview->count($perSource[$requirementSource], $group, $overdue);

            if ($sourceId !== 0 && $requirementSource !== $sourceId) {
                continue;
            }

            $this->overview->count($total, $group, $overdue);

            if (! $this->overview->matches($filter, $group, $overdue) || ! $this->matchesSearch($requirement, $needle)) {
                continue;
            }

            $url = route('app.compliance.requirements.show', ['requirementId' => $id, 'from' => 'assessments']);
            $assessable = in_array($group, ComplianceAssessmentOverview::ASSESSABLE, true);

            $rows[] = [
                'id' => $id,
                'origin' => $requirement->origin(),
                'source_id' => $requirementSource,
                'group' => $group,
                'is_control' => (bool) $requirement->frameworkNode?->isControl(),
                'assessed_at' => $summary['assessed_at'],
                'assessed_by_name' => $summary['assessed_by_name'],
                'is_overdue' => $overdue && $assessable,
                'next_review_on' => $summary['next_review_on'],
                'url' => $url,
                // Opens the requirement with the assessment form already open.
                'assess_url' => $canAssess && $assessable ? route('app.compliance.requirements.show', ['requirementId' => $id, 'from' => 'assessments', 'assess' => 1]) : null,
                // Relevans or Kontrollvalg is decided in Kravregister, on the requirement itself.
                'relevance_url' => $group === ComplianceAssessmentOverview::RELEVANCE_OPEN
                    ? route('app.compliance.requirements.show', ['requirementId' => $id])
                    : null,
            ];
        }

        return Inertia::render('App/Compliance/Assessments/Index', [
            'stats' => $total,
            // One row per source that has active requirements, in the register's order of sources.
            'per_source' => $sources
                ->filter(fn (ComplianceSource $source): bool => isset($perSource[(int) $source->id]))
                ->map(fn (ComplianceSource $source): array => [
                    'id' => (int) $source->id,
                    'label' => $source->originLabel(),
                    'from_library' => $source->isFromLibrary(),
                    'stats' => $perSource[(int) $source->id],
                ])
                ->values()
                ->all(),
            'requirements' => $rows,
            'visible_count' => count($rows),
            'filters' => [
                'source' => $sourceId !== 0 ? $sourceId : null,
                'status' => $filter,
                'search' => $search,
            ],
            'status_filters' => ComplianceAssessmentOverview::FILTERS,
            'sources' => $sources->map(fn (ComplianceSource $source): array => [
                'id' => (int) $source->id,
                'label' => $source->originLabel(),
            ])->all(),
            'register_url' => route('app.compliance.requirements.index'),
            'permissions' => ['can_assess' => $canAssess],
        ]);
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    /** Search in the reference, title and requirement text, like the register's. */
    private function matchesSearch(ComplianceRequirement $requirement, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        foreach ([$requirement->reference, $requirement->title, $requirement->requirement_text] as $value) {
            if ($value !== null && str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }
}
