<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The Wiki graph that tests/e2e/wiki-graph-filters.spec.js filters, owned by the spec.
 *
 * A customer of its own, so every count the spec asserts ("9 av 16 sider") is the count of what is
 * seeded here and of nothing else: two source documents with one owner each, sixteen pages, and a
 * few wikilinks. The titles are chosen for the searches the spec makes —
 *
 *  - "masterdata itil" matches the ITIL article and its summary (2);
 *  - "Styrings- og samhandlingsmodell" matches one page exactly (1);
 *  - "ITIL" matches four ITIL pages (more than one, fewer than all);
 *  - "styring" matches two Samhandling pages (fewer than the document's seven);
 *  - "kontinuerlig" matches one linked and one isolated page, so hiding isolated pages still
 *    leaves one.
 *
 * Each document has exactly one article page, and every page belongs to exactly one document, so
 * the document and owner filters split the graph 9 / 7. No lint findings: every page is "OK".
 *
 * Test-only (autoload-dev), invoked through tinker from the spec. seed() starts from a clean slate
 * and returns the customer id; cleanup() removes the customer and everything seeded under it.
 */
class WikiGraphFilterE2EFixture
{
    public const CUSTOMER_SLUG = 'e2e-wiki-graph';

    public const VIEWER_EMAIL = 'e2e.wikigraph@procynia.test';

    public const VIEWER_PASSWORD = 'e2e-wiki-graph-password';

    /** @var array<string, array{owner: string, pages: list<array{0: string, 1: string}>}> */
    private const DOCUMENTS = [
        'Masterdata ITIL.docx' => [
            'owner' => 'Ola Nordmann',
            'pages' => [
                ['Masterdata ITIL', EnterpriseWikiPage::PAGE_TYPE_ARTICLE],
                ['Sammendrag: Masterdata ITIL', EnterpriseWikiPage::PAGE_TYPE_SUMMARY],
                ['ITIL-prosessmodell', EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
                ['Hendelseshåndtering i ITIL', EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
                ['Endringshåndtering', EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
                ['Kontinuerlig forbedring', EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
                ['Tjenestekatalog', EnterpriseWikiPage::PAGE_TYPE_ENTITY],
                ['Servicedesk', EnterpriseWikiPage::PAGE_TYPE_ENTITY],
                ['Konfigurasjonsdatabase', EnterpriseWikiPage::PAGE_TYPE_ENTITY],
            ],
        ],
        'Masterdata Samhandling.docx' => [
            'owner' => 'Kari Nordmann',
            'pages' => [
                ['Masterdata Samhandling', EnterpriseWikiPage::PAGE_TYPE_ARTICLE],
                ['Sammendrag: Masterdata Samhandling', EnterpriseWikiPage::PAGE_TYPE_SUMMARY],
                ['Styrings- og samhandlingsmodell', EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
                ['Styringsgruppe', EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
                ['Kontinuerlig leveranseoppfølging', EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
                ['Kunde', EnterpriseWikiPage::PAGE_TYPE_ENTITY],
                ['Leverandør', EnterpriseWikiPage::PAGE_TYPE_ENTITY],
            ],
        ],
    ];

    /** Wikilinks by title. «Kontinuerlig leveranseoppfølging» is deliberately left isolated. */
    private const LINKS = [
        ['Sammendrag: Masterdata ITIL', 'Masterdata ITIL'],
        ['Masterdata ITIL', 'ITIL-prosessmodell'],
        ['Masterdata ITIL', 'Kontinuerlig forbedring'],
        ['ITIL-prosessmodell', 'Hendelseshåndtering i ITIL'],
        ['Sammendrag: Masterdata Samhandling', 'Masterdata Samhandling'],
        ['Masterdata Samhandling', 'Styrings- og samhandlingsmodell'],
        ['Styrings- og samhandlingsmodell', 'Styringsgruppe'],
    ];

    public static function seed(): int
    {
        self::cleanup();

        return DB::transaction(function (): int {
            $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
            $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

            $customer = Customer::query()->create([
                'name' => 'E2E Wiki-graf',
                'slug' => self::CUSTOMER_SLUG,
                'language_id' => $language->id,
                'nationality_id' => $nationality->id,
                'is_active' => true,
                'permission_settings' => Customer::DEFAULT_PERMISSION_SETTINGS,
            ]);

            app(ModuleEntitlementService::class)->grantDefaultPackage($customer);

            self::user($customer, 'E2E Grafleser', self::VIEWER_EMAIL, User::BID_ROLE_SYSTEM_OWNER);

            $pageIdsByTitle = [];

            foreach (self::DOCUMENTS as $filename => $definition) {
                $owner = self::user($customer, $definition['owner'], 'e2e.wikigraph.'.Str::slug($definition['owner']).'@procynia.test', User::BID_ROLE_CONTRIBUTOR);

                $document = EnterpriseWikiDocument::query()->create([
                    'customer_id' => $customer->id,
                    'owner_user_id' => $owner->id,
                    'original_filename' => $filename,
                    'file_path' => 'e2e-wiki-graph/'.Str::slug($filename).'.docx',
                    'file_hash_sha256' => hash('sha256', 'e2e-wiki-graph-'.$filename),
                    'document_status' => 'processed',
                ]);

                $run = EnterpriseWikiIngestRun::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'customer_id' => $customer->id,
                    'trigger_type' => 'manual',
                    'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                    'source_id' => $document->id,
                    'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
                ]);

                foreach ($definition['pages'] as [$title, $pageType]) {
                    $page = EnterpriseWikiPage::query()->create([
                        'customer_id' => $customer->id,
                        'slug' => 'e2e-graf-'.Str::slug($title),
                        'title' => $title,
                        'page_type' => $pageType,
                        'status' => EnterpriseWikiPage::STATUS_APPROVED,
                        'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                    ]);

                    EnterpriseWikiIngestRunPage::query()->create([
                        'enterprise_wiki_ingest_run_id' => $run->id,
                        'enterprise_wiki_page_id' => $page->id,
                        'action' => 'created',
                    ]);
                    $pageIdsByTitle[$title] = $page->id;
                }
            }

            foreach (self::LINKS as [$from, $to]) {
                EnterpriseWikiPageLink::query()->create([
                    'customer_id' => $customer->id,
                    'from_page_id' => $pageIdsByTitle[$from],
                    'to_page_id' => $pageIdsByTitle[$to],
                    'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
                    'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
                ]);
            }

            return (int) $customer->id;
        });
    }

    public static function cleanup(): void
    {
        $customerId = Customer::query()->where('slug', self::CUSTOMER_SLUG)->value('id');

        if ($customerId === null) {
            return;
        }

        DB::transaction(function () use ($customerId): void {
            // Runs first: their run pages go with them; pages take their links with them.
            EnterpriseWikiIngestRun::query()->where('customer_id', $customerId)->delete();
            EnterpriseWikiPage::query()->where('customer_id', $customerId)->delete();
            EnterpriseWikiDocument::query()->where('customer_id', $customerId)->delete();
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
            'password' => Hash::make(self::VIEWER_PASSWORD),
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
