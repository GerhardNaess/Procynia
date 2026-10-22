<?php

namespace Tests\Feature\App;

use App\Models\ComplianceFramework;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\Compliance\ComplianceQualityContextService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Services\Compliance\ComplianceRequirementService;
use App\Services\Compliance\ComplianceSourceService;
use App\Services\Compliance\Library\ComplianceApplicabilityService;
use App\Services\Compliance\Library\ComplianceFrameworkActivationService;
use App\Services\Compliance\Library\ComplianceFrameworkLibraryImporter;
use App\Services\EnterpriseWiki\Knowledge\Sources\ComplianceAuditKnowledgeSource;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AuthorsQualityFlows;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The ordinary register work in Etterlevelse og revisjon checks compliance.edit in the services
 * themselves — phase 0 step 13 (docs/external-api-phase-0-plan.md §2i).
 *
 * Registering and changing requirements and sources, Sett som utgått and Gjenåpne, Relevans,
 * «Aktiver for virksomheten» and the links to Kvalitet: called directly on the service, they are
 * refused for a reader, for another customer's editor and System Owner, and for a customer without
 * the module, and nothing is written. compliance.edit is not human-only, so an integration that
 * holds it may do this work; the decisions are another matter (ComplianceHumanDecisionTest).
 */
class ComplianceServiceAuthorizationTest extends TestCase
{
    use AuthorsQualityFlows;
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    private const WRITES = [
        'create_requirement', 'update_requirement', 'retire', 'reopen', 'set_applicability',
        'create_source', 'update_source', 'activate', 'link_process', 'unlink_process', 'link_control', 'unlink_control',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /** An editor — and an integration holding compliance.edit — does the register work. */
    public function test_an_editor_and_an_integration_with_compliance_edit_do_the_register_work(): void
    {
        $world = $this->world();
        $integration = $this->editorWithQuality($world['customer']);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        foreach (['editor' => $world['editor'], 'integration' => $integration->fresh()] as $who => $actor) {
            $created = app(ComplianceRequirementService::class)->create($actor, $this->fields($world, "Nytt krav ({$who})"));
            $this->assertSame((int) $actor->id, (int) $created->created_by, $who);
            app(ComplianceRequirementLifecycleService::class)->retire($created, $actor, 'Erstattet.');
            $this->assertSame(ComplianceRequirement::STATUS_RETIRED, $created->fresh()->status, $who);
        }
    }

    public function test_a_reader_is_refused_every_write(): void
    {
        $world = $this->world();
        $reader = $this->complianceReader($world['customer']);
        $this->complianceGrant($world['customer'], $reader, [CustomerPermissionCatalog::QUALITY_VIEW]);

        $this->assertWritesRefused($world, $reader, 'reader');
    }

    /** compliance.edit in one customer is nothing in another — their System Owner with every key included. */
    public function test_another_customers_editor_is_refused_every_write(): void
    {
        $world = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->complianceContext();
        $this->complianceGrant($other, $otherOwner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT, CustomerPermissionCatalog::QUALITY_VIEW]);

        // Registering a requirement, a source or a standard is always in the actor's own customer.
        $intoTheWorld = array_values(array_diff(self::WRITES, ['create_requirement', 'create_source', 'activate']));

        foreach (['editor' => $this->editorWithQuality($other), 'System Owner' => $otherOwner] as $who => $person) {
            $this->assertWritesRefused($world, $person, $who, $intoTheWorld);
        }

        // Nor can another customer's source be named: the requirement is not written.
        $this->expectException(ValidationException::class);
        app(ComplianceRequirementService::class)->create($this->editorWithQuality($other), $this->fields($world, 'Lånt kilde'));
    }

    public function test_a_customer_without_the_module_is_refused_every_write(): void
    {
        $world = $this->world();
        $world['customer']->packageEntitlements()->delete();
        $this->qualitySystemOwner((int) $world['customer']->id); // Kvalitet back, compliance still gone

        $this->assertWritesRefused($world, $world['editor']->fresh(), 'no module');
    }

    /**
     * On a library requirement the service changes only what the customer owns; a retired requirement
     * is reopened first — both read again under the lock.
     */
    public function test_the_service_keeps_the_library_text_and_refuses_a_retired_requirement(): void
    {
        $world = $this->world();
        $library = $world['library'];
        $title = $library->title;

        app(ComplianceRequirementService::class)->update($world['editor'], $library, ['title' => 'Overskrevet', 'requirement_text' => 'Overskrevet', 'review_interval_months' => 12]);
        $this->assertSame($title, $library->fresh()->title);
        $this->assertSame(12, (int) $library->fresh()->review_interval_months);

        $this->expectException(ValidationException::class);
        app(ComplianceRequirementService::class)->update($world['editor'], $world['retired'], $this->fields($world, 'Endret mens utgått'));
    }

