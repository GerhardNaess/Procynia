<?php

namespace Tests\Support;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditProcess;
use App\Models\ComplianceAuditRequirement;
use App\Models\ComplianceAuditStatusChange;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementControl;
use App\Models\ComplianceRequirementProcess;
use App\Models\ComplianceRequirementStatusChange;
use App\Models\ComplianceSource;
use App\Models\CustomerRole;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only setup and cleanup for the Etterlevelse og revisjon E2E spec
 * (tests/e2e/compliance.spec.js). Not autoloaded in production (autoload-dev only) — invoked via
 * `php artisan tinker --execute=...`.
 *
 * ONE NAME RULE, NO LIST OF NAMES — the same as ImprovementE2EFixture.
 *
 * Every source, role and requirement a spec creates is named «E2E Krav <SUFFIX> <anything>»
 * (tests/e2e/helpers/compliance.js::complianceE2eName builds it). Cleanup matches that prefix and
 * nothing else, in the E2E customer only, plus every requirement under one of the run's sources.
 * The people a spec works as are seeded fresh, carry the marker in their name and an
 * «e2e.krav.» address, and are removed with the run; the shared E2E users are never given a
 * compliance role.
 *
 * The Kvalitet process, control and evidence the Hvordan kravet oppfylles specs link to are
 * seeded here too, named by the same rule, and removed with the run; their links go with them.
 *
 * Revisjoner follow the same rule: an audit is the run's when its title carries the marker. They
 * are removed before the requirements, since a requirement in an audit's scope cannot be deleted.
 *
 * Status history (requirements and audits) and compliance assessments are append-only, and the
 * database refuses to delete them while the customer exists. Cleanup is the one place that must
 * remove them anyway, so it switches the three history triggers off for its own transaction only —
 * a test-only step, never something the product can do.
 */
class ComplianceE2EFixture
{
    public const PREFIX = 'E2E Krav';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    /** Leftovers from interrupted runs are only swept once they are this old, so a run in flight is never touched. */
    private const SWEEP_MIN_AGE_MINUTES = 10;

