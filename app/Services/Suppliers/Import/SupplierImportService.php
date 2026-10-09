<?php

namespace App\Services\Suppliers\Import;

use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierProfileService;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierRegistration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Importer leverandører (docs/supplier-management-v1-plan.md, «Excel-import av leverandører»):
 * upload → preview → confirm, with nothing written to the register before the person confirms.
 *
 * UPLOAD reads the file in the request (SupplierImportReader) and keeps only the recognised cell
 * values in a pending SupplierImport. The file is never stored: PHP removes the upload when the
 * request ends, so there is no temporary file to scan or clean up. A person has one pending import at
 * a time — a new upload replaces the previous one.
 *
 * PREVIEW is SupplierImportAnalyzer against the register as it is now. It writes nothing.
 *
 * EXECUTE, in one transaction:
 *  - the import row is locked; one already completed is returned as it is — a double click or a
 *    retry imports nothing twice;
 *  - imports for the same customer wait for each other (a transaction-scoped advisory lock), so two
 *    at once cannot both register the same supplier;
 *  - supplier.edit is checked again, and the rows are analysed again inside the lock. If the result
 *    is not what the person confirmed — the register changed in between — nothing is written and the
 *    person is asked to look at the preview again (StaleSupplierImportException);
 *  - every supplier is written in its own savepoint with SupplierRegistration — «Registrer
 *    leverandør»'s own write — and its profile through SupplierProfileService, so a supplier is
 *    either complete or not there. A row the database refuses at that moment (the same organisation
 *    number registered by hand a second ago) is reported as rejected; any other failure rolls the
 *    whole import back and leaves it pending, to be tried again;
 *  - existing suppliers change only when the person chose «Oppdater eksisterende», and only the
 *    master data the preview listed;
 *  - nobody is notified: an import is not a handover, and hundreds of bell items would bury the real
 *    ones. Follow-up shows in Mine oppgaver and Trenger oppmerksomhet as for any supplier;
 *  - the import is marked completed with who did it, when and what happened, and its rows cleared.
 */
