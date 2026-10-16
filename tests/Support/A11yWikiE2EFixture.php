<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The Wiki that tests/e2e/a11y-wiki.spec.js (WCAG Fase 5) works in — a customer of its own, so nothing
 * the spec does (submitting a page for review, deciding claims, saving an edit) touches real customer
 * data, and nothing in a development database can change what it asserts.
 *
 *   - «E2E Wiki-eier» (System Owner, every Wiki permission) owns the article and submits it;
 *   - «E2E Kontrollør» (Bidragsyter with every Wiki permission) is the one eligible reviewer;
 *   - «Leverandørsikkerhet», a draft article whose Markdown starts with `# Leverandørsikkerhet` (as
 *     ingested articles do), two `##` sections, two best-practice suggestions waiting for a decision,
 *     and a link to «Kontinuitetsplan»;
 *   - «Kontinuitetsplan» and «Tilgangsstyring», published, linked to and from the article, so the graph
 *     and its list have relations to show.
 *
 * No AI call is involved anywhere: claim decisions, the review hand-over and a refused edit are plain
 * database work. Test-only (autoload-dev), invoked through tinker; seed() starts from a clean slate.
 */
class A11yWikiE2EFixture
{
    public const CUSTOMER_SLUG = 'e2e-a11y-wiki';

    public const OWNER_EMAIL = 'e2e.a11ywiki.owner@procynia.test';

    public const REVIEWER_EMAIL = 'e2e.a11ywiki.reviewer@procynia.test';

    public const PASSWORD = 'e2e-a11y-wiki-password';

    public const ARTICLE_SLUG = 'e2e-a11y-leverandorsikkerhet';

    public const RELATED_SLUG = 'e2e-a11y-kontinuitetsplan';

    public const OTHER_SLUG = 'e2e-a11y-tilgangsstyring';

    public static function seed(): int
    {
        self::cleanup();

        $customerId = DB::transaction(function (): int {
            $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
            $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

            $customer = Customer::query()->create([
                'name' => 'E2E Wiki universell utforming',
                'slug' => self::CUSTOMER_SLUG,
                'language_id' => $language->id,
                'nationality_id' => $nationality->id,
                'is_active' => true,
                'permission_settings' => Customer::DEFAULT_PERMISSION_SETTINGS,
            ]);

            app(ModuleEntitlementService::class)->grantDefaultPackage($customer);

            $wikiRole = CustomerRole::query()->create([
                'customer_id' => $customer->id,
                'name' => 'E2E Wiki',
                'is_active' => true,
            ]);
            $wikiRole->syncPermissions([
                CustomerPermissionCatalog::WIKI_VIEW,
                CustomerPermissionCatalog::WIKI_EDIT,
                CustomerPermissionCatalog::WIKI_REVIEW,
                CustomerPermissionCatalog::WIKI_APPROVE,
                CustomerPermissionCatalog::WIKI_DELETE,
                CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
            ]);

            $owner = self::user($customer, 'E2E Wiki-eier', self::OWNER_EMAIL, User::BID_ROLE_SYSTEM_OWNER);
            $reviewer = self::user($customer, 'E2E Kontrollør', self::REVIEWER_EMAIL, User::BID_ROLE_CONTRIBUTOR);
            // Offered as reviewer only with the customer's «Godkjenne Wiki-sider» capability as well.
            $reviewer->forceFill(['is_wiki_approver' => true])->save();

            foreach ([$owner, $reviewer] as $user) {
                $user->customerRoles()->attach($wikiRole->id, ['customer_id' => $customer->id]);
            }

            $document = EnterpriseWikiDocument::query()->create([
                'customer_id' => $customer->id,
                'owner_user_id' => $owner->id,
                'original_filename' => 'Leverandørrutiner (E2E a11y).docx',
                'file_path' => 'e2e-a11y-wiki/leverandorrutiner.docx',
                'file_hash_sha256' => hash('sha256', 'e2e-a11y-wiki-document'),
                'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
                'extracted_text' => 'Vi vurderer informasjonssikkerheten hos nye leverandører. Leverandøren følges opp årlig.',
            ]);

            $run = EnterpriseWikiIngestRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
                'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_hash' => hash('sha256', 'e2e-a11y-wiki-run'),
                'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            ]);

            $article = self::article($customer, $owner, $document);
            $related = self::simplePage($customer, $owner, self::RELATED_SLUG, 'Kontinuitetsplan', EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                "# Kontinuitetsplan\n\nKontinuitetsplanen beskriver hvordan leveransen opprettholdes ved avbrudd.");
            $other = self::simplePage($customer, $owner, self::OTHER_SLUG, 'Tilgangsstyring', EnterpriseWikiPage::PAGE_TYPE_ENTITY,
                "# Tilgangsstyring\n\nTilgang gis etter behov og fjernes når den ikke lenger trengs.");

            foreach ([$article, $related, $other] as $page) {
                EnterpriseWikiIngestRunPage::query()->create([
                    'enterprise_wiki_ingest_run_id' => $run->id,
                    'enterprise_wiki_page_id' => $page->id,
                    'action' => 'created',
                ]);
            }

            foreach ([[$article, $related], [$other, $article]] as [$from, $to]) {
                EnterpriseWikiPageLink::query()->create([
                    'customer_id' => $customer->id,
                    'from_page_id' => $from->id,
                    'to_page_id' => $to->id,
                    'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
                    'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
                ]);
            }

            return (int) $customer->id;
        });

        // The focus view reads the graph store, as in WikiGraphFocusE2EFixture. Where the projection is
        // off, the spec checks the «not available» answer instead.
        try {
            $projector = app(EnterpriseWikiGraphProjector::class);

            foreach (EnterpriseWikiPage::query()->where('customer_id', $customerId)->pluck('id') as $pageId) {
                $projector->projectPage((int) $pageId);
            }
        } catch (\Throwable) {
            // Projection unavailable in this environment.
        }

        return $customerId;
    }

