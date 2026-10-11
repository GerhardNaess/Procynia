<?php

namespace App\Services\Compliance\Library;

use App\Models\ComplianceFramework;
use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «Aktiver for virksomheten»: turns a Kravbibliotek framework into the customer's own register —
 * one ComplianceSource carrying framework_id, one ComplianceRequirement per library requirement
 * carrying framework_node_id. From there everything Etterlevelse og revisjon already does
 * (assessments, audits, Kvalitet links, Trenger oppmerksomhet) works on them unchanged.
 *
 * Idempotent, at three levels: the customer row is locked so two activations queue up; the source
 * and every requirement are looked up before they are written; and the partial unique indexes
 * (customer, framework) and (customer, node) refuse a duplicate the lookups missed.
 *
 * What the customer owns is never written here: owner, review interval, relevance judgement,
 * assessments, links, status and history. What the library owns — reference, title and
 * requirement text — follows the library on every sync; an assessment keeps its own snapshot, so a
 * changed text shows as «endret siden siste vurdering» rather than rewriting history.
 *
 * Relevance starts where the standard puts it: a mandatory requirement applies within the scope; a
 * conditional one is Ikke vurdert until the customer decides.
 *
 * Authorization is the caller's (compliance.edit through ComplianceAccessService).
 */
class ComplianceFrameworkActivationService
{
    public function activate(ComplianceFramework $framework, User $actor): ComplianceSource
    {
        return DB::transaction(function () use ($framework, $actor): ComplianceSource {
            $customerId = (int) $actor->customer_id;
            Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

            $source = ComplianceSource::query()
                ->where('customer_id', $customerId)
                ->where('framework_id', $framework->id)
                ->first();

            if ($source === null) {
                $source = ComplianceSource::query()->create([
                    'customer_id' => $customerId,
                    'framework_id' => (int) $framework->id,
                    'name' => $framework->reference,
                    'version' => $framework->edition,
                    'kind' => ComplianceSource::KIND_STANDARD,
                    'description' => $framework->name,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $this->sync($framework, $source, $actor);

            return $source;
        });
    }

    /**
     * Bring one customer's activated framework up to date with the library: add requirements the
     * register lacks, refresh the library-owned fields of the ones it has. Withdrawn library nodes
     * are left alone — the customer's requirement and its history stay as they are.
     *
     * @return array{created: int, updated: int}
     */
    public function sync(ComplianceFramework $framework, ComplianceSource $source, ?User $actor): array
    {
        $customerId = (int) $source->customer_id;

        $source->fill(['name' => $framework->reference, 'version' => $framework->edition]);

        if ($source->isDirty()) {
            $source->save();
        }

        $nodes = $framework->requirementNodes()->get();
        $existing = ComplianceRequirement::query()
            ->where('customer_id', $customerId)
            ->whereIn('framework_node_id', $nodes->pluck('id'))
            ->get()
            ->keyBy(fn (ComplianceRequirement $requirement): int => (int) $requirement->framework_node_id);

        $created = 0;
        $updated = 0;

        foreach ($nodes as $node) {
            $libraryFields = [
                'reference' => $node->clause,
                'title' => $node->title,
                'requirement_text' => (string) $node->description,
            ];

            $requirement = $existing->get((int) $node->id);

            if ($requirement === null) {
                $requirement = new ComplianceRequirement($libraryFields + [
                    'customer_id' => $customerId,
                    'source_id' => (int) $source->id,
                    'owner_user_id' => null,
                    'review_interval_months' => null,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
                $requirement->forceFill([
                    'framework_node_id' => (int) $node->id,
                    'applicability' => $node->applicability_rule === ComplianceFrameworkNode::RULE_MANDATORY
                        ? ComplianceRequirement::APPLICABILITY_APPLIES
                        : ComplianceRequirement::APPLICABILITY_NOT_ASSESSED,
                ])->save();
                $created++;

                continue;
            }

            $requirement->fill($libraryFields);

            if ($requirement->isDirty()) {
                $requirement->save();
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** The customer's activation of the framework, if any. Customer-scoped by the caller's user. */
    public function sourceFor(ComplianceFramework $framework, int $customerId): ?ComplianceSource
    {
        return ComplianceSource::query()
            ->where('customer_id', $customerId)
            ->where('framework_id', $framework->id)
            ->first();
    }
}
