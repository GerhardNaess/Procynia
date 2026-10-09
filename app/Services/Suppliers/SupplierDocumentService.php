<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Support\PrivateFiles\PrivateFileRule;
use App\Support\PrivateFiles\PrivateFileScanStatus;
use App\Support\PrivateFiles\PrivateFileStore;
use App\Support\PrivateFiles\StoredPrivateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dokumentasjonsoversikt: the only writer of a SupplierDocument (docs/supplier-management-v1-plan.md
 * §4.4, §6.3).
 *
 * Registers, corrects, renews and deletes the description of a document — type, name, where it is
 * kept, how long it is valid, a comment. Location is stored as the text it was given and nothing
 * fetches it. Never the Enterprise Wiki document store.
 *
 * Since v2.1 (supplier-assurance-v2-plan §27) a row may carry one private file, through the shared
 * PrivateFileStore — never Wiki, never AI. The file is written first, then the row in a transaction;
 * if the row cannot be written, the new file is deleted again. A file that is replaced or removed is
 * deleted only after the transaction commits, and never while a row or a control's snapshot still
 * refers to it; a deletion that fails is left for private-files:prune-orphans. A row a control rests
 * on keeps its file: it can be neither replaced nor removed.
 *
 * «Registrer fornyet» adds a new row of the same type and points the renewed row at it, in one
 * transaction. Only a row that has not been replaced already can be renewed, and the new row always
 * belongs to the same supplier — the database only knows the pointer is a row.
 *
 * An ended supplier is read-only: every write locks the supplier row and checks its status inside
 * the lock, so nothing lands on a supplier being ended at the same moment.
 *
 * A row given as the basis of a control — or one renewing such a row — is never deleted
 * (supplier-assurance-v2-plan §10.4): checked here inside the lock, and refused by the database's
 * NO ACTION reference too. Correcting it is still allowed; the control keeps its snapshot.
 *
 * Authorization is the caller's (supplier.edit or supplier.assure through SupplierAccessService).
 */
