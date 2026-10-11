<?php

namespace App\Services\Compliance\Library;

use App\Models\ComplianceFramework;
use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceSource;
use App\Support\Compliance\FrameworkModuleLinks;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Brings Kravbiblioteket in line with a framework definition file (database/data/compliance_frameworks).
 * The only writer of compliance_frameworks and compliance_framework_nodes.
 *
 * Idempotent: the framework is found by key and every node by node_key, so a second run with the
 * same file changes nothing. A run with a changed file updates what changed, adds what is new,
 * withdraws (never deletes) what is gone and restores what comes back. The whole definition is
 * checked before anything is written; an invalid file writes nothing.
 *
 * Customer data is touched in one way only: customers that have already activated the framework
 * get their register brought up to date through ComplianceFrameworkActivationService::sync() — new
 * requirements added, the library-owned reference, title and text refreshed. Owners, relevance
 * judgements, assessments, links and history are never written here, and a customer that has not
 * activated the framework gets nothing.
 */
class ComplianceFrameworkLibraryImporter
{
    public function __construct(
        private readonly ComplianceFrameworkActivationService $activation,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array{framework: ComplianceFramework, created: int, updated: int, unchanged: int, withdrawn: int, restored: int, customers_synced: int, customer_requirements_created: int, customer_requirements_updated: int}
     */
    public function import(array $definition): array
    {
        $nodes = $this->flatten($definition);

        return DB::transaction(function () use ($definition, $nodes): array {
            $framework = ComplianceFramework::query()->firstOrNew(['key' => $definition['key']]);
            $framework->fill([
                'catalog_key' => $definition['catalog_key'],
                'reference' => $definition['reference'],
                'edition' => $definition['edition'],
                'name' => $definition['name'],
                'description' => $definition['description'],
                'scope_note' => $definition['scope_note'] ?? null,
                'content_verified' => (bool) ($definition['content_verified'] ?? false),
                'verification_note' => $definition['verification_note'] ?? null,
                'position' => (int) ($definition['position'] ?? 0),
            ])->save();

            $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'restored' => 0];
            $existing = ComplianceFrameworkNode::query()->where('framework_id', $framework->id)->get()->keyBy('node_key');
            $idsByClause = [];

            foreach ($nodes as $node) {
                $model = $existing->get($node['node_key']) ?? new ComplianceFrameworkNode(['framework_id' => $framework->id, 'node_key' => $node['node_key']]);
                $wasWithdrawn = $model->exists && $model->withdrawn_at !== null;

                $model->fill([
                    'parent_id' => $node['parent_clause'] !== null ? $idsByClause[$node['parent_clause']] : null,
                    'clause' => $node['clause'],
                    'kind' => $node['kind'],
                    'depth' => $node['depth'],
                    'position' => $node['position'],
                    'title' => $node['title'],
                    'description' => $node['description'],
                    'guidance' => $node['guidance'],
                    'suggested_documentation' => $node['documentation'],
                    'requirement_type' => $node['type'],
                    'documented_information' => $node['info'],
                    'applicability_rule' => $node['rule'],
                    'related_modules' => $node['modules'],
                    'withdrawn_at' => null,
                ]);

                if (! $model->exists) {
                    $report['created']++;
                } elseif ($wasWithdrawn) {
                    $report['restored']++;
                } elseif ($model->isDirty()) {
                    $report['updated']++;
                } else {
                    $report['unchanged']++;
                }

                $model->save();
                $idsByClause[$node['clause']] = (int) $model->id;
            }

            $present = array_column($nodes, 'node_key');
            $report['withdrawn'] = ComplianceFrameworkNode::query()
                ->where('framework_id', $framework->id)
                ->whereNull('withdrawn_at')
                ->whereNotIn('node_key', $present)
                ->update(['withdrawn_at' => now(), 'updated_at' => now()]);

            $synced = ['customers_synced' => 0, 'customer_requirements_created' => 0, 'customer_requirements_updated' => 0];

            ComplianceSource::query()->where('framework_id', $framework->id)->orderBy('id')->get()
                ->each(function (ComplianceSource $source) use ($framework, &$synced): void {
                    $result = $this->activation->sync($framework, $source, null);
                    $synced['customers_synced']++;
                    $synced['customer_requirements_created'] += $result['created'];
                    $synced['customer_requirements_updated'] += $result['updated'];
                });

            return ['framework' => $framework->fresh()] + $report + $synced;
        });
    }

