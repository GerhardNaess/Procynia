<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dokumentasjonsoversikt: the only writer of a SupplierDocument (docs/supplier-management-v1-plan.md
 * §4.4, §6.3).
 *
 * Registers, corrects, renews and deletes the description of a document — type, name, where it is
 * kept, how long it is valid, a comment. Never a file: location is stored as the text it was given
 * and nothing here, or anywhere else, fetches it. Never the Enterprise Wiki document store either.
 *
 * «Registrer fornyet» adds a new row of the same type and points the renewed row at it, in one
 * transaction. Only a row that has not been replaced already can be renewed, and the new row always
 * belongs to the same supplier — the database only knows the pointer is a row.
 *
 * An ended supplier is read-only: every write locks the supplier row and checks its status inside
 * the lock, so nothing lands on a supplier being ended at the same moment.
 *
 * Authorization is the caller's (supplier.edit through SupplierAccessService).
 */
class SupplierDocumentService
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(SupplierDocument::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:2000'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @param  array<string, mixed>  $validated */
    public function create(Supplier $supplier, User $actor, array $validated): SupplierDocument
    {
        return $this->whileOpen($supplier, fn (Supplier $locked): SupplierDocument => SupplierDocument::query()->create($this->fields($validated) + [
            'customer_id' => (int) $locked->customer_id,
            'supplier_id' => (int) $locked->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]));
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
    public function renew(SupplierDocument $document, User $actor, array $validated): SupplierDocument
    {
        return $this->whileOpen($document->supplier, function (Supplier $locked) use ($document, $actor, $validated): SupplierDocument {
            $old = SupplierDocument::query()
                ->whereKey($document->id)
                ->where('supplier_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($old->isReplaced()) {
                throw ValidationException::withMessages(['title' => __('procynia.supplier_management.validation.document_already_replaced')]);
            }

            $new = SupplierDocument::query()->create([
                'document_type' => $old->document_type,
            ] + $this->fields($validated) + [
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $old->forceFill(['replaced_by_document_id' => $new->id, 'updated_by' => $actor->id])->save();

            return $new;
        });
    }

    /**
     * Removes a row registered by mistake. A row it replaced points back to nothing and is current
     * again.
     */
    public function delete(SupplierDocument $document): void
    {
        $this->whileOpen($document->supplier, fn () => $document->delete());
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
