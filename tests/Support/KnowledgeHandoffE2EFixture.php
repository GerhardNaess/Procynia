<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data for tests/e2e/knowledge-handoff.spec.js — «Lag kunnskapsartikkel» from an avvik into the Wiki.
 *
 * NO PROVIDER CALL. A handoff starts the ordinary Wiki run, and the local queue workers would run
 * it against the real AI provider. seed() therefore suspends AI for the E2E customer for the length
 * of the spec: the run's first AI call is refused by cost control before anything is sent, while the
 * Wiki source and its provenance — what the spec checks — are created exactly as in production.
 * cleanup() restores the previous AI status (remembered outside the spec, so an interrupted run is
 * restored by the next sweep) and removes the source through the ordinary cancel and delete paths.
 */
final class KnowledgeHandoffE2EFixture
{
    private const PREFIX = 'E2E Kunnskap';

    private const USER_EMAIL = 'e2e.user@procynia.test';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const AI_STATUS_CACHE_KEY = 'e2e:knowledge-handoff:previous-ai-access-status';

    /** @return array{case_id: int, case_title: string} */
    public static function seed(string $suffix): array
    {
        $customer = self::customer();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;

        if (! Cache::has(self::AI_STATUS_CACHE_KEY)) {
            Cache::forever(self::AI_STATUS_CACHE_KEY, $customer->ai_access_status ?? Customer::AI_ACCESS_ENABLED);
        }
        $customer->forceFill(['ai_access_status' => Customer::AI_ACCESS_SUSPENDED])->save();

        return DB::transaction(function () use ($customer, $user, $name): array {
            $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name('Lønn')]);

            $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => $name('Kunnskapsdeler'), 'is_active' => true]);
            $role->syncPermissions([
                CustomerPermissionCatalog::IMPROVEMENT_VIEW,
                CustomerPermissionCatalog::IMPROVEMENT_EDIT,
                CustomerPermissionCatalog::WIKI_VIEW,
                CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
            ]);
            $role->syncBusinessAreas(false, [$area->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

            $case = ImprovementCase::query()->create([
                'customer_id' => $customer->id,
                'business_area_id' => $area->id,
                'type' => ImprovementCase::TYPE_DEVIATION,
                'title' => $name('Feil lønnsutbetaling'),
                'description' => 'Dobbel utbetaling til ti ansatte i mars.',
                'cause_analysis' => 'Lønnsfilen ble importert manuelt uten avstemming mot forrige kjøring.',
            ]);

            return ['case_id' => (int) $case->id, 'case_title' => (string) $case->title];
        });
    }

    /**
     * What the case handed over: its origin rows, each document's name and text, and the run.
     *
     * @return array{origins: int, documents: list<array{filename: string, text: string, uploaded_by: string|null}>, runs: int}
     */
    public static function handedOver(int $caseId): array
    {
        $documentIds = EnterpriseWikiDocumentOrigin::query()
            ->where('source_type', 'improvement_case')
            ->where('source_id', $caseId)
            ->pluck('enterprise_wiki_document_id');

        return [
            'origins' => $documentIds->count(),
            'documents' => EnterpriseWikiDocument::query()->whereIn('id', $documentIds)->with('uploadedBy:id,email')->get()
                ->map(fn (EnterpriseWikiDocument $document): array => [
                    'filename' => (string) $document->original_filename,
                    'text' => (string) $document->extracted_text,
                    'uploaded_by' => $document->uploadedBy?->email,
                ])->all(),
            'runs' => EnterpriseWikiIngestRun::query()
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->whereIn('source_id', $documentIds)
                ->count(),
        ];
    }

    /** Remove what a run created (all runs when no suffix) and give the customer its AI back. */
    public static function cleanup(?string $suffix = null): void
    {
        $customer = self::customer();
        $owner = User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->firstOrFail();
        $pattern = '^'.preg_quote(self::PREFIX).' '.($suffix === null ? '[A-Z0-9]{6}' : preg_quote(strtoupper($suffix))).'( |$)';

        $caseIds = ImprovementCase::query()->where('customer_id', $customer->id)->where('title', '~', $pattern)->pluck('id');
        $documentIds = EnterpriseWikiDocumentOrigin::query()
            ->where('customer_id', $customer->id)
            ->where('source_type', 'improvement_case')
            ->whereIn('source_id', $caseIds)
            ->pluck('enterprise_wiki_document_id')
            ->unique();

        foreach (EnterpriseWikiDocument::query()->whereIn('id', $documentIds)->get() as $document) {
            EnterpriseWikiIngestRun::query()
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->nonTerminal()
                ->get()
                ->each(fn (EnterpriseWikiIngestRun $run) => app(EnterpriseWikiDocumentFlowService::class)->cancelRun($run, $owner, 'E2E cleanup'));

            app(EnterpriseWikiDocumentDeletionService::class)->delete($document->fresh(), $owner);
        }

        DB::transaction(function () use ($customer, $pattern, $caseIds): void {
            ImprovementCase::query()->whereIn('id', $caseIds)->get()->each(fn (ImprovementCase $case) => $case->delete());
            CustomerRole::query()->where('customer_id', $customer->id)->where('name', '~', $pattern)->delete();
            BusinessArea::query()->where('customer_id', $customer->id)->where('name', '~', $pattern)->get()
                ->reject(fn (BusinessArea $area): bool => $area->isInUse())
                ->each(fn (BusinessArea $area) => $area->delete());
        });

        if (Cache::has(self::AI_STATUS_CACHE_KEY)) {
            $customer->forceFill(['ai_access_status' => Cache::pull(self::AI_STATUS_CACHE_KEY)])->save();
        }
    }

    /** @return array{cases: int, roles: int, areas: int, origins: int, ai_suspended_by_fixture: bool} */
    public static function remaining(string $suffix): array
    {
        $customer = self::customer();
        $pattern = '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).'( |$)';
        $caseIds = ImprovementCase::query()->where('customer_id', $customer->id)->where('title', '~', $pattern)->pluck('id');

        return [
            'cases' => $caseIds->count(),
            'roles' => CustomerRole::query()->where('customer_id', $customer->id)->where('name', '~', $pattern)->count(),
            'areas' => BusinessArea::query()->where('customer_id', $customer->id)->where('name', '~', $pattern)->count(),
            'origins' => EnterpriseWikiDocumentOrigin::query()->where('source_type', 'improvement_case')->whereIn('source_id', $caseIds)->count(),
            'ai_suspended_by_fixture' => Cache::has(self::AI_STATUS_CACHE_KEY),
        ];
    }

    private static function customer(): Customer
    {
        return Customer::query()->findOrFail(User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id'));
    }
}