    /** @return array<string, mixed> the definition in the file, unchecked until import() */
    public static function definitionFromFile(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Framework definition not found: {$path}");
        }

        $definition = require $path;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Framework definition must return an array: {$path}");
        }

        return $definition;
    }

    /**
     * The tree as nodes in the standard's order, each knowing its parent, and the whole definition
     * checked: required fields, unique clauses, a requirement is a leaf with every requirement field
     * set, a section has children, and every value is one the schema accepts.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{node_key: string, clause: string, parent_clause: string|null, kind: string, depth: int, position: int, title: string, description: string|null, guidance: string|null, documentation: list<string>|null, type: string|null, info: string|null, rule: string|null, modules: list<string>|null}>
     */
    public function flatten(array $definition): array
    {
        foreach (['key', 'catalog_key', 'reference', 'edition', 'name', 'description'] as $field) {
            if (! is_string($definition[$field] ?? null) || trim($definition[$field]) === '') {
                throw new InvalidArgumentException("Framework definition is missing [{$field}].");
            }
        }

        if (! is_array($definition['chapters'] ?? null) || $definition['chapters'] === []) {
            throw new InvalidArgumentException('Framework definition has no chapters.');
        }

        $nodes = [];
        $seen = [];
        $walk = function (array $items, ?string $parent, int $depth) use (&$walk, &$nodes, &$seen, $definition): void {
            foreach ($items as $item) {
                $clause = trim((string) ($item['clause'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));

                if ($clause === '' || $title === '') {
                    throw new InvalidArgumentException('Every node needs a clause and a title.');
                }

                if (isset($seen[$clause])) {
                    throw new InvalidArgumentException("Clause [{$clause}] appears twice.");
                }

                if ($parent !== null && ! str_starts_with($clause, $parent.'.')) {
                    throw new InvalidArgumentException("Clause [{$clause}] is not under its parent [{$parent}].");
                }

                $seen[$clause] = true;
                $children = $item['children'] ?? null;
                $isSection = $children !== null;

                if ($isSection && (! is_array($children) || $children === [])) {
                    throw new InvalidArgumentException("Section [{$clause}] has no children.");
                }

                if (! $isSection) {
                    $this->checkRequirement($clause, $item);
                }

                $nodes[] = [
                    'node_key' => $definition['key'].'/'.$clause,
                    'clause' => $clause,
                    'parent_clause' => $parent,
                    'kind' => $isSection ? ComplianceFrameworkNode::KIND_SECTION : ComplianceFrameworkNode::KIND_REQUIREMENT,
                    'depth' => $depth,
                    'position' => (count($nodes) + 1) * 10,
                    'title' => $title,
                    'description' => $isSection ? null : trim((string) $item['description']),
                    'guidance' => $isSection ? null : (trim((string) ($item['guidance'] ?? '')) ?: null),
                    'documentation' => $isSection ? null : array_values(array_map('strval', $item['documentation'] ?? [])),
                    'type' => $isSection ? null : $item['type'],
                    'info' => $isSection ? null : ($item['info'] ?? null),
                    'rule' => $isSection ? null : $item['rule'],
                    'modules' => $isSection ? null : array_values($item['modules'] ?? []),
                ];

                if ($isSection) {
                    $walk($children, $clause, $depth + 1);
                }
            }
        };

        $walk($definition['chapters'], null, 1);

        return $nodes;
    }

    /** @param  array<string, mixed>  $item */
    private function checkRequirement(string $clause, array $item): void
    {
        if (trim((string) ($item['description'] ?? '')) === '') {
            throw new InvalidArgumentException("Requirement [{$clause}] has no description.");
        }

        if (! in_array($item['type'] ?? null, ComplianceFrameworkNode::TYPES, true)) {
            throw new InvalidArgumentException("Requirement [{$clause}] has an unknown type.");
        }

        if (! in_array($item['rule'] ?? null, ComplianceFrameworkNode::RULES, true)) {
            throw new InvalidArgumentException("Requirement [{$clause}] has an unknown applicability rule.");
        }

        if (($item['info'] ?? null) !== null && ! in_array($item['info'], ComplianceFrameworkNode::DOCUMENTED_INFORMATION, true)) {
            throw new InvalidArgumentException("Requirement [{$clause}] has an unknown documented information value.");
        }

        foreach ($item['modules'] ?? [] as $module) {
            if (! FrameworkModuleLinks::exists((string) $module)) {
                throw new InvalidArgumentException("Requirement [{$clause}] names an unknown module [{$module}].");
            }
        }
    }
}
