<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewSnapshotSection;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Beslutningsgrunnlaget (plan §5, §10): one code path for the live basis of a draft and the frozen
 * basis of a finalized review.
 *
 *  - live(): each section built now, with the viewer's own access, narrowed to the review's scope.
 *  - capture(): the same build with the finalizing person's access, as rows to freeze.
 *  - snapshot(): the frozen rows, narrowed to what the reader may see today. Never the state now.
 *
 * Each section comes back with a state the page can say plainly:
 *  available (empty = «Ingen registrerte forhold»), module_unavailable, no_access, not_captured.
 */
final class ManagementReviewBasisService
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly DecisionRows $decisionRows,
    ) {}

    private function presenter(): SectionPresenter
    {
        return new SectionPresenter((int) config('management_review.list_limit', 50));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function live(User $viewer, ManagementReview $review, ?CarbonImmutable $now = null): array
    {
        $scope = ReviewScope::forReview($review, $now);
        $views = [];

        foreach ($this->catalog->keysFor($review) as $key) {
            $built = $this->buildFor($viewer, $key, $scope);
            $view = $this->view($key, $built['state'], $scope->capturedAt);

            if ($built['state'] === Catalog::STATE_AVAILABLE && $built['payload'] !== null) {
                $view['basis'] = $this->presenter()->present(
                    $built['payload'],
                    $built['area_ids'],
                    $key === 'previous_decisions' ? $this->catalog->caseAreaIds($viewer) : null,
                );
                $view['coverage'] = $built['complete'] ? 'complete' : 'partial';
            }

            $views[$key] = $view;
        }

        return $views;
    }

    /**
     * The rows to freeze at finalization: one per section with a basis, plus the review's own
     * decisions. Built with the finalizing person's access; `coverage` records it.
     *
     * @return list<array<string, mixed>>
     */
    public function capture(User $finalizer, ManagementReview $review, CarbonImmutable $now): array
    {
        $scope = ReviewScope::forReview($review, $now);
        $rows = [];

        foreach ($this->catalog->keysFor($review) as $key) {
            if (! $this->catalog->hasBasis($key)) {
                continue;
            }

            $built = $this->buildFor($finalizer, $key, $scope);
            $definition = $this->catalog->definition($key);

            $rows[] = [
                'section_key' => $key,
                'state' => match ($built['state']) {
                    Catalog::STATE_AVAILABLE => ManagementReviewSnapshotSection::STATE_CAPTURED,
                    Catalog::STATE_MODULE_UNAVAILABLE => ManagementReviewSnapshotSection::STATE_MODULE_UNAVAILABLE,
                    default => ManagementReviewSnapshotSection::STATE_NOT_CAPTURED,
                },
                'source_module' => $definition['module'] ?? null,
                'coverage' => [
                    'area_ids' => $built['area_ids'],
                    'complete' => $built['complete'],
                    'scope_area_ids' => $scope->areaIds,
                ],
                'payload' => $built['payload'] ?? ['schema_version' => SectionPayload::SCHEMA_VERSION],
            ];
        }

        $decisions = new SectionPayload(false, PHP_INT_MAX);
        $decisions->list('decisions');

        foreach ($this->decisionRows->rows($this->decisions($review), $scope->today) as $row) {
            $decisions->item('decisions', $row);
        }

        $rows[] = [
            'section_key' => Catalog::DECISIONS,
            'state' => ManagementReviewSnapshotSection::STATE_CAPTURED,
            'source_module' => null,
            'coverage' => ['area_ids' => null, 'complete' => true, 'scope_area_ids' => $scope->areaIds],
            'payload' => $decisions->toArray(),
        ];

        return $rows;
    }

    /**
     * The frozen basis, narrowed to what the reader may see today.
     *
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(User $reader, ManagementReview $review): array
    {
        /** @var Collection<string, ManagementReviewSnapshotSection> $rows */
        $rows = $review->snapshotSections()->get()->keyBy('section_key');
        $capturedAt = $rows->first()?->captured_at ?? $review->finalized_at;
        $views = [];

        foreach ($this->catalog->keysFor($review) as $key) {
            $row = $rows->get($key);

            if ($row === null) {
                $views[$key] = $this->view($key, Catalog::STATE_AVAILABLE, $capturedAt);

                continue;
            }

            if ($row->state === ManagementReviewSnapshotSection::STATE_MODULE_UNAVAILABLE) {
                $views[$key] = $this->view($key, Catalog::STATE_MODULE_UNAVAILABLE, $capturedAt);

                continue;
            }

            if ($row->state === ManagementReviewSnapshotSection::STATE_NOT_CAPTURED) {
                // Still say no_access rather than reveal whether there was data, when the reader may
                // not read the module either.
                $views[$key] = $this->view($key, $this->catalog->canRead($reader, $key) ? Catalog::STATE_NOT_CAPTURED : Catalog::STATE_NO_ACCESS, $capturedAt);

                continue;
            }

            $allowed = $this->readableAreas($reader, $key, $row->coverage);

            if (! $this->catalog->canRead($reader, $key) || $allowed === []) {
                $views[$key] = $this->view($key, Catalog::STATE_NO_ACCESS, $capturedAt);

                continue;
            }

            $view = $this->view($key, Catalog::STATE_AVAILABLE, $capturedAt);
            $view['basis'] = $this->presenter()->present(
                $row->payload,
                $allowed,
                $key === 'previous_decisions' ? $this->catalog->caseAreaIds($reader) : null,
            );
            $captured = $row->coverage['area_ids'] ?? null;
            $view['coverage'] = ! ($row->coverage['complete'] ?? true)
                ? 'captured_partial'
                : ($allowed === null || $captured === null || array_diff($captured, $allowed) === [] ? 'complete' : 'partial');
            $views[$key] = $view;
        }

        return $views;
    }

    /**
     * The review's own decisions as they stood at finalization, keyed by decision id, narrowed for the
     * reader. Empty for a draft.
     *
     * @return array<int, array<string, mixed>>
     */
    public function decisionsAtFinalization(User $reader, ManagementReview $review): array
    {
        $row = $review->snapshotSections()->where('section_key', Catalog::DECISIONS)->first();

        if ($row === null) {
            return [];
        }

        $presented = $this->presenter()->present($row->payload, null, $this->catalog->caseAreaIds($reader));
        $items = collect($presented['lists'])->firstWhere('key', 'decisions')['items'] ?? [];

        return collect($items)->keyBy(fn (array $item): int => (int) $item['id'])->all();
    }

    /**
     * The decisions of a review, with what DecisionRows reads.
     *
     * @return Collection<int, ManagementReviewDecision>
     */
    public function decisions(ManagementReview $review): Collection
    {
        return $review->decisions()
            ->with(['review:id,title', 'owner:id,name', 'improvementCase.owner:id,name'])
            ->get();
    }

    /**
     * One section built for one person: its live state, and — when available — its payload and the
     * fagområder it covers.
     *
     * @return array{state: string, payload: ?array, area_ids: ?list<int>, complete: bool}
     */
    private function buildFor(User $user, string $key, ReviewScope $scope): array
    {
        $state = $this->catalog->liveState($user, $key);
        $result = ['state' => $state, 'payload' => null, 'area_ids' => null, 'complete' => true];

        if ($state !== Catalog::STATE_AVAILABLE || ! $this->catalog->hasBasis($key)) {
            return $result;
        }

        $areaIds = null;

        if ($this->catalog->isAreaScoped($key)) {
            $areaIds = $scope->narrow($this->catalog->areaIds($user, $key) ?? []);

            // The person reaches fagområder, but none within this review's scope.
            if ($areaIds === []) {
                return ['state' => Catalog::STATE_NO_ACCESS] + $result;
            }
        }

        return [
            'state' => Catalog::STATE_AVAILABLE,
            'payload' => $this->catalog->builder($key)->build($user, $scope, $areaIds),
            'area_ids' => $areaIds,
            'complete' => $this->catalog->reachesWholeScope($user, $key, $scope->areaIds),
        ];
    }

    /**
     * For an area-scoped snapshot section, the fagområder of it the reader may see today: their own
     * within those captured. Null for a section that is not area-scoped.
     *
     * @param  array<string, mixed>  $coverage
     * @return list<int>|null
     */
    private function readableAreas(User $reader, string $key, array $coverage): ?array
    {
        if (! $this->catalog->isAreaScoped($key)) {
            return null;
        }

        $captured = array_map('intval', $coverage['area_ids'] ?? []);

        return array_values(array_intersect($captured, $this->catalog->areaIds($reader, $key) ?? []));
    }

    /** @return array<string, mixed> */
    private function view(string $key, string $state, mixed $capturedAt): array
    {
        return [
            'key' => $key,
            'type' => $this->catalog->definition($key)['type'],
            'state' => $state,
            'module' => $this->catalog->definition($key)['module'] ?? null,
            'has_basis' => $this->catalog->hasBasis($key),
            'basis' => null,
            'coverage' => null,
            'captured_at' => $capturedAt !== null ? CarbonImmutable::parse($capturedAt)->toIso8601String() : null,
        ];
    }
}