    /**
     * For the journey: a fresh person whose role gives view, edit and delete in Etterlevelse og
     * revisjon. The spec creates the source and the requirement itself, through the pages.
     *
     * @return array{name: string, email: string}
     */
    public static function seedJourney(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $person = self::person($customerId, $suffix, $password, $name('Kravansvarlig'), 'ansvarlig');
            self::role($customerId, $name('Kravforvalter'), [
                CustomerPermissionCatalog::COMPLIANCE_VIEW,
                CustomerPermissionCatalog::COMPLIANCE_EDIT,
                CustomerPermissionCatalog::COMPLIANCE_DELETE,
            ], $person);

            return ['name' => $person->name, 'email' => $person->email];
        });
    }

    /**
     * For the assessment journey: a fresh person whose role gives view, edit and assess — and not
     * delete. The spec creates the source and the requirement itself, through the pages.
     *
     * @return array{name: string, email: string}
     */
    public static function seedAssessor(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $person = self::person($customerId, $suffix, $password, $name('Vurderer'), 'vurderer');
            self::role($customerId, $name('Etterlevelsesvurderer'), [
                CustomerPermissionCatalog::COMPLIANCE_VIEW,
                CustomerPermissionCatalog::COMPLIANCE_EDIT,
                CustomerPermissionCatalog::COMPLIANCE_ASSESS,
            ], $person);

            return ['name' => $person->name, 'email' => $person->email];
        });
    }

    /**
     * For the access spec: a fresh reader (compliance.view only), a source, an active requirement
     * with one compliance assessment, and a retired one with its history — retired the way the
     * lifecycle would have done it.
     *
     * @return array{reader_email: string, source_label: string, active_id: int, active_title: string, retired_id: int, retired_title: string}
     */
    public static function seedReader(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $reader = self::person($customerId, $suffix, $password, $name('Leser'), 'leser');
            self::role($customerId, $name('Kravleser'), [CustomerPermissionCatalog::COMPLIANCE_VIEW], $reader);

            $source = ComplianceSource::query()->create([
                'customer_id' => $customerId,
                'name' => $name('Personopplysningsloven'),
                'kind' => ComplianceSource::KIND_LAW,
            ]);
            $requirement = fn (string $title, string $reference) => ComplianceRequirement::query()->create([
                'customer_id' => $customerId,
                'source_id' => $source->id,
                'reference' => $reference,
                'title' => $title,
                'requirement_text' => 'Registrert av E2E-fixturen.',
                'owner_user_id' => $reader->id,
                'review_interval_months' => 12,
            ]);

            $active = $requirement($name('Behandlingsprotokoll'), '§ 30');
            $retired = $requirement($name('Gammel meldeplikt'), '§ 31');

            ComplianceRequirementStatusChange::query()->create([
                'customer_id' => $customerId,
                'requirement_id' => $retired->id,
                'from_status' => ComplianceRequirement::STATUS_ACTIVE,
                'to_status' => ComplianceRequirement::STATUS_RETIRED,
                'note' => 'Meldeplikten er opphevet.',
                'changed_by_user_id' => $reader->id,
                'changed_at' => now(),
            ]);
            $retired->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();

            // Assessed once, the way ComplianceAssessmentService would have written it.
            ComplianceAssessment::query()->create([
                'customer_id' => $customerId,
                'requirement_id' => $active->id,
                'result' => ComplianceAssessment::RESULT_PARTIALLY_COMPLIANT,
                'rationale' => 'Protokollen finnes, men er ikke oppdatert.',
                'assessed_by_user_id' => $reader->id,
                'assessed_at' => now(),
                'requirement_reference' => $active->reference,
                'requirement_title' => $active->title,
                'requirement_text' => $active->requirement_text,
                'source_name' => $source->name,
                'source_version' => $source->version,
            ]);

            return [
                'reader_email' => $reader->email,
                'source_label' => $source->name,
                'active_id' => (int) $active->id,
                'active_title' => $active->title,
                'retired_id' => (int) $retired->id,
                'retired_title' => $retired->title,
            ];
        });
    }

    /**
     * For the Hvordan kravet oppfylles journey: a fresh person who may view, edit and assess
     * requirements and read Kvalitet, and in Kvalitet a process, a control with its fields, and one
     * piece of evidence on the control. The spec creates the source and the requirement itself.
     *
     * @return array{name: string, email: string, process_title: string, control_title: string, evidence_title: string}
     */
    public static function seedQualityJourney(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $person = self::person($customerId, $suffix, $password, $name('Kravforvalter'), 'kobler');
            self::role($customerId, $name('Kravforvalter med Kvalitet'), [
                CustomerPermissionCatalog::COMPLIANCE_VIEW,
                CustomerPermissionCatalog::COMPLIANCE_EDIT,
                CustomerPermissionCatalog::COMPLIANCE_ASSESS,
                CustomerPermissionCatalog::QUALITY_VIEW,
            ], $person);
            $quality = self::qualityItems($customerId, $name, $person);

            return ['name' => $person->name, 'email' => $person->email] + $quality['labels'];
        });
    }

    /**
     * For the Kvalitet access spec: a fresh person with compliance.view and compliance.edit — and
     * no Kvalitet — and a requirement already linked to a seeded process and control with evidence.
     *
     * @return array{email: string, requirement_id: int, requirement_title: string, process_id: int, control_id: int, process_title: string, control_title: string, evidence_title: string}
     */
    public static function seedQualityHidden(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $person = self::person($customerId, $suffix, $password, $name('Uten Kvalitet'), 'utenkvalitet');
            self::role($customerId, $name('Kravforvalter uten Kvalitet'), [
                CustomerPermissionCatalog::COMPLIANCE_VIEW,
                CustomerPermissionCatalog::COMPLIANCE_EDIT,
            ], $person);
            $quality = self::qualityItems($customerId, $name, $person);

            $source = ComplianceSource::query()->create([
                'customer_id' => $customerId,
                'name' => $name('ISO 9001'),
                'kind' => ComplianceSource::KIND_STANDARD,
            ]);
            $requirement = ComplianceRequirement::query()->create([
                'customer_id' => $customerId,
                'source_id' => $source->id,
                'reference' => '8.5',
                'title' => $name('Styrt produksjon'),
                'requirement_text' => 'Registrert av E2E-fixturen.',
                'owner_user_id' => $person->id,
            ]);
            ComplianceRequirementProcess::query()->create(['customer_id' => $customerId, 'requirement_id' => $requirement->id, 'quality_process_id' => $quality['process']->id]);
            ComplianceRequirementControl::query()->create(['customer_id' => $customerId, 'requirement_id' => $requirement->id, 'control_item_id' => $quality['control']->id]);

            return [
                'email' => $person->email,
                'requirement_id' => (int) $requirement->id,
                'requirement_title' => $requirement->title,
                'process_id' => (int) $quality['process']->id,
                'control_id' => (int) $quality['control']->id,
            ] + $quality['labels'];
        });
    }

    /**
     * For the Trenger oppmerksomhet journey: a fresh person who may view, edit and assess, a second
     * person who will own the spec's requirement until they leave, and a source holding one
     * requirement that is owned and assessed «Oppfylt» today — so it needs no attention and the
     * filter has something to leave out. The spec creates its own requirement through the pages.
     *
     * @return array{name: string, email: string, former_owner_name: string, source_label: string, settled_title: string}
     */
    public static function seedAttention(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $person = self::person($customerId, $suffix, $password, $name('Oppfølger'), 'oppfolger');
            $formerOwner = self::person($customerId, $suffix, $password, $name('Tidligere ansvarlig'), 'tidligere');
            $permissions = [
                CustomerPermissionCatalog::COMPLIANCE_VIEW,
                CustomerPermissionCatalog::COMPLIANCE_EDIT,
                CustomerPermissionCatalog::COMPLIANCE_ASSESS,
            ];
            self::role($customerId, $name('Kravoppfølger'), $permissions, $person);
            self::role($customerId, $name('Kravleser'), [CustomerPermissionCatalog::COMPLIANCE_VIEW], $formerOwner);

            $source = ComplianceSource::query()->create([
                'customer_id' => $customerId,
                'name' => $name('ISO 27001'),
                'version' => '2022',
                'kind' => ComplianceSource::KIND_STANDARD,
            ]);
            $settled = ComplianceRequirement::query()->create([
                'customer_id' => $customerId,
                'source_id' => $source->id,
                'reference' => 'A.5.1',
                'title' => $name('Informasjonssikkerhetspolicy'),
                'requirement_text' => 'Registrert av E2E-fixturen.',
                'owner_user_id' => $person->id,
                'review_interval_months' => 12,
            ]);
            ComplianceAssessment::query()->create([
                'customer_id' => $customerId,
                'requirement_id' => $settled->id,
                'result' => ComplianceAssessment::RESULT_COMPLIANT,
                'rationale' => 'Policyen er vedtatt og publisert.',
                'assessed_by_user_id' => $person->id,
                'assessed_at' => now(),
                'requirement_reference' => $settled->reference,
                'requirement_title' => $settled->title,
                'requirement_text' => $settled->requirement_text,
                'source_name' => $source->name,
                'source_version' => $source->version,
            ]);

            return [
                'name' => $person->name,
                'email' => $person->email,
                'former_owner_name' => $formerOwner->name,
                'source_label' => $source->name.' ('.$source->version.')',
                'settled_title' => $settled->title,
            ];
        });
    }

    /**
     * For the Revisjoner journey: a fresh person who may view and audit — and read Kvalitet, but not
     * edit requirements — a source holding two active requirements, and a Kvalitet process. The spec
     * creates the audit itself, through the pages.
     *
     * @return array{name: string, email: string, source_label: string, first_requirement: string, second_requirement: string, process_title: string}
     */
    public static function seedAuditJourney(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $person = self::person($customerId, $suffix, $password, $name('Revisjonsansvarlig'), 'revisor');
            self::role($customerId, $name('Revisor med Kvalitet'), [
                CustomerPermissionCatalog::COMPLIANCE_VIEW,
                CustomerPermissionCatalog::COMPLIANCE_AUDIT,
                CustomerPermissionCatalog::QUALITY_VIEW,
            ], $person);

            $source = ComplianceSource::query()->create([
                'customer_id' => $customerId,
                'name' => $name('ISO 27001'),
                'version' => '2022',
                'kind' => ComplianceSource::KIND_STANDARD,
            ]);
            $requirement = fn (string $title, string $reference) => ComplianceRequirement::query()->create([
                'customer_id' => $customerId,
                'source_id' => $source->id,
                'reference' => $reference,
                'title' => $title,
                'requirement_text' => 'Registrert av E2E-fixturen.',
                'owner_user_id' => $person->id,
            ]);
            $first = $requirement($name('Tilgangsstyring'), 'A.5.15');
            $second = $requirement($name('Tilgangsrettigheter'), 'A.5.18');
            $process = QualityItem::query()->create([
                'customer_id' => $customerId,
                'quality_type' => QualityItem::TYPE_PROCESS,
                'title' => $name('Brukeradministrasjon'),
                'status' => QualityItem::STATUS_ACTIVE,
            ]);

            return [
                'name' => $person->name,
                'email' => $person->email,
                'source_label' => $source->name.' ('.$source->version.')',
                'first_requirement' => $first->title,
                'second_requirement' => $second->title,
                'process_title' => $process->title,
            ];
        });
    }

    /**
     * For the Revisjoner access spec: a fresh reader (compliance.view only, no Kvalitet) and an audit
     * in progress with a requirement and a Kvalitet process in scope — started the way the lifecycle
     * would have done it.
     *
     * @return array{email: string, audit_id: int, audit_title: string, requirement_title: string, process_id: int, process_title: string}
     */
    public static function seedAuditReader(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $reader = self::person($customerId, $suffix, $password, $name('Revisjonsleser'), 'revisjonsleser');
            self::role($customerId, $name('Leser'), [CustomerPermissionCatalog::COMPLIANCE_VIEW], $reader);

            $source = ComplianceSource::query()->create([
                'customer_id' => $customerId,
                'name' => $name('ISO 9001'),
                'kind' => ComplianceSource::KIND_STANDARD,
            ]);
            $requirement = ComplianceRequirement::query()->create([
                'customer_id' => $customerId,
                'source_id' => $source->id,
                'reference' => '9.2',
                'title' => $name('Internrevisjon'),
                'requirement_text' => 'Registrert av E2E-fixturen.',
                'owner_user_id' => $reader->id,
            ]);
            $process = QualityItem::query()->create([
                'customer_id' => $customerId,
                'quality_type' => QualityItem::TYPE_PROCESS,
                'title' => $name('Skjult prosess'),
                'status' => QualityItem::STATUS_ACTIVE,
            ]);
            $audit = ComplianceAudit::query()->create([
                'customer_id' => $customerId,
                'title' => $name('Kvalitetsrevisjon'),
                'audit_type' => ComplianceAudit::TYPE_EXTERNAL,
                'responsible_user_id' => $reader->id,
                'auditor_name' => 'Sertifiseringsorganet AS',
                'planned_start_date' => now()->toDateString(),
                'planned_end_date' => now()->addWeek()->toDateString(),
                'scope_description' => 'Revisjon av kvalitetsstyringssystemet.',
            ]);
            ComplianceAuditRequirement::query()->create(['customer_id' => $customerId, 'audit_id' => $audit->id, 'requirement_id' => $requirement->id]);
            ComplianceAuditProcess::query()->create(['customer_id' => $customerId, 'audit_id' => $audit->id, 'quality_process_id' => $process->id]);
            ComplianceAuditStatusChange::query()->create([
                'customer_id' => $customerId,
                'audit_id' => $audit->id,
                'from_status' => ComplianceAudit::STATUS_PLANNED,
                'to_status' => ComplianceAudit::STATUS_IN_PROGRESS,
                'changed_by_user_id' => $reader->id,
                'changed_at' => now(),
            ]);
            $audit->forceFill(['status' => ComplianceAudit::STATUS_IN_PROGRESS])->save();

            return [
                'email' => $reader->email,
                'audit_id' => (int) $audit->id,
                'audit_title' => $audit->title,
                'requirement_title' => $requirement->title,
                'process_id' => (int) $process->id,
                'process_title' => $process->title,
            ];
        });
    }

    /**
     * The former owner leaves: their account is deleted, and the requirements they owned are left
     * without an owner by the foreign key, the way it happens in the product.
     */
    public static function removeFormerOwner(string $suffix): array
    {
        $deleted = self::runUsers(self::customerId(), self::pattern($suffix))
            ->where('email', 'like', '%.tidligere@procynia.test')
            ->delete();

        return ['deleted' => $deleted];
    }

    /**
     * Moves the run's assessments the given number of months into the past, as if they had been
     * registered then — the test's clock, not the product's. Assessments are immutable, so the
     * history trigger is switched off for this transaction only, as in cleanup().
     */
    public static function ageAssessments(string $suffix, int $months): array
    {
        $customerId = self::customerId();
        $pattern = self::pattern($suffix);

        return DB::transaction(function () use ($customerId, $pattern, $months): array {
            $requirementIds = ComplianceRequirement::query()->where('customer_id', $customerId)->where('title', '~', $pattern)->pluck('id');

            DB::statement('ALTER TABLE compliance_assessments DISABLE TRIGGER compliance_assessments_immutable');
            $aged = DB::table('compliance_assessments')
                ->where('customer_id', $customerId)
                ->whereIn('requirement_id', $requirementIds)
                ->update(['assessed_at' => DB::raw("assessed_at - interval '{$months} months'")]);
            DB::statement('ALTER TABLE compliance_assessments ENABLE TRIGGER compliance_assessments_immutable');

            return ['aged' => $aged];
        });
    }

    /**
     * The run's Kvalitet items as Kvalitet has them — for checking that linking and unlinking from
     * a requirement changed nothing there.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public static function qualityState(string $suffix): array
    {
        $items = QualityItem::query()
            ->where('customer_id', self::customerId())
            ->where('title', '~', self::pattern($suffix))
            ->with('controlDetail')
            ->orderBy('id')
            ->get()
            ->map(fn (QualityItem $item): array => [
                'title' => $item->title,
                'type' => $item->quality_type,
                'status' => $item->status,
                'updated_at' => $item->updated_at?->toIso8601String(),
                'criterion' => $item->controlDetail?->criterion,
                'method' => $item->controlDetail?->method,
                'evidence' => QualityItemDocument::query()->where('quality_item_id', $item->id)->orderBy('id')->get(['title', 'note', 'updated_at'])->toArray(),
            ])
            ->all();

        return ['items' => $items];
    }

    /**
     * What is left of one run, for checking that cleanup really emptied it.
     *
     * @return array{sources: int, requirements: int, status_changes: int, assessments: int, requirement_processes: int, requirement_controls: int, audits: int, audit_status_changes: int, audit_requirements: int, audit_processes: int, quality_items: int, roles: int, users: int}
     */
    public static function remaining(string $suffix): array
    {
        $customerId = self::customerId();
        $pattern = self::pattern($suffix);
        $sourceIds = ComplianceSource::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->pluck('id');
        $requirementIds = ComplianceRequirement::query()->where('customer_id', $customerId)
            ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('source_id', $sourceIds))
            ->pluck('id');
        $auditIds = ComplianceAudit::query()->where('customer_id', $customerId)->where('title', '~', $pattern)->pluck('id');

        return [
            'sources' => $sourceIds->count(),
            'requirements' => $requirementIds->count(),
            'status_changes' => ComplianceRequirementStatusChange::query()->whereIn('requirement_id', $requirementIds)->count(),
            'assessments' => ComplianceAssessment::query()->whereIn('requirement_id', $requirementIds)->count(),
            'requirement_processes' => ComplianceRequirementProcess::query()->whereIn('requirement_id', $requirementIds)->count(),
            'requirement_controls' => ComplianceRequirementControl::query()->whereIn('requirement_id', $requirementIds)->count(),
            'audits' => $auditIds->count(),
            'audit_status_changes' => ComplianceAuditStatusChange::query()->whereIn('audit_id', $auditIds)->count(),
            'audit_requirements' => ComplianceAuditRequirement::query()->whereIn('audit_id', $auditIds)->orWhereIn('requirement_id', $requirementIds)->count(),
            'audit_processes' => ComplianceAuditProcess::query()->whereIn('audit_id', $auditIds)->count(),
            'quality_items' => QualityItem::query()->where('customer_id', $customerId)->where('title', '~', $pattern)->count(),
            'roles' => CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->count(),
            'users' => self::runUsers($customerId, $pattern)->count(),
        ];
    }

    /**
     * Removes what one run created (by its suffix), or — with no suffix — whatever interrupted runs
     * left behind. Idempotent, and safe after a run that stopped halfway.
     */
    public static function cleanup(?string $suffix = null): void
    {
        $customerId = self::customerId();
        $pattern = $suffix === null ? '^'.preg_quote(self::PREFIX).' '.self::SUFFIX_PATTERN.'( |$)' : self::pattern($suffix);
        $sweepOnly = fn (Builder $query): Builder => $suffix === null
            ? $query->where('created_at', '<', now()->subMinutes(self::SWEEP_MIN_AGE_MINUTES))
            : $query;

        DB::transaction(function () use ($customerId, $pattern, $sweepOnly): void {
            $sourceIds = $sweepOnly(ComplianceSource::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->pluck('id');

            // Test-only: the history trigger refuses deletes while the customer exists. Off for this
            // transaction's statements only; a failure rolls the switch back with everything else.
            DB::statement('ALTER TABLE compliance_requirement_status_changes DISABLE TRIGGER compliance_requirement_status_changes_immutable');
            DB::statement('ALTER TABLE compliance_assessments DISABLE TRIGGER compliance_assessments_immutable');
            DB::statement('ALTER TABLE compliance_audit_status_changes DISABLE TRIGGER compliance_audit_status_changes_immutable');

            // The run's audits first: their history and scope go with them through the cascade, and a
            // requirement in an audit's scope could not be deleted otherwise.
            $sweepOnly(ComplianceAudit::query()->where('customer_id', $customerId)->where('title', '~', $pattern))->delete();

            // A requirement is the run's when its title carries the marker, or when it sits under one
            // of the run's sources — a spec may retitle it, but not move it out of a source no one
            // else uses. History and assessments go with it through the cascade.
            $sweepOnly(ComplianceRequirement::query()->where('customer_id', $customerId)
                ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('source_id', $sourceIds)))
                ->delete();

            DB::statement('ALTER TABLE compliance_requirement_status_changes ENABLE TRIGGER compliance_requirement_status_changes_immutable');
            DB::statement('ALTER TABLE compliance_assessments ENABLE TRIGGER compliance_assessments_immutable');
            DB::statement('ALTER TABLE compliance_audit_status_changes ENABLE TRIGGER compliance_audit_status_changes_immutable');

            // The run's Kvalitet items; details, evidence and any remaining links cascade.
            $sweepOnly(QualityItem::query()->where('customer_id', $customerId)->where('title', '~', $pattern))->delete();

            // A source still holding a requirement the run did not create stays.
            ComplianceSource::query()->whereIn('id', $sourceIds)->whereDoesntHave('requirements')->delete();

            // Role permissions and user-role links cascade from the role.
            $sweepOnly(CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->delete();

            // People the run seeded carry the marker in both name and address; the shared E2E users never do.
            $sweepOnly(self::runUsers($customerId, $pattern))->delete();
        });
    }

    /**
     * A process, a control with its fields, and one piece of evidence on the control — written the
     * way Kvalitet writes them, named by the run's rule.
     *
     * @return array{process: QualityItem, control: QualityItem, labels: array{process_title: string, control_title: string, evidence_title: string}}
     */
    private static function qualityItems(int $customerId, \Closure $name, User $author): array
    {
        $process = QualityItem::query()->create([
            'customer_id' => $customerId,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $name('Tilgangsprosess'),
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
        $control = QualityItem::query()->create([
            'customer_id' => $customerId,
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => $name('Tilgangsgjennomgang'),
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
        QualityControlDetail::query()->create([
            'customer_id' => $customerId,
            'quality_item_id' => $control->id,
            'criterion' => 'Alle tilganger er godkjent av leder.',
            'method' => 'Stikkprøve av ti brukere i hvert fagsystem.',
            'frequency' => QualityControlDetail::FREQUENCY_QUARTERLY,
            'responsibility' => 'IT-sjef',
        ]);
        $evidence = QualityItemDocument::query()->create([
            'customer_id' => $customerId,
            'quality_item_id' => $control->id,
            'relation_type' => QualityItemDocument::RELATION_TYPE_EVIDENCE,
            'title' => $name('Gjennomgang mars'),
            'note' => 'Signert gjennomgangsrapport for første kvartal.',
            'source' => QualityItemDocument::SOURCE_MANUAL,
            'created_by_user_id' => $author->id,
        ]);

        return [
            'process' => $process,
            'control' => $control,
            'labels' => ['process_title' => $process->title, 'control_title' => $control->title, 'evidence_title' => $evidence->title],
        ];
    }

    /** A fresh, active person of the E2E customer, marked with the run's suffix. */
    private static function person(int $customerId, string $suffix, string $password, string $name, string $mailbox): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'e2e.krav.'.strtolower($suffix).'.'.$mailbox.'@procynia.test',
            'password' => bcrypt($password),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customerId,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissionKeys */
    private static function role(int $customerId, string $name, array $permissionKeys, User $holder): CustomerRole
    {
        $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name, 'is_active' => true]);
        $role->syncPermissions($permissionKeys);
        $holder->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

        return $role;
    }

    /** @return Builder<User> */
    private static function runUsers(int $customerId, string $pattern): Builder
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->where('name', '~', $pattern)
            ->where('email', 'like', 'e2e.krav.%@procynia.test');
    }

    private static function namer(string $suffix): \Closure
    {
        return fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;
    }

    private static function pattern(string $suffix): string
    {
        return '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).'( |$)';
    }

    private static function customerId(): int
    {
        return (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
    }
}
