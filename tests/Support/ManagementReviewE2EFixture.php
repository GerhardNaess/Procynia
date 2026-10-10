<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewEvent;
use App\Models\ManagementReviewSection;
use App\Models\ManagementReviewSnapshotSection;
use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\ManagementReview\ManagementReviewDecisionService;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewSectionCatalog;
use App\Services\ManagementReview\ManagementReviewService;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog as P;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only setup and cleanup for the Ledelsens gjennomgåelse E2E spec
 * (tests/e2e/management-review.spec.js). Not autoloaded in production (autoload-dev only) — invoked
 * via `php artisan tinker --execute=...`.
 *
 * A CUSTOMER OF ITS OWN, «E2E LG <SUFFIX> Kunde», holding GRC (Basis and every option), copied from
 * the E2E customer's language and nationality — the arrangement SupplierE2EFixture uses. Its people,
 * roles, fagområde, risk and case all live inside it, so nothing the run does can touch the shared
 * E2E data. Cleanup removes the risk first (it holds its fagområde with RESTRICT) and then the
 * customer, which the history and lock triggers allow.
 */
class ManagementReviewE2EFixture
{
    public const PREFIX = 'E2E LG';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    private const SWEEP_MIN_AGE_MINUTES = 10;

    /**
     * The run's customer with a quality manager who runs the review (every review permission, and
     * read access to the modules in one fagområde), a colleague who reads reviews and can own a
     * tiltak, a high risk and an open deviation in that fagområde, and a control without evidence.
     *
     * @return array{email: string, name: string, colleague_email: string, colleague_name: string, area: string}
     */
    public static function seedJourney(string $suffix, string $password): array
    {
        $template = Customer::query()->findOrFail((int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id'));
        $name = fn (string $what): string => self::PREFIX.' '.strtoupper($suffix).' '.$what;

        return DB::transaction(function () use ($template, $suffix, $password, $name): array {
            $customer = Customer::query()->create([
                'name' => $name('Kunde'),
                'slug' => 'e2e-lg-'.strtolower($suffix),
                'language_id' => $template->language_id,
                'nationality_id' => $template->nationality_id,
                'is_active' => true,
            ]);
            app(ModuleEntitlementService::class)->activatePackage($customer, 'grc');
            $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift '.strtoupper($suffix)]);

            $manager = self::person($customer, $suffix, $password, $name('Kvalitetsleder'), 'leder');
            self::role($customer, $name('Ledelse'), [
                P::MANAGEMENT_REVIEW_VIEW, P::MANAGEMENT_REVIEW_EDIT, P::MANAGEMENT_REVIEW_FINALIZE, P::MANAGEMENT_REVIEW_DELETE,
                P::QUALITY_VIEW, P::RISK_VIEW, P::OBJECTIVE_VIEW, P::IMPROVEMENT_VIEW, P::IMPROVEMENT_EDIT,
                P::COMPLIANCE_VIEW, P::SUPPLIER_VIEW,
            ], $manager, $area);

            $colleague = self::person($customer, $suffix, $password, $name('Kollega'), 'kollega');
            self::role($customer, $name('Leser'), [P::MANAGEMENT_REVIEW_VIEW], $colleague);

            $risk = Risk::query()->create([
                'customer_id' => $customer->id,
                'business_area_id' => $area->id,
                'title' => 'Datatap '.strtoupper($suffix),
                'owner_user_id' => $manager->id,
                'status' => Risk::STATUS_IDENTIFIED,
            ]);
            RiskAssessment::query()->create([
                'customer_id' => $customer->id, 'risk_id' => $risk->id, 'assessed_by' => $manager->id, 'assessed_at' => now()->subDays(10),
                'rationale' => 'Vurdert', 'criteria_key' => 'standard_5x5_v1',
                'inherent_likelihood' => 5, 'inherent_consequence' => 5, 'residual_likelihood' => 4, 'residual_consequence' => 4,
            ]);

            self::deviation($customer, $area, 'Avvik '.strtoupper($suffix));
            // A control in force with no evidence: «Kvalitetsarbeid» needs attention.
            QualityItem::query()->create([
                'customer_id' => $customer->id,
                'quality_type' => QualityItem::TYPE_CONTROL,
                'title' => 'Tilgangskontroll '.strtoupper($suffix),
                'status' => QualityItem::STATUS_ACTIVE,
            ]);

            return [
                'email' => $manager->email,
                'name' => $manager->name,
                'colleague_email' => $colleague->email,
                'colleague_name' => $colleague->name,
                'area' => $area->name,
            ];
        });
    }

    /**
     * The journey's customer with a finalized review, and the quality manager allowed to hand it to
     * the Wiki. NO PROVIDER CALL: AI is suspended for this customer of the run's own, so the Wiki run
     * the handoff starts is stopped by cost control before anything is sent (the arrangement
     * KnowledgeHandoffE2EFixture uses); the customer goes away with cleanup().
     *
     * @return array{email: string, review_id: int, conclusion: string, participant: string, risk: string}
     */
    public static function seedFinalizedForWiki(string $suffix, string $password): array
    {
        $people = self::seedJourney($suffix, $password);
        $manager = User::query()->where('email', $people['email'])->sole();
        $customer = $manager->customer;
        $customer->forceFill(['ai_access_status' => Customer::AI_ACCESS_SUSPENDED])->save();
        self::role($customer, self::PREFIX.' '.strtoupper($suffix).' Wiki', [P::WIKI_VIEW, P::WIKI_SOURCE_MANAGE], $manager);

        $conclusion = 'Styringssystemet er egnet, men avvik må lukkes raskere.';
        $participant = 'Deltaker '.strtoupper($suffix);
        auth()->setUser($manager);

        $review = app(ManagementReviewService::class)->create($manager, [
            'title' => 'LG Wiki '.strtoupper($suffix), 'purpose' => null,
            'period_start' => now()->subMonths(6)->toDateString(), 'period_end' => now()->subDay()->toDateString(),
            'meeting_date' => now()->toDateString(), 'all_business_areas' => true, 'business_area_ids' => [],
            'frameworks' => [], 'owner_user_id' => $manager->id,
        ]);
        $review->participants()->create(['customer_id' => $customer->id, 'name' => $participant, 'position' => 1]);
        $review->forceFill(['conclusion' => $conclusion])->save();

        foreach (app(ManagementReviewSectionCatalog::class)->keysFor($review) as $key) {
            ManagementReviewSection::query()->create([
                'customer_id' => $customer->id, 'management_review_id' => $review->id, 'section_key' => $key,
                'judgement' => ManagementReview::JUDGEMENT_SATISFACTORY,
                'comment' => $key === 'risks' ? 'Risikobildet følges opp kvartalsvis.' : null,
            ]);
        }

        app(ManagementReviewDecisionService::class)->create($manager, $review, [
            'kind' => ManagementReviewDecision::KIND_DECISION, 'text' => 'Avvik skal lukkes innen 30 dager.', 'section_key' => 'improvements',
            'owner_user_id' => null, 'due_date' => null,
        ]);
        app(ManagementReviewFinalizationService::class)->finalize($manager, $review->fresh());

        return [
            'email' => $people['email'],
            'review_id' => (int) $review->id,
            'conclusion' => $conclusion,
            'participant' => $participant,
            'risk' => 'Datatap '.strtoupper($suffix),
        ];
    }

    /**
     * What the review handed over: its origin rows and each Wiki source's text.
     *
     * @return array{origins: int, texts: list<string>}
     */
    public static function handedOver(int $reviewId): array
    {
        $documentIds = EnterpriseWikiDocumentOrigin::query()
            ->where('source_type', 'management_review')
            ->where('source_id', $reviewId)
            ->pluck('enterprise_wiki_document_id');

        return [
            'origins' => $documentIds->count(),
            'texts' => EnterpriseWikiDocument::query()->whereIn('id', $documentIds)->pluck('extracted_text')->map(fn ($text): string => (string) $text)->all(),
        ];
    }

    /** A new deviation after the review is finalized — the module changes, the snapshot must not. */
    public static function addDeviation(string $suffix, string $title): array
    {
        $customer = Customer::query()->where('name', '~', self::pattern($suffix))->sole();
        $area = BusinessArea::query()->where('customer_id', $customer->id)->firstOrFail();
        $case = self::deviation($customer, $area, $title.' '.strtoupper($suffix));

        return ['id' => (int) $case->id];
    }

    /** @return array<string, int> */
    public static function remaining(string $suffix): array
    {
        $customerIds = Customer::query()->where('name', '~', self::pattern($suffix))->pluck('id');

        return [
            'customers' => $customerIds->count(),
            'reviews' => ManagementReview::query()->whereIn('customer_id', $customerIds)->count(),
            'decisions' => ManagementReviewDecision::query()->whereIn('customer_id', $customerIds)->count(),
            'snapshots' => ManagementReviewSnapshotSection::query()->whereIn('customer_id', $customerIds)->count(),
            'events' => ManagementReviewEvent::query()->whereIn('customer_id', $customerIds)->count(),
            'improvement_cases' => ImprovementCase::query()->where(fn (Builder $query) => $query
                ->whereIn('customer_id', $customerIds)
                ->orWhere('title', 'like', '%'.strtoupper($suffix).'%'))->count(),
            'risks' => Risk::query()->where(fn (Builder $query) => $query
                ->whereIn('customer_id', $customerIds)
                ->orWhere('title', 'like', '%'.strtoupper($suffix).'%'))->count(),
            'business_areas' => BusinessArea::query()->whereIn('customer_id', $customerIds)->count(),
            'roles' => CustomerRole::query()->whereIn('customer_id', $customerIds)->count(),
            'users' => User::query()->where('email', 'like', 'e2e.lg.'.strtolower($suffix).'.%')->count(),
            'wiki_documents' => EnterpriseWikiDocument::query()->whereIn('customer_id', $customerIds)->count(),
        ];
    }

    /** Removes the run's customer and everything in it; without a suffix, sweeps old leftovers. */
    public static function cleanup(?string $suffix = null): void
    {
        $pattern = $suffix === null ? '^'.preg_quote(self::PREFIX).' '.self::SUFFIX_PATTERN.' Kunde$' : self::pattern($suffix);
        $customers = Customer::query()->where('name', '~', $pattern)
            ->when($suffix === null, fn (Builder $query) => $query->where('created_at', '<', now()->subMinutes(self::SWEEP_MIN_AGE_MINUTES)))
            ->get();

        foreach ($customers as $customer) {
            self::removeWikiSources($customer);

            DB::transaction(function () use ($customer): void {
                CustomerRole::query()->where('customer_id', $customer->id)->delete();
                // Deleting the people nulls their ids out of the locked rows and the history, which
                // the triggers allow; names kept as text stay.
                User::query()->where('customer_id', $customer->id)->delete();
                CustomerPackageEntitlement::query()->where('customer_id', $customer->id)->delete();
                // Before the fagområde they hold with RESTRICT.
                Risk::query()->where('customer_id', $customer->id)->delete();
                // Reviews, their locked rows and history, the cases and the fagområde go with the customer.
                $customer->delete();
            });
        }
    }

    /**
     * A source handed to the Wiki leaves through the ordinary paths — its run cancelled, the document
     * deleted with its stored file — before the customer goes.
     */
    private static function removeWikiSources(Customer $customer): void
    {
        $actor = User::query()->where('customer_id', $customer->id)->orderBy('id')->first();

        if ($actor === null) {
            return;
        }

        foreach (EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->get() as $document) {
            EnterpriseWikiIngestRun::query()
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->nonTerminal()
                ->get()
                ->each(fn (EnterpriseWikiIngestRun $run) => app(EnterpriseWikiDocumentFlowService::class)->cancelRun($run, $actor, 'E2E cleanup'));

            app(EnterpriseWikiDocumentDeletionService::class)->delete($document->fresh(), $actor);
        }
    }

    private static function deviation(Customer $customer, BusinessArea $area, string $title): ImprovementCase
    {
        return ImprovementCase::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'type' => ImprovementCase::TYPE_DEVIATION,
            'title' => $title,
            'description' => 'Registrert av E2E-oppsettet.',
        ]);
    }

    private static function person(Customer $customer, string $suffix, string $password, string $name, string $mailbox): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'e2e.lg.'.strtolower($suffix).'.'.$mailbox.'@procynia.test',
            'password' => bcrypt($password),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissionKeys */
    private static function role(Customer $customer, string $name, array $permissionKeys, User $holder, ?BusinessArea $area = null): CustomerRole
    {
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => $name, 'is_active' => true]);
        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, $area !== null ? [(int) $area->id] : []);
        $holder->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    private static function pattern(string $suffix): string
    {
        return '^'.preg_quote(self::PREFIX).' '.strtoupper($suffix).' Kunde$';
    }
}