    private static function article(Customer $customer, User $owner, EnterpriseWikiDocument $document): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => self::ARTICLE_SLUG,
            'title' => 'Leverandørsikkerhet',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        $intro = "# Leverandørsikkerhet\n\nDenne artikkelen beskriver hvordan vi vurderer informasjonssikkerheten hos nye leverandører.";
        $purpose = "## Formål\n\nVi vurderer informasjonssikkerheten hos nye leverandører. Se også [Kontinuitetsplan](/app/wiki/".self::RELATED_SLUG.').';
        $suggestionOne = 'Det anbefales å kreve dokumentert tilgangsstyring hos leverandøren.';
        $followUp = "## Oppfølging\n\nLeverandøren følges opp årlig.";
        $suggestionTwo = 'Det anbefales å gjennomgå leverandørens hendelseslogg hvert kvartal.';

        $blocks = [
            ['block_key' => 'block-0001', 'markdown' => $intro, 'content_origin' => 'human_authored'],
            ['block_key' => 'block-0002', 'markdown' => $purpose, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, 'source_id' => $document->id, 'source_label' => $document->original_filename, 'page_reference' => 'Avsnitt 1'],
            ['block_key' => 'block-0003', 'markdown' => $suggestionOne, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, 'best_practice_reason' => 'Vanlig krav i leverandøravtaler.'],
            ['block_key' => 'block-0004', 'markdown' => $followUp, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, 'source_id' => $document->id, 'source_label' => $document->original_filename, 'page_reference' => 'Avsnitt 2'],
            ['block_key' => 'block-0005', 'markdown' => $suggestionTwo, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, 'best_practice_reason' => 'Vanlig oppfølgingspraksis.'],
        ];

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", [$intro, $purpose, $suggestionOne, $followUp, $suggestionTwo]),
            'content_blocks_json' => array_map(
                fn (array $block, int $position): array => $block + ['position' => $position],
                $blocks,
                array_keys($blocks),
            ),
            'generated_by_model' => 'e2e-a11y-wiki-fixture',
        ]);

        $sourceClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Vi vurderer informasjonssikkerheten hos nye leverandører.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'page_excerpt' => 'Vi vurderer informasjonssikkerheten hos nye leverandører.',
            'content_block_key' => 'block-0002',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'verified_at' => now(),
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $sourceClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_element_key' => 'paragraph-0',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_label' => $document->original_filename,
            'excerpt' => 'Vi vurderer informasjonssikkerheten hos nye leverandører.',
            'source_hash' => $document->file_hash_sha256,
            'page_reference' => 'Avsnitt 1',
        ]);

        foreach ([['block-0003', $suggestionOne, 1], ['block-0005', $suggestionTwo, 2]] as [$blockKey, $text, $position]) {
            EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'claim_text' => $text,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'page_excerpt' => $text,
                'content_block_key' => $blockKey,
                'position_order' => $position,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => now(),
                'review_metadata' => [
                    'statement_kind' => 'recommendation',
                    'classification_basis' => 'ai_block_content_origin',
                    'suggested_placement' => $blockKey,
                    'visible_wiki_link_recommendation' => 'not_needed',
                ],
            ]);
        }

        return $page;
    }

    private static function simplePage(Customer $customer, User $owner, string $slug, string $title, string $type, string $markdown): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $type,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'e2e-a11y-wiki-fixture',
        ]);

        return $page;
    }

    public static function cleanup(): void
    {
        $customerId = Customer::query()->where('slug', self::CUSTOMER_SLUG)->value('id');

        if ($customerId === null) {
            return;
        }

        try {
            $projector = app(EnterpriseWikiGraphProjector::class);

            foreach (EnterpriseWikiPage::query()->where('customer_id', $customerId)->pluck('id') as $pageId) {
                $projector->deletePage((int) $customerId, (int) $pageId);
            }
        } catch (\Throwable) {
            // Projection unavailable in this environment.
        }

        DB::transaction(function () use ($customerId): void {
            EnterpriseWikiIngestRun::query()->where('customer_id', $customerId)->delete();
            EnterpriseWikiPage::query()->where('customer_id', $customerId)->delete();
            EnterpriseWikiDocument::query()->where('customer_id', $customerId)->delete();
            DB::table('customer_user_roles')->where('customer_id', $customerId)->delete();
            CustomerRole::query()->where('customer_id', $customerId)->delete();
            User::query()->where('customer_id', $customerId)->delete();
            CustomerPackageEntitlement::query()->where('customer_id', $customerId)->delete();
            Customer::query()->whereKey($customerId)->delete();
        });
    }

    private static function user(Customer $customer, string $name, string $email, string $bidRole): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