class SupplierDocumentService
{
    public function __construct(private readonly PrivateFileStore $files) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(SupplierDocument::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'standard' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:2000'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The optional file on Legg til and Registrer fornyet, and the required one on «Last opp fil».
     *
     * @return array<string, list<mixed>>
     */
    public static function fileRules(bool $required = false): array
    {
        return [
            'file' => [$required ? 'required' : 'nullable', new PrivateFileRule(
                __('procynia.supplier_management.validation.file_type'),
                __('procynia.supplier_management.validation.file_size'),
            )],
        ];
    }

    /** @param  array<string, mixed>  $validated */
    public function create(Supplier $supplier, User $actor, array $validated, ?UploadedFile $file = null): SupplierDocument
    {
        return $this->withNewFile($supplier, $file, fn (?StoredPrivateFile $stored): SupplierDocument => $this->whileOpen($supplier, function (Supplier $locked) use ($actor, $validated, $stored): SupplierDocument {
            $document = new SupplierDocument($this->fields($validated) + [
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $document->forceFill($this->fileColumns($stored, $actor))->save();

            return $document;
        }));
    }

    /** @param  array<string, mixed>  $validated */
    public function update(SupplierDocument $document, User $actor, array $validated): SupplierDocument
    {
        return $this->whileOpen($document->supplier, function () use ($document, $actor, $validated): SupplierDocument {
            $document->fill($this->fields($validated) + ['updated_by' => $actor->id])->save();

            return $document;
        });
    }

    /**
     * Registrer fornyet: the new document, of the same type as the one it renews; the old one stays
     * and is marked Erstattet.
     *
     * @param  array<string, mixed>  $validated  everything but the type, which the renewed row decides
     */
    public function renew(SupplierDocument $document, User $actor, array $validated, ?UploadedFile $file = null): SupplierDocument
    {
        return $this->withNewFile($document->supplier, $file, fn (?StoredPrivateFile $stored): SupplierDocument => $this->whileOpen($document->supplier, function (Supplier $locked) use ($document, $actor, $validated, $stored): SupplierDocument {
            $old = SupplierDocument::query()
                ->whereKey($document->id)
                ->where('supplier_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($old->isReplaced()) {
                throw ValidationException::withMessages(['title' => __('procynia.supplier_management.validation.document_already_replaced')]);
            }

            $new = new SupplierDocument([
                'document_type' => $old->document_type,
            ] + $this->fields($validated) + [
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $new->forceFill($this->fileColumns($stored, $actor))->save();

            $old->forceFill(['replaced_by_document_id' => $new->id, 'updated_by' => $actor->id])->save();

            return $new;
        }));
    }

    /**
     * «Last opp fil» / «Erstatt fil»: puts a file on the row. The earlier file, if any, is deleted once
     * the row no longer refers to it. Refused for a row a control rests on.
     */
    public function attachFile(SupplierDocument $document, User $actor, UploadedFile $file): SupplierDocument
    {
        return $this->withNewFile($document->supplier, $file, fn (?StoredPrivateFile $stored): SupplierDocument => $this->whileOpen($document->supplier, function (Supplier $locked) use ($document, $actor, $stored): SupplierDocument {
            $row = $this->lockedFileRow($document, $locked);
            $previous = $row->file_path;

            $row->forceFill($this->fileColumns($stored, $actor) + ['updated_by' => $actor->id])->save();

            if ($previous !== null) {
                $this->files->deleteAfterCommit($previous, (int) $locked->customer_id, SupplierDocument::FILE_AREA);
            }

            return $row;
        }));
    }

    /** «Fjern fil»: the row stays, without a file. Refused for a row a control rests on. */
    public function removeFile(SupplierDocument $document, User $actor): SupplierDocument
    {
        return $this->whileOpen($document->supplier, function (Supplier $locked) use ($document, $actor): SupplierDocument {
            $row = $this->lockedFileRow($document, $locked);

            if (! $row->hasFile()) {
                throw ValidationException::withMessages(['file' => __('procynia.supplier_management.validation.file_missing')]);
            }

            $previous = (string) $row->file_path;
            $row->forceFill($this->fileColumns(null, $actor) + ['updated_by' => $actor->id])->save();
            $this->files->deleteAfterCommit($previous, (int) $locked->customer_id, SupplierDocument::FILE_AREA);

            return $row;
        });
    }

    /**
     * Removes a row registered by mistake. A row it replaced points back to nothing and is current
     * again. Never a row a control rests on, nor one renewing it.
     */
    public function delete(SupplierDocument $document): void
    {
        $this->whileOpen($document->supplier, function () use ($document): void {
            if (! $document->isDeletable()) {
                throw ValidationException::withMessages(['title' => __('procynia.supplier_management.validation.document_used_in_control')]);
            }

            $path = $document->file_path;
            $document->delete();

            if ($path !== null) {
                $this->files->deleteAfterCommit($path, (int) $document->customer_id, SupplierDocument::FILE_AREA);
            }
        });
    }

    /**
     * The row locked inside the supplier lock, for a change of its file — refused while a control
     * rests on it (its file is that control's evidence).
     */
    private function lockedFileRow(SupplierDocument $document, Supplier $locked): SupplierDocument
    {
        $row = SupplierDocument::query()
            ->whereKey($document->id)
            ->where('supplier_id', $locked->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($row->isUsedInControl()) {
            throw ValidationException::withMessages(['file' => __('procynia.supplier_management.validation.file_locked')]);
        }

        return $row;
    }

    /**
     * Stores the file (if any) before $write, and deletes it again if $write does not complete — so a
     * refused or failed write never leaves a file behind.
     *
     * @template T
     *
     * @param  callable(?StoredPrivateFile): T  $write
     * @return T
     */
    private function withNewFile(Supplier $supplier, ?UploadedFile $file, callable $write): mixed
    {
        $stored = $file === null ? null : $this->files->store($file, (int) $supplier->customer_id, SupplierDocument::FILE_AREA);

        try {
            return $write($stored);
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->files->delete($stored->path, (int) $supplier->customer_id, SupplierDocument::FILE_AREA);
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> the file columns for $stored, or all empty */
    private function fileColumns(?StoredPrivateFile $stored, User $actor): array
    {
        return [
            'file_key' => $stored?->key,
            'file_path' => $stored?->path,
            'file_original_name' => $stored?->originalName,
            'file_mime_type' => $stored?->mimeType,
            'file_size_bytes' => $stored?->sizeBytes,
            'file_sha256' => $stored?->sha256,
            'file_scan_status' => $stored === null ? null : PrivateFileScanStatus::NOT_SCANNED,
            'file_uploaded_by' => $stored === null ? null : $actor->id,
            'file_uploaded_at' => $stored === null ? null : now(),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(Supplier): T  $write
     * @return T
     */
    private function whileOpen(Supplier $supplier, callable $write): mixed
    {
        return DB::transaction(function () use ($supplier, $write): mixed {
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if ($locked->isEnded()) {
                throw ValidationException::withMessages(['title' => __('procynia.supplier_management.validation.reopen_before_edit')]);
            }

            return $write($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fields(array $validated): array
    {
        $fields = [
            'title' => trim((string) $validated['title']),
            'standard' => $this->optional($validated['standard'] ?? null),
            'location' => $this->optional($validated['location'] ?? null),
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'comment' => $this->optional($validated['comment'] ?? null),
        ];

        if (array_key_exists('document_type', $validated)) {
            $fields['document_type'] = (string) $validated['document_type'];
        }

        return $fields;
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
