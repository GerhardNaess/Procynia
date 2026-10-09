<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Suppliers\Import\SupplierImportColumns;
use App\Services\Suppliers\Import\SupplierImportReader;
use App\Services\Suppliers\SupplierRegistration;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PDOException;
use RuntimeException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;
use ZipArchive;

/**
 * Importer leverandører (docs/supplier-management-v1-plan.md, «Excel-import av leverandører»). One test
 * per rule:
 *
 *  - the template downloads in the person's language and reads back as the import's own columns;
 *  - upload → preview writes nothing; only Bekreft registers, by «Registrer leverandør»'s rules, with
 *    classification and profile, without notifying anybody;
 *  - files that are not a real, readable .xlsx within the limits are refused in plain words;
 *  - row errors: missing fields, invalid organisation numbers, unknown values, incomplete
 *    criticality, owners who cannot own;
 *  - duplicates: in the file, existing by organisation number, possible by name, namesakes;
 *  - existing suppliers change only when chosen, only the listed master data, never when ended;
 *  - tenant isolation and permissions on every step;
 *  - a double confirm, a repeated import, a stale preview and a concurrent registration import
 *    nothing twice; a failure rolls everything back;
 *  - no file is stored, and pending imports are cleaned up.
 */
class SupplierImportTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    /** Valid Norwegian organisation numbers (modulus 11). */
    private const ORG_A = '974760673';

    private const ORG_B = '923609016';

    private const ORG_C = '991825827';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Storage::fake('local');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_template_downloads_in_the_users_language_and_reads_back_as_the_imports_columns(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        $response = $this->actingAs($editor)->get('/app/supplier-management/import/template')->assertOk();
        $this->assertStringContainsString('leverandorer-importmal.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertSame('no-store, private', $response->headers->get('cache-control'));
        $path = $response->getFile()->getPathname();
        $this->files[] = $copy = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        copy($path, $copy);

        // The data sheet comes first with the headers only, required ones marked; the guide follows.
        $sheets = $this->sheetRows($copy);
        $this->assertSame(['Leverandører', 'Veiledning'], array_keys($sheets));
        $this->assertCount(1, $sheets['Leverandører']);
        $this->assertSame('Leverandørnavn *', $sheets['Leverandører'][0][0]);
        $this->assertContains('Intern ansvarlig (e-post)', $sheets['Leverandører'][0]);
        $this->assertContains('Bransjer', $sheets['Leverandører'][0]);
        $this->assertStringContainsString('IT og skytjenester', json_encode($sheets['Veiledning'], JSON_UNESCAPED_UNICODE));

        // Every header is recognised: an empty template is «no suppliers», not «missing columns».
        $this->actingAs($editor)->post('/app/supplier-management/import', ['file' => new UploadedFile($copy, 'mal.xlsx', null, null, true)])
            ->assertSessionHasErrors(['file' => __('procynia.supplier_management.import.file_errors.no_rows')]);

        // In English for an English-speaking person.
        $editor->forceFill(['preferred_language_id' => Language::query()->firstOrCreate(['code' => 'en'], ['name_en' => 'English', 'name_no' => 'Engelsk'])->id])->save();
        $english = $this->actingAs($editor->fresh())->get('/app/supplier-management/import/template')->assertOk();
        $this->assertStringContainsString('supplier-import-template.xlsx', (string) $english->headers->get('content-disposition'));
        $this->files[] = $copy = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        copy($english->getFile()->getPathname(), $copy);
        $this->assertSame('Supplier name *', $this->sheetRows($copy)['Suppliers'][0][0]);
    }

    public function test_a_valid_file_is_previewed_without_writing_and_registered_only_when_confirmed(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $colleague = $this->supplierUser($customer, []);

        $import = $this->upload($editor, [
            [
                'name' => 'Drift  AS', 'organization_number' => '974 760 673', 'category' => 'IT og skytjenester',
                'deliverable_description' => 'Drift av lønnssystem', 'owner' => strtoupper($colleague->email),
                'contact_name' => 'Kari Kontakt', 'contact_email' => 'kari@drift.example', 'contact_phone' => '90000000',
                'criticality' => 'Kritisk', 'review_interval_months' => '12 måneder',
                'processes_personal_data' => 'Ja', 'has_system_access' => 'ja', 'supports_critical_delivery' => 'Nei', 'hard_to_replace' => 'yes',
                'data_role' => 'Databehandler', 'stores_our_data' => 'Ikke avklart', 'sectors' => 'IKT og digitale tjenester; Renhold',
            ],
            [
                // The bare minimum: Aktiv, Ikke vurdert, the person importing as intern ansvarlig.
                'name' => 'Renhold Vest', 'category' => 'other', 'deliverable_description' => 'Renhold av kontor', 'initial_status' => 'Under vurdering',
            ],
        ]);

        // The preview: nothing written yet.
        $page = $this->preview($editor, $import);
        $this->assertSame(0, Supplier::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(['total' => 2, 'new' => 2, 'existing' => 0, 'updatable' => 0, 'error' => 0, 'file_duplicate' => 0, 'possible_duplicate' => 0], $page['import']['summary']);
        [$first, $second] = $page['import']['rows'];
        $this->assertSame([2, 'new', 'Drift AS', self::ORG_A, 'it_cloud', 'critical', 12, $colleague->name, false, 3], [
            $first['row'], $first['status'], $first['values']['name'], $first['values']['organization_number'], $first['values']['category'],
            $first['values']['criticality'], $first['values']['review_interval_months'], $first['values']['owner']['name'], $first['values']['owner']['is_default'], $first['values']['profile_answers'],
        ]);
        $this->assertSame([$editor->name, true, null, 'onboarding'], [$second['values']['owner']['name'], $second['values']['owner']['is_default'], $second['values']['criticality'], $second['values']['initial_status']]);
        // What would be written never reaches the page.
        $this->assertArrayNotHasKey('write', $first);

        $this->confirm($editor, $import, $page)->assertSessionHas('success');

        $drift = Supplier::query()->where('customer_id', $customer->id)->where('organization_number', self::ORG_A)->sole();
        $this->assertSame(
            ['Drift AS', 'it_cloud', 'active', $colleague->id, 'kari@drift.example', '90000000', 'critical', 12, true, true, false, true, $editor->id],
            [$drift->name, $drift->category, $drift->status, $drift->owner_user_id, $drift->contact_email, $drift->contact_phone, $drift->criticality, $drift->review_interval_months,
                $drift->processes_personal_data, $drift->has_system_access, $drift->supports_critical_delivery, $drift->hard_to_replace, $drift->created_by],
        );
        // The status and classification it is registered with are its own, not changes.
        $this->assertFalse($drift->statusChanges()->exists());
        $this->assertFalse($drift->criticalityChanges()->exists());
        // The profile, through SupplierProfileService: with its history row.
        $profile = SupplierProfile::query()->whereKey($drift->id)->sole();
        $this->assertSame(['processor', 'unknown', ['ict', 'cleaning']], [$profile->data_role, $profile->stores_our_data, $profile->sectors]);
        $this->assertSame(1, $drift->profileChanges()->count());

        $renhold = Supplier::query()->where('customer_id', $customer->id)->where('name', 'Renhold Vest')->sole();
        $this->assertSame(['onboarding', null, $editor->id, null], [$renhold->status, $renhold->criticality, $renhold->owner_user_id, $renhold->organization_number]);
        $this->assertFalse($renhold->profile()->exists());

        // Nobody is told: an import is not a handover.
        $this->assertSame(0, UserNotification::query()->where('customer_id', $customer->id)->count());

        // The import is the record of what happened; the cell values are gone.
        $import->refresh();
        $this->assertSame([SupplierImport::STATUS_COMPLETED, null, $editor->id, 2, 0], [$import->status, $import->rows, $import->completed_by_user_id, $import->result['created'], $import->result['rejected']]);
        $result = $this->actingAs($editor)->get("/app/supplier-management/import/{$import->id}")->assertOk()->viewData('page')['props']['import'];
        $this->assertSame(['created', "/app/supplier-management/{$drift->id}"], [$result['result']['rows'][0]['outcome'], $result['result']['rows'][0]['url']]);
    }

    public function test_files_that_are_not_a_readable_xlsx_within_the_limits_are_refused_in_plain_words(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $refuse = function (UploadedFile $file, string $reason, array $parameters = []) use ($editor): void {
            $this->actingAs($editor)->post('/app/supplier-management/import', ['file' => $file])
                ->assertSessionHasErrors(['file' => __("procynia.supplier_management.import.file_errors.{$reason}", $parameters)]);
        };

        $this->actingAs($editor)->post('/app/supplier-management/import', [])->assertSessionHasErrors('file');
        $refuse(UploadedFile::fake()->createWithContent('leverandorer.xlsx', '%PDF-1.4 not a workbook %%EOF'), 'not_xlsx');
        $refuse(UploadedFile::fake()->createWithContent('leverandorer.csv', "Leverandørnavn;Kategori\nDrift AS;Annet\n"), 'not_xlsx');
        $refuse($this->macroWorkbook(), 'not_xlsx');
        $refuse(UploadedFile::fake()->create('stor.xlsx', SupplierImportReader::MAX_KILOBYTES + 1), 'too_large', ['max' => 5]);
        $refuse($this->xlsx([['contact_name' => 'Kari']], ['contact_name']), 'missing_columns', ['columns' => '«Leverandørnavn», «Kategori», «Hva leverer de til oss?»']);
        $refuse($this->xlsx([['name' => 'Drift AS']], ['name']), 'missing_columns', ['columns' => '«Kategori», «Hva leverer de til oss?»']);
        $refuse($this->rawXlsx([['Leverandørnavn', 'Navn', 'Kategori', 'Hva leverer de til oss?'], ['A', 'B', 'Annet', 'X']]), 'duplicate_column', ['column' => 'Leverandørnavn']);
        $refuse($this->xlsx([]), 'no_rows');

        $tooMany = array_fill(0, SupplierImportReader::MAX_ROWS + 1, ['name' => 'Drift AS', 'category' => 'Annet', 'deliverable_description' => 'X']);
        $refuse($this->xlsx($tooMany), 'too_many_rows', ['max' => SupplierImportReader::MAX_ROWS]);

        $this->assertSame(0, SupplierImport::query()->count());
    }

    public function test_rows_with_errors_are_shown_with_plain_reasons_and_never_registered(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $inactive = $this->supplierUser($customer, []);
        $inactive->forceFill(['is_active' => false])->save();
        $noAccess = $this->member($customer);
        ['customer' => $other] = $this->context('grc');
        $stranger = $this->supplierUser($other, []);
        $valid = ['category' => 'Annet', 'deliverable_description' => 'Leveranse'];

        $import = $this->upload($editor, [
            ['name' => 'Mangler kategori', 'deliverable_description' => 'X'],
            ['name' => 'Feil orgnr', 'organization_number' => '974760674'] + $valid,
            ['name' => 'Ukjent kategori', 'category' => 'Romfart', 'deliverable_description' => 'X'],
            ['name' => 'Feil e-post', 'contact_email' => 'ikke-en-epost'] + $valid,
            ['name' => 'Halv kritikalitet', 'criticality' => 'Viktig', 'processes_personal_data' => 'Ja'] + $valid,
            ['name' => 'Uten intervall', 'criticality' => 'Kritisk', 'processes_personal_data' => 'Nei', 'has_system_access' => 'Nei', 'supports_critical_delivery' => 'Nei', 'hard_to_replace' => 'Nei'] + $valid,
            ['name' => 'Inaktiv eier', 'owner' => $inactive->email] + $valid,
            ['name' => 'Eier uten tilgang', 'owner' => $noAccess->email] + $valid,
            ['name' => 'Eier hos annen kunde', 'owner' => $stranger->email] + $valid,
            ['name' => 'Avsluttet', 'initial_status' => 'Avsluttet'] + $valid,
            ['name' => 'Ukjent profilsvar', 'stores_our_data' => 'Kanskje'] + $valid,
            ['organization_number' => self::ORG_C] + $valid,
            ['name' => 'Gyldig', 'organization_number' => 'SE556677-8899'] + $valid,
        ]);

        $page = $this->preview($editor, $import);
        $rows = collect($page['import']['rows'])->keyBy('row');
        $error = fn (int $row): string => implode(' | ', array_column(array_filter($rows[$row]['messages'], fn (array $m): bool => $m['type'] === 'error'), 'text'));

        foreach (range(2, 13) as $row) {
            $this->assertSame('error', $rows[$row]['status'], "row {$row}");
        }

        $this->assertSame('Kategori må fylles ut.', $error(2));
        $this->assertStringContainsString('«974760674» er ikke gyldig', $error(3));
        $this->assertStringContainsString('«Romfart» er ikke en gyldig verdi for Kategori. Bruk: IT og skytjenester', $error(4));
        $this->assertSame('Skriv en gyldig e-postadresse.', $error(5));
        $this->assertStringContainsString('må også «Tilgang til våre systemer eller informasjon»', $error(6));
        $this->assertSame(__('procynia.supplier_management.validation.interval_required'), $error(7));
        $this->assertStringContainsString('kan ikke være intern ansvarlig', $error(8));
        $this->assertStringContainsString('kan ikke være intern ansvarlig', $error(9));
        // Another customer's user is nobody here — not even «found but not allowed».
        $this->assertStringContainsString('Fant ingen bruker hos dere', $error(10));
        $this->assertStringContainsString('Status «Avsluttet» kan ikke importeres', $error(11));
        $this->assertStringContainsString('«Kanskje» er ikke en gyldig verdi for Lagrer eller behandler våre data', $error(12));
        $this->assertSame('Leverandørnavn må fylles ut.', $error(13));
        // A foreign number keeps its own format.
        $this->assertSame('new', $rows[14]['status']);

        $this->confirm($editor, $import, $page);
        $this->assertSame(['Gyldig'], Supplier::query()->where('customer_id', $customer->id)->pluck('name')->all());
        $this->assertSame(12, $import->fresh()->result['rejected']);
    }

    public function test_duplicates_in_the_file_and_in_the_register_are_never_registered_twice_or_merged_by_name(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $existing = $this->supplier($customer, $editor, 'Drift AS');
        $existing->forceFill(['organization_number' => self::ORG_A])->save();
        $this->supplier($customer, $editor, 'Bygg Nord');
        $this->supplier($customer, $editor, 'Anlegg Sør');
        $numbered = $this->supplier($customer, $editor, 'Transport Øst');
        $numbered->forceFill(['organization_number' => self::ORG_B])->save();
        $valid = ['category' => 'Annet', 'deliverable_description' => 'Leveranse'];

        $import = $this->upload($editor, [
            ['name' => 'Ny AS', 'organization_number' => self::ORG_C] + $valid,              // 2: new
            ['name' => 'Ny AS (kopi)', 'organization_number' => '991 825 827'] + $valid,     // 3: same number as row 2
            ['name' => 'Uten nummer'] + $valid,                                                // 4: new
            ['name' => 'uten   NUMMER'] + $valid,                                              // 5: same name as row 4
            ['name' => 'DRIFT AS', 'organization_number' => self::ORG_A] + $valid,           // 6: existing
            ['name' => 'Bygg Nord'] + $valid,                                                  // 7: namesake, no number
            ['name' => 'Anlegg Sør', 'organization_number' => '981 038 185'] + $valid,       // 8: namesake registered without a number
            ['name' => 'Transport Øst', 'organization_number' => '983 971 636'] + $valid,    // 9: namesake with another number → new
            ['name' => 'Uten Nummer', 'organization_number' => '915000002'] + $valid,      // 10: row 4's name, which had no number
        ]);

        $page = $this->preview($editor, $import);
        $rows = collect($page['import']['rows'])->keyBy('row');
        $this->assertSame(
            [2 => 'new', 3 => 'file_duplicate', 4 => 'new', 5 => 'file_duplicate', 6 => 'existing', 7 => 'possible_duplicate', 8 => 'possible_duplicate', 9 => 'new', 10 => 'file_duplicate'],
            $rows->map(fn (array $row): string => $row['status'])->all(),
        );
        $this->assertSame([2, 4, 4], [$rows[3]['duplicate_of_row'], $rows[5]['duplicate_of_row'], $rows[10]['duplicate_of_row']]);
        $this->assertSame($existing->id, $rows[6]['existing']['id']);
        $this->assertStringContainsString(self::ORG_B, collect($rows[9]['messages'])->pluck('text')->implode(' '));

        $this->confirm($editor, $import, $page);

        $this->assertSame(1, Supplier::query()->where('customer_id', $customer->id)->where('organization_number', self::ORG_C)->count());
        $this->assertSame(1, Supplier::query()->where('customer_id', $customer->id)->where('name', 'Uten nummer')->count());
        $this->assertSame(1, Supplier::query()->where('customer_id', $customer->id)->where('name', 'Bygg Nord')->count());
        $this->assertSame(2, Supplier::query()->where('customer_id', $customer->id)->where('name', 'Transport Øst')->count());
        // The existing supplier is untouched: updating was not chosen.
        $this->assertSame('Drift AS', $existing->fresh()->name);
        $result = $import->fresh()->result;
        $this->assertSame([3, 0, 6, 0], [$result['created'], $result['updated'], $result['skipped'], $result['rejected']]);
    }

    public function test_existing_suppliers_change_only_when_chosen_and_only_in_the_master_data_listed(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $colleague = $this->supplierUser($customer, []);
        $drift = $this->supplier($customer, $editor, 'Drift AS', Supplier::STATUS_ACTIVE, $this->classification());
        $drift->forceFill(['organization_number' => self::ORG_A, 'contact_name' => 'Kari Kontakt', 'note' => 'Behold meg'])->save();
        $ended = $this->supplier($customer, $editor, 'Avsluttet AS', Supplier::STATUS_ENDED);
        $ended->forceFill(['organization_number' => self::ORG_B])->save();
        $same = $this->supplier($customer, $editor, 'Lik AS');
        $same->forceFill(['organization_number' => self::ORG_C])->save();

        $rows = [
            // Name, deliverable and owner change; empty cells (note) keep; criticality is not touched.
            ['name' => 'Drift Norge AS', 'organization_number' => self::ORG_A, 'deliverable_description' => 'Drift og support', 'owner' => $colleague->email, 'contact_name' => 'Kari Kontakt', 'criticality' => 'Kritisk'],
            ['name' => 'Avsluttet AS', 'organization_number' => self::ORG_B, 'category' => 'Annet', 'deliverable_description' => 'Nytt'],
            ['name' => 'Lik AS', 'organization_number' => self::ORG_C, 'category' => 'IT og skytjenester', 'deliverable_description' => 'Drift av lønnssystem'],
        ];
        $import = $this->upload($editor, $rows, ['name', 'organization_number', 'category', 'deliverable_description', 'owner', 'contact_name', 'criticality']);
        $page = $this->preview($editor, $import);
        $byRow = collect($page['import']['rows'])->keyBy('row');

        $this->assertSame(['existing', true], [$byRow[2]['status'], $byRow[2]['can_update']]);
        $this->assertSame([
            ['field' => 'name', 'from' => 'Drift AS', 'to' => 'Drift Norge AS'],
            ['field' => 'deliverable_description', 'from' => 'Drift av lønnssystem', 'to' => 'Drift og support'],
            ['field' => 'owner_user_id', 'from' => $editor->name, 'to' => $colleague->name],
        ], $byRow[2]['changes']);
        $this->assertSame([false, false], [$byRow[3]['can_update'], $byRow[4]['can_update']]);
        $this->assertSame(1, $page['import']['summary']['updatable']);

        // Not chosen: nothing changes.
        $this->confirm($editor, $import, $page, false);
        $this->assertSame('Drift AS', $drift->fresh()->name);
        $this->assertSame('existing_not_chosen', $import->fresh()->result['rows'][0]['reason']);

        // Chosen: exactly the listed fields, nothing else.
        $import = $this->upload($editor, $rows, ['name', 'organization_number', 'category', 'deliverable_description', 'owner', 'contact_name', 'criticality']);
        $this->confirm($editor, $import, $this->preview($editor, $import), true);
        $drift->refresh();
        $this->assertSame(['Drift Norge AS', 'Drift og support', $colleague->id, 'Behold meg', 'standard', $editor->id], [$drift->name, $drift->deliverable_description, $drift->owner_user_id, $drift->note, $drift->criticality, $drift->updated_by]);
        $this->assertFalse($drift->criticalityChanges()->exists());
        $this->assertSame(['ended', 'Avsluttet AS', 'Drift av lønnssystem'], [$ended->fresh()->status, $ended->fresh()->name, $ended->fresh()->deliverable_description]);
        $this->assertSame(['updated', 'skipped', 'skipped'], array_column($import->fresh()->result['rows'], 'outcome'));
        $this->assertSame(['existing_ended', 'existing_unchanged'], array_column(array_slice($import->fresh()->result['rows'], 1), 'reason'));
        // A reassignment by import tells nobody.
        $this->assertSame(0, UserNotification::query()->where('customer_id', $customer->id)->count());
    }

    public function test_another_customers_register_and_imports_are_never_read_or_changed(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $sameCustomerEditor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        ['customer' => $other] = $this->context('grc');
        $otherEditor = $this->supplierUser($other, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $theirs = $this->supplier($other, $otherEditor, 'Deres Drift AS');
        $theirs->forceFill(['organization_number' => self::ORG_A])->save();
        $this->supplier($other, $otherEditor, 'Navnebror AS');

        $import = $this->upload($editor, [
            ['name' => 'Vår Drift AS', 'organization_number' => self::ORG_A, 'category' => 'Annet', 'deliverable_description' => 'X'],
            ['name' => 'Navnebror AS', 'category' => 'Annet', 'deliverable_description' => 'X'],
        ]);
        $page = $this->preview($editor, $import);

        // Their supplier is no match here: both rows are new, and nothing of theirs is on the page.
        $this->assertSame(['new', 'new'], array_column($page['import']['rows'], 'status'));
        $this->assertStringNotContainsString('Deres Drift', json_encode($page['import'], JSON_UNESCAPED_UNICODE));

        // Someone else's import — same customer or not — is not found.
        $this->actingAs($sameCustomerEditor)->get("/app/supplier-management/import/{$import->id}")->assertNotFound();
        $this->actingAs($otherEditor)->get("/app/supplier-management/import/{$import->id}")->assertNotFound();
        $this->actingAs($otherEditor)->post("/app/supplier-management/import/{$import->id}/execute", ['update_existing' => true, 'preview_hash' => $page['import']['preview_hash']])->assertNotFound();
        $this->actingAs($otherEditor)->delete("/app/supplier-management/import/{$import->id}")->assertNotFound();
        $this->assertFalse($import->fresh()->isCompleted());

        $this->confirm($editor, $import, $page, true);
        $this->assertSame('Deres Drift AS', $theirs->fresh()->name);
        $this->assertSame(1, Supplier::query()->where('customer_id', $other->id)->where('organization_number', self::ORG_A)->count());
        $this->assertSame(1, Supplier::query()->where('customer_id', $customer->id)->where('organization_number', self::ORG_A)->count());
    }

    public function test_only_supplier_edit_imports_on_every_step_and_losing_it_before_confirm_writes_nothing(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $reader = $this->supplierUser($customer, []);
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $file = fn (): UploadedFile => $this->xlsx([['name' => 'Drift AS', 'category' => 'Annet', 'deliverable_description' => 'X']]);

        foreach ([$reader, $assessor] as $user) {
            $this->actingAs($user)->get('/app/supplier-management/import')->assertForbidden();
            $this->actingAs($user)->get('/app/supplier-management/import/template')->assertForbidden();
            $this->actingAs($user)->post('/app/supplier-management/import', ['file' => $file()])->assertForbidden();
        }

        // The register offers the button only with supplier.edit.
        $this->assertFalse($this->actingAs($reader)->get('/app/supplier-management')->viewData('page')['props']['permissions']['can_edit']);
        $this->actingAs($editor)->get('/app/supplier-management/import')->assertOk();

        $import = $this->upload($editor, [['name' => 'Drift AS', 'category' => 'Annet', 'deliverable_description' => 'X']]);
        $page = $this->preview($editor, $import);

        // The role loses supplier.edit between the preview and Bekreft.
        DB::table('customer_role_permissions')->where('permission_key', CustomerPermissionCatalog::SUPPLIER_EDIT)
            ->whereIn('customer_role_id', $editor->customerRoles()->pluck('customer_roles.id'))->delete();
        $this->actingAs($editor->fresh())->post("/app/supplier-management/import/{$import->id}/execute", ['update_existing' => false, 'preview_hash' => $page['import']['preview_hash']])->assertForbidden();
        $this->assertSame(0, Supplier::query()->where('customer_id', $customer->id)->count());

        // A customer without the module does not reach the import at all: the module guard sends the
        // person to Hjem, as for every page of Leverandøroppfølging.
        ['customer' => $basis] = $this->context('basis');
        $basisUser = $this->supplierUser($basis, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($basisUser)->get('/app/supplier-management/import')->assertRedirect(route('app.dashboard'));
        $this->actingAs($basisUser)->post('/app/supplier-management/import', ['file' => $file()])->assertRedirect(route('app.dashboard'));
        $this->assertSame(0, SupplierImport::query()->where('customer_id', $basis->id)->count());
    }

    public function test_a_double_confirm_and_a_repeated_import_register_nothing_twice(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $rows = [
            ['name' => 'Drift AS', 'organization_number' => self::ORG_A, 'category' => 'Annet', 'deliverable_description' => 'X'],
            ['name' => 'Renhold Vest', 'category' => 'Annet', 'deliverable_description' => 'X'],
        ];

        $import = $this->upload($editor, $rows);
        $page = $this->preview($editor, $import);
        $this->confirm($editor, $import, $page)->assertSessionHas('success', __('procynia.supplier_management.import.flash.completed'));
        // The second click of a double click, or a retry.
        $this->confirm($editor, $import, $page)->assertSessionHas('success', __('procynia.supplier_management.import.flash.already_completed'));
        $this->assertSame(2, Supplier::query()->where('customer_id', $customer->id)->count());

        // The same file again: one existing, one possible duplicate — nothing new.
        $again = $this->upload($editor, $rows);
        $page = $this->preview($editor, $again);
        $this->assertSame(['existing', 'possible_duplicate'], array_column($page['import']['rows'], 'status'));
        $this->confirm($editor, $again, $page);
        $this->assertSame(2, Supplier::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, $again->fresh()->result['created']);
    }

    public function test_a_register_changed_since_the_preview_or_during_the_import_is_never_overwritten(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $colleague = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $rows = [
            ['name' => 'Drift AS', 'organization_number' => self::ORG_A, 'category' => 'Annet', 'deliverable_description' => 'X'],
            ['name' => 'Bygg Nord', 'organization_number' => self::ORG_B, 'category' => 'Annet', 'deliverable_description' => 'X'],
        ];

        // Two people import the same file at once: the first confirms, the second's preview is stale.
        $mine = $this->upload($editor, $rows);
        $theirs = $this->upload($colleague, $rows);
        $myPage = $this->preview($editor, $mine);
        $theirPage = $this->preview($colleague, $theirs);
        $this->confirm($editor, $mine, $myPage)->assertSessionHas('success');
        $this->confirm($colleague, $theirs, $theirPage)->assertSessionHas('error', __('procynia.supplier_management.import.flash.stale'));
        $this->assertFalse($theirs->fresh()->isCompleted());
        $this->assertSame(2, Supplier::query()->where('customer_id', $customer->id)->count());

        // Looking again shows both as existing; confirming then registers nothing.
        $theirPage = $this->preview($colleague, $theirs);
        $this->assertSame(['existing', 'existing'], array_column($theirPage['import']['rows'], 'status'));
        $this->confirm($colleague, $theirs, $theirPage);
        $this->assertSame(2, Supplier::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_supplier_registered_by_hand_while_the_import_is_written_is_never_registered_twice(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        // The database refuses the organisation number, as its unique index does when someone registers
        // it by hand between the analysis and the write: that row is rejected, the rest is imported.
        $this->app->bind(SupplierRegistration::class, fn () => new class extends SupplierRegistration
        {
            public function register(User $actor, array $fields, string $initialStatus, ?array $classification): Supplier
            {
                if ($fields['organization_number'] === '991825827') {
                    throw new UniqueConstraintViolationException('pgsql', 'insert into suppliers', [], new PDOException('duplicate key value violates unique constraint'));
                }

                return parent::register($actor, $fields, $initialStatus, $classification);
            }
        });

        $race = $this->upload($editor, [
            ['name' => 'Kappløp AS', 'organization_number' => self::ORG_C, 'category' => 'Annet', 'deliverable_description' => 'X'],
            ['name' => 'Etterpå AS', 'category' => 'Annet', 'deliverable_description' => 'X'],
        ]);
        $this->confirm($editor, $race, $this->preview($editor, $race))->assertSessionHas('success');

        $this->assertSame(0, Supplier::query()->where('customer_id', $customer->id)->where('organization_number', self::ORG_C)->count());
        $this->assertSame(1, Supplier::query()->where('customer_id', $customer->id)->where('name', 'Etterpå AS')->count());
        $rows = $race->fresh()->result['rows'];
        $this->assertSame([['rejected', 'organization_number_taken'], ['created', null]], [[$rows[0]['outcome'], $rows[0]['reason']], [$rows[1]['outcome'], $rows[1]['reason']]]);
    }

    public function test_a_failure_while_importing_rolls_everything_back_and_shows_no_internal_error(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->app->bind(SupplierRegistration::class, fn () => new class extends SupplierRegistration
        {
            public function register(User $actor, array $fields, string $initialStatus, ?array $classification): Supplier
            {
                if ($fields['name'] === 'Krasj AS') {
                    throw new RuntimeException('SQLSTATE[XX000]: internal detail');
                }

                return parent::register($actor, $fields, $initialStatus, $classification);
            }
        });

        $import = $this->upload($editor, [
            ['name' => 'Først AS', 'category' => 'Annet', 'deliverable_description' => 'X'],
            ['name' => 'Krasj AS', 'category' => 'Annet', 'deliverable_description' => 'X'],
        ]);
        $response = $this->confirm($editor, $import, $this->preview($editor, $import));

        $response->assertSessionHas('error', __('procynia.supplier_management.import.flash.failed'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
        $this->assertSame(0, Supplier::query()->where('customer_id', $customer->id)->count());
        $this->assertFalse($import->fresh()->isCompleted());
        $this->assertNotNull($import->fresh()->rows);
    }

    public function test_no_file_is_stored_and_unconfirmed_imports_are_cleaned_up(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $row = [['name' => 'Drift AS', 'category' => 'Annet', 'deliverable_description' => 'X', 'note' => '=HYPERLINK("http://example.test")']];

        $first = $this->upload($editor, $row);
        $this->assertSame([], Storage::disk('local')->allFiles());
        // Only the recognised cell values are kept — never the file, and never text that would be a
        // formula when the register is opened in a spreadsheet again.
        $this->assertSame([['row' => 2, 'cells' => ['name' => 'Drift AS', 'category' => 'Annet', 'deliverable_description' => 'X']]], $first->rows);

        // One pending import a person: a new upload replaces it.
        $second = $this->upload($editor, $row);
        $this->assertNull(SupplierImport::query()->find($first->id));

        // Avbryt deletes it.
        $this->actingAs($editor)->delete("/app/supplier-management/import/{$second->id}")->assertRedirect('/app/supplier-management/import');
        $this->assertNull(SupplierImport::query()->find($second->id));

        // Not confirmed within a day: deleted by the scheduled command, and refused when opened.
        $stale = $this->upload($editor, $row);
        $stale->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->actingAs($editor)->get("/app/supplier-management/import/{$stale->id}")
            ->assertRedirect('/app/supplier-management/import')
            ->assertSessionHas('error', __('procynia.supplier_management.import.flash.expired'));
        $this->assertNull(SupplierImport::query()->find($stale->id));

        $old = $this->upload($editor, $row);
        $old->forceFill(['created_at' => now()->subHours(25)])->save();
        $completed = $this->upload($this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]), $row);
        $completed->forceFill(['status' => SupplierImport::STATUS_COMPLETED, 'rows' => null, 'result' => ['created' => 0, 'rows' => []], 'completed_at' => now()->subDays(3), 'created_at' => now()->subDays(3)])->save();
        $fresh = $this->upload($this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]), $row);

        $this->artisan('suppliers:prune-imports')->assertSuccessful();
        $this->assertNull(SupplierImport::query()->find($old->id));
        $this->assertNotNull(SupplierImport::query()->find($completed->id));
        $this->assertNotNull(SupplierImport::query()->find($fresh->id));
    }

    public function test_a_formula_is_never_evaluated_and_the_messages_follow_the_users_language(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $editor->forceFill(['preferred_language_id' => Language::query()->firstOrCreate(['code' => 'en'], ['name_en' => 'English', 'name_no' => 'Engelsk'])->id])->save();
        $editor = $editor->fresh();

        // English headers and values. Row 2's name is a formula whose cached value is not what the formula
        // computes: the cached value is what Excel showed, and it is what is read.
        $file = $this->rawXlsx([
            ['Supplier name', 'Category', 'What do they deliver to us?', 'Organisation number'],
            [new FormulaCell('=1+1', null, null), 'Other', 'Cleaning', ''],
            ['Cleaning Ltd', 'Space travel', 'Cleaning', '974760674'],
            ['', 'Other', 'Cleaning', ''],
        ]);
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', (string) preg_replace('#<c r="A2"([^>]*)><f>1\+1</f></c>#', '<c r="A2"$1 t="str"><f>1+1</f><v>Cached AS</v></c>', $sheet));
        $zip->close();

        $this->actingAs($editor)->post('/app/supplier-management/import', ['file' => new UploadedFile($file->getRealPath(), 'suppliers.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $import = SupplierImport::query()->where('created_by_user_id', $editor->id)->sole();
        $this->assertSame('Cached AS', $import->rows[0]['cells']['name']);

        $rows = $this->preview($editor, $import)['import']['rows'];
        $this->assertSame('new', $rows[0]['status']);
        $this->assertStringContainsString('The organisation number «974760674» is not valid.', $rows[1]['messages'][0]['text']);
        $this->assertSame('Supplier name is required.', collect($rows[2]['messages'])->firstWhere('type', 'error')['text'] ?? null);
    }

    // --- helpers -------------------------------------------------------------------------------

    /**
     * Uploads rows (field => text) as an .xlsx with the template's Norwegian headers.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>|null  $fields
     */
    private function upload(User $user, array $rows, ?array $fields = null): SupplierImport
    {
        $this->actingAs($user)->post('/app/supplier-management/import', ['file' => $this->xlsx($rows, $fields)])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return SupplierImport::query()->where('created_by_user_id', $user->id)->where('status', SupplierImport::STATUS_PENDING)->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> the page props */
    private function preview(User $user, SupplierImport $import): array
    {
        return $this->actingAs($user)->get("/app/supplier-management/import/{$import->id}")->assertOk()->viewData('page')['props'];
    }

    /** @param  array<string, mixed>  $page */
    private function confirm(User $user, SupplierImport $import, array $page, bool $updateExisting = false): TestResponse
    {
        return $this->actingAs($user)->post("/app/supplier-management/import/{$import->id}/execute", [
            'update_existing' => $updateExisting,
            'preview_hash' => $page['import']['preview_hash'],
        ])->assertRedirect("/app/supplier-management/import/{$import->id}");
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>|null  $fields
     */
    private function xlsx(array $rows, ?array $fields = null): UploadedFile
    {
        $fields ??= array_values(array_unique(array_merge(SupplierImportColumns::REQUIRED, ...array_map('array_keys', $rows ?: [[]]))));
        $table = [array_map(fn (string $field): string => SupplierImportColumns::header($field, 'no'), $fields)];

        foreach ($rows as $row) {
            $table[] = array_map(fn (string $field): string => (string) ($row[$field] ?? ''), $fields);
        }

        return $this->rawXlsx($table);
    }

    /** @param  list<list<string|Cell>>  $table */
    private function rawXlsx(array $table): UploadedFile
    {
        $this->files[] = $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($table as $values) {
            $writer->addRow(new Row(array_map(fn ($value): Cell => $value instanceof Cell ? $value : ($value === '' ? Cell::fromValue(null) : new StringCell($value, null)), $values)));
        }

        $writer->close();

        return new UploadedFile($path, 'leverandorer.xlsx', null, null, true);
    }

    private function macroWorkbook(): UploadedFile
    {
        $file = $this->xlsx([['name' => 'Drift AS', 'category' => 'Annet', 'deliverable_description' => 'X']]);
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        $zip->addFromString('xl/vbaProject.bin', 'macro');
        $zip->close();

        return new UploadedFile($file->getRealPath(), 'leverandorer.xlsx', null, null, true);
    }

    /** @return array<string, list<list<string>>> sheet name => rows of cell texts */
    private function sheetRows(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = array_map(fn (Cell $cell): string => (string) $cell->getValue(), $row->getCells());
            }
        }

        $reader->close();

        return $sheets;
    }
}