    /**
     * «Overfør til Wiki» from an audit: compliance.audit in the audit's own customer, with the module.
     * Wiki checks its own half (wiki.knowledge.handoff) in WikiKnowledgeHandoffService.
     */
    public function test_handing_audit_knowledge_to_wiki_takes_compliance_audit_in_the_audits_customer(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        ['customer' => $other] = $this->complianceContext();
        $source = app(ComplianceAuditKnowledgeSource::class);

        $this->assertTrue($source->canHandOff($auditor, $audit));
        $this->assertFalse($source->canHandOff($this->complianceEditor($customer), $audit));
        $this->assertFalse($source->canHandOff($this->complianceAuditor($other), $audit));

        $customer->packageEntitlements()->delete();
        $this->assertFalse($source->canHandOff($auditor->fresh(), $audit));
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  list<string>  $writes
     */
    private function assertWritesRefused(array $world, User $actor, string $who, array $writes = self::WRITES): void
    {
        $before = $this->snapshot();
        $requirements = app(ComplianceRequirementService::class);
        $lifecycle = app(ComplianceRequirementLifecycleService::class);
        $sources = app(ComplianceSourceService::class);
        $quality = app(ComplianceQualityContextService::class);

        $attempts = [
            'create_requirement' => fn () => $requirements->create($actor, $this->fields($world, 'Uten rett')),
            'update_requirement' => fn () => $requirements->update($actor, $world['requirement'], $this->fields($world, 'Overskrevet')),
            'retire' => fn () => $lifecycle->retire($world['requirement'], $actor, 'Uten rett.'),
            'reopen' => fn () => $lifecycle->reopen($world['retired'], $actor, 'Uten rett.'),
            'set_applicability' => fn () => app(ComplianceApplicabilityService::class)->set($world['library'], $actor, ComplianceRequirement::APPLICABILITY_APPLIES, null),
            'create_source' => fn () => $sources->create($actor, ['name' => 'Ny kilde', 'version' => null, 'kind' => 'internal', 'description' => null]),
            'update_source' => fn () => $sources->update($world['source'], $actor, ['name' => 'Overskrevet', 'version' => null, 'kind' => 'internal', 'description' => null]),
            'activate' => fn () => app(ComplianceFrameworkActivationService::class)->activate($world['framework'], $actor),
            'link_process' => fn () => $quality->linkProcess($actor, $world['requirement'], (int) $world['process']->id),
            'unlink_process' => fn () => $quality->unlinkProcess($actor, $world['requirement'], (int) $world['linkedProcess']->id),
            'link_control' => fn () => $quality->linkControl($actor, $world['requirement'], (int) $world['control']->id),
            'unlink_control' => fn () => $quality->unlinkControl($actor, $world['requirement'], (int) $world['linkedControl']->id),
        ];

        foreach ($writes as $write) {
            try {
                $attempts[$write]();
                $this->fail("{$who}: {$write} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(AuthorizationException::class, $e, "{$who}: {$write} — ".$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns): array => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row): array => (array) $row)->all();

        return [
            'requirements' => $rows('compliance_requirements', ['id', 'title', 'status', 'applicability']),
            'sources' => $rows('compliance_sources', ['id', 'name']),
            'status_changes' => $rows('compliance_requirement_status_changes', ['id']),
            'processes' => $rows('compliance_requirement_processes', ['id']),
            'controls' => $rows('compliance_requirement_controls', ['id']),
        ];
    }

    /**
     * A customer with an editor who also reads Kvalitet; a requirement linked to one process and one
     * control, with another of each to link; a retired requirement; and ISO 9001 activated, with one
     * of its requirements, and a second standard not yet activated.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->editorWithQuality($customer);
        $source = $this->complianceSource($customer, 'Internt regelverk', null, 'internal');
        $requirement = $this->complianceRequirement($source, 'Tilgangsstyring', null, 'R.1');
        $retired = $this->complianceRequirement($source, 'Gammelt krav', null, 'R.2');
        app(ComplianceRequirementLifecycleService::class)->retire($retired, $editor, 'Erstattet.');

        $quality = fn (string $type, string $title) => QualityItem::query()->create(['customer_id' => $customer->id, 'quality_type' => $type, 'title' => $title, 'status' => QualityItem::STATUS_ACTIVE]);
        $linkedProcess = $quality(QualityItem::TYPE_PROCESS, 'Tilgangsprosess');
        $linkedControl = $quality(QualityItem::TYPE_CONTROL, 'Tilgangskontroll');
        $quality = ['process' => $quality(QualityItem::TYPE_PROCESS, 'Innkjøp'), 'control' => $quality(QualityItem::TYPE_CONTROL, 'Fire øyne')];
        app(ComplianceQualityContextService::class)->linkProcess($editor, $requirement, (int) $linkedProcess->id);
        app(ComplianceQualityContextService::class)->linkControl($editor, $requirement, (int) $linkedControl->id);

        $importer = app(ComplianceFrameworkLibraryImporter::class);
        $importer->import(ComplianceFrameworkLibraryImporter::definitionFromFile(database_path('data/compliance_frameworks/iso9001_2015.php')));
        $activated = ComplianceFramework::query()->where('key', 'iso9001:2015')->sole();
        $librarySource = app(ComplianceFrameworkActivationService::class)->activate($activated, $editor);
        $library = ComplianceRequirement::query()->where('source_id', $librarySource->id)->orderBy('id')->firstOrFail();
        $importer->import(ComplianceFrameworkLibraryImporter::definitionFromFile(database_path('data/compliance_frameworks/iso14001_2026.php')));
        $framework = ComplianceFramework::query()->where('key', '!=', 'iso9001:2015')->orderBy('id')->firstOrFail();

        return [
            'customer' => $customer,
            'editor' => $editor,
            'source' => $source,
            'requirement' => $requirement->fresh(),
            'retired' => $retired->fresh(),
            'library' => $library,
            'framework' => $framework,
            'linkedProcess' => $linkedProcess,
            'linkedControl' => $linkedControl,
            ...$quality,
        ];
    }

    private function editorWithQuality(Customer $customer): User
    {
        $user = $this->complianceEditor($customer);
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::QUALITY_VIEW]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $world
     * @return array<string, mixed>
     */
    private function fields(array $world, string $title): array
    {
        return [
            'source_id' => (int) $world['source']->id,
            'reference' => null,
            'title' => $title,
            'requirement_text' => 'Kravtekst.',
            'owner_user_id' => null,
            'review_interval_months' => null,
        ];
    }
}