class SupplierImportService
{
    /** A pending import nobody confirmed is deleted after this many hours. */
    public const PENDING_LIFETIME_HOURS = 24;

    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierImportReader $reader,
        private readonly SupplierImportAnalyzer $analyzer,
        private readonly SupplierRegistration $registration,
        private readonly SupplierProfileService $profiles,
    ) {}

    /** @throws SupplierImportFileException */
    public function upload(User $actor, UploadedFile $file): SupplierImport
    {
        $read = $this->reader->read((string) $file->getRealPath(), $file->getClientOriginalName());

        return DB::transaction(function () use ($actor, $file, $read): SupplierImport {
            $this->pendingFor($actor)->delete();

            $import = (new SupplierImport)->forceFill([
                'customer_id' => (int) $actor->customer_id,
                'created_by_user_id' => (int) $actor->id,
                'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'status' => SupplierImport::STATUS_PENDING,
                'row_count' => count($read['rows']),
                'rows' => $read['rows'],
                'ignored_columns' => $read['ignored_columns'],
            ]);
            $import->save();

            return $import;
        });
    }

    /** The person's own import, or null — another person's, or another customer's, is not found. */
    public function find(User $actor, int $importId): ?SupplierImport
    {
        return SupplierImport::query()
            ->where('customer_id', (int) $actor->customer_id)
            ->where('created_by_user_id', (int) $actor->id)
            ->whereKey($importId)
            ->first();
    }

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, int>, hash: string} */
    public function preview(User $actor, SupplierImport $import): array
    {
        return $this->analyzer->analyze($actor, $import->rows ?? []);
    }

    /**
     * @throws StaleSupplierImportException when the register changed since the preview
     * @throws AuthorizationException
     */
    public function execute(User $actor, int $importId, bool $updateExisting, string $previewHash): SupplierImport
    {
        return DB::transaction(function () use ($actor, $importId, $updateExisting, $previewHash): SupplierImport {
            $import = SupplierImport::query()
                ->where('customer_id', (int) $actor->customer_id)
                ->where('created_by_user_id', (int) $actor->id)
                ->whereKey($importId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($import->isCompleted()) {
                return $import;
            }

            if (! $this->access->canEdit($actor)) {
                throw new AuthorizationException;
            }

            // One import per customer at a time, until this transaction ends.
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [crc32('supplier-import'), (int) $actor->customer_id]);

            $analysis = $this->analyzer->analyze($actor, $import->rows ?? []);

            if (! hash_equals($analysis['hash'], $previewHash)) {
                throw new StaleSupplierImportException;
            }

            $outcomes = [];

            foreach ($analysis['rows'] as $row) {
                $outcomes[] = $this->carryOut($actor, $row, $updateExisting);
            }

            $counts = array_count_values(array_column($outcomes, 'outcome'));

            $import->forceFill([
                'status' => SupplierImport::STATUS_COMPLETED,
                'update_existing' => $updateExisting,
                'rows' => null,
                'result' => [
                    'created' => $counts['created'] ?? 0,
                    'updated' => $counts['updated'] ?? 0,
                    'skipped' => $counts['skipped'] ?? 0,
                    'rejected' => $counts['rejected'] ?? 0,
                    'rows' => $outcomes,
                ],
                'completed_by_user_id' => (int) $actor->id,
                'completed_at' => now(),
            ])->save();

            return $import;
        });
    }

    /** Avbryt: a pending import is deleted; a completed one is the record of what happened and stays. */
    public function discard(SupplierImport $import): void
    {
        if (! $import->isCompleted()) {
            $import->delete();
        }
    }

    /** Pending imports older than PENDING_LIFETIME_HOURS, deleted. */
    public function pruneExpired(): int
    {
        return SupplierImport::query()
            ->where('status', SupplierImport::STATUS_PENDING)
            ->where('created_at', '<', now()->subHours(self::PENDING_LIFETIME_HOURS))
            ->delete();
    }

    public function isExpired(SupplierImport $import): bool
    {
        return ! $import->isCompleted() && $import->created_at?->lt(now()->subHours(self::PENDING_LIFETIME_HOURS));
    }

    /** @return Builder<SupplierImport> */
    private function pendingFor(User $actor)
    {
        return SupplierImport::query()
            ->where('customer_id', (int) $actor->customer_id)
            ->where('created_by_user_id', (int) $actor->id)
            ->where('status', SupplierImport::STATUS_PENDING);
    }

    /**
     * One row, in its own savepoint.
     *
     * @param  array<string, mixed>  $row
     * @return array{row: int, name: string, outcome: string, reason: string|null, supplier_id: int|null, messages: list<string>}
     */
    private function carryOut(User $actor, array $row, bool $updateExisting): array
    {
        $errors = array_values(array_map(
            fn (array $message): string => $message['text'],
            array_filter($row['messages'], fn (array $message): bool => $message['type'] === 'error'),
        ));
        $outcome = [
            'row' => (int) $row['row'],
            'name' => (string) ($row['values']['name'] ?? ''),
            'outcome' => 'skipped',
            'reason' => match (true) {
                $row['status'] !== SupplierImportAnalyzer::STATUS_EXISTING => $row['status'],
                ($row['existing']['status'] ?? null) === Supplier::STATUS_ENDED => 'existing_ended',
                $errors !== [] => 'existing_not_updatable',
                default => 'existing_unchanged',
            },
            'supplier_id' => $row['existing']['id'] ?? null,
            // What was wrong, in the words the person saw — the record of why a row was not imported.
            'messages' => $errors,
        ];

        $write = $row['write'] ?? null;

        if ($row['status'] === SupplierImportAnalyzer::STATUS_ERROR) {
            return ['outcome' => 'rejected'] + $outcome;
        }

        if ($write === null) {
            return $outcome;
        }

        if ($write['action'] === 'update' && ! $updateExisting) {
            return ['reason' => 'existing_not_chosen'] + $outcome;
        }

        try {
            return DB::transaction(fn (): array => $write['action'] === 'create'
                ? ['outcome' => 'created', 'reason' => null, 'supplier_id' => $this->create($actor, $write)] + $outcome
                : ['outcome' => 'updated', 'reason' => null] + ['supplier_id' => $this->update($actor, $write)] + $outcome);
        } catch (UniqueConstraintViolationException) {
            // Registered by someone else since the analysis above — never a second copy.
            return ['outcome' => 'rejected', 'reason' => 'organization_number_taken'] + $outcome;
        } catch (ValidationException) {
            return ['outcome' => 'rejected', 'reason' => 'changed'] + $outcome;
        }
    }

    /** @param  array<string, mixed>  $write */
    private function create(User $actor, array $write): int
    {
        $supplier = $this->registration->register($actor, $write['fields'], $write['initial_status'], $write['classification']);

        if ($write['profile'] !== null) {
            $this->profiles->save($supplier, $actor, $write['profile'], null);
        }

        return (int) $supplier->id;
    }

    /**
     * Only the fields the preview listed, with the supplier locked and checked again: one ended since
     * is left alone.
     *
     * @param  array<string, mixed>  $write
     */
    private function update(User $actor, array $write): int
    {
        $supplier = $this->access->visibleSuppliers($actor)->whereKey($write['supplier_id'])->lockForUpdate()->first();

        if (! $supplier instanceof Supplier || $supplier->isEnded()) {
            throw ValidationException::withMessages(['supplier' => 'changed']);
        }

        $supplier->fill($write['fields'] + ['updated_by' => (int) $actor->id])->save();

        return (int) $supplier->id;
    }
}
