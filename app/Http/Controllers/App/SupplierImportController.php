<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\User;
use App\Services\Suppliers\Import\StaleSupplierImportException;
use App\Services\Suppliers\Import\SupplierImportFileException;
use App\Services\Suppliers\Import\SupplierImportReader;
use App\Services\Suppliers\Import\SupplierImportService;
use App\Services\Suppliers\Import\SupplierImportTemplate;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Leverandører → Importer leverandører: Last opp → Kontroller → Bekreft
 * (docs/supplier-management-v1-plan.md, «Excel-import av leverandører»).
 *
 * supplier.edit, as for «Registrer leverandør» — importing registers and changes suppliers, and grants
 * nothing more. The route guard has already refused a customer without the `supplier` module; a user
 * without supplier.edit gets a 403 on every step. An import is the person's own: another person's,
 * or another customer's, is a 404. Nothing reaches the register before the person confirms.
 */
class SupplierImportController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierImportService $imports,
    ) {}

    public function create(): Response
    {
        $this->authorizedUser();

        return $this->page(null);
    }

    /** The empty template, in the person's language. */
    public function template(SupplierImportTemplate $template): BinaryFileResponse
    {
        $this->authorizedUser();

        return response()
            ->download($template->write(), $template->fileName(), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ])
            ->setPrivate()
            ->deleteFileAfterSend();
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        $messages = [
            'file.required' => __('procynia.supplier_management.import.file_errors.required'),
            'file.file' => __('procynia.supplier_management.import.file_errors.not_xlsx'),
            'file.uploaded' => __('procynia.supplier_management.import.file_errors.too_large', ['max' => SupplierImportReader::MAX_KILOBYTES / 1024]),
            'file.max' => __('procynia.supplier_management.import.file_errors.too_large', ['max' => SupplierImportReader::MAX_KILOBYTES / 1024]),
        ];

        $request->validate([
            'file' => ['required', 'file', 'max:'.SupplierImportReader::MAX_KILOBYTES],
        ], $messages + SupplierValidationMessages::messages());

        try {
            $import = $this->imports->upload($user, $request->file('file'));
        } catch (SupplierImportFileException $exception) {
            throw ValidationException::withMessages(['file' => $exception->userMessage()]);
        }

        return redirect()->route('app.supplier-management.import.show', ['importId' => $import->id]);
    }

    public function show(int $importId): Response|RedirectResponse
    {
        $user = $this->authorizedUser();
        $import = $this->imports->find($user, $importId) ?? abort(404);

        if ($this->imports->isExpired($import)) {
            $this->imports->discard($import);

            return redirect()->route('app.supplier-management.import.create')
                ->with('error', __('procynia.supplier_management.import.flash.expired'));
        }

        return $this->page($import);
    }

    public function execute(Request $request, int $importId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $import = $this->imports->find($user, $importId) ?? abort(404);
        $validated = $request->validate([
            'update_existing' => ['required', 'boolean'],
            'preview_hash' => ['required', 'string', 'max:128'],
        ], SupplierValidationMessages::messages());

        if ($this->imports->isExpired($import)) {
            $this->imports->discard($import);

            return redirect()->route('app.supplier-management.import.create')
                ->with('error', __('procynia.supplier_management.import.flash.expired'));
        }

        $wasCompleted = $import->isCompleted();

        try {
            $import = $this->imports->execute($user, $importId, (bool) $validated['update_existing'], $validated['preview_hash']);
        } catch (StaleSupplierImportException) {
            return redirect()->route('app.supplier-management.import.show', ['importId' => $importId])
                ->with('error', __('procynia.supplier_management.import.flash.stale'));
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // The whole import was rolled back and is still pending: nothing half-done, and it can be
            // tried again. What went wrong is logged, never shown.
            report($exception);

            return redirect()->route('app.supplier-management.import.show', ['importId' => $importId])
                ->with('error', __('procynia.supplier_management.import.flash.failed'));
        }

        return redirect()->route('app.supplier-management.import.show', ['importId' => $import->id])
            ->with('success', __($wasCompleted ? 'procynia.supplier_management.import.flash.already_completed' : 'procynia.supplier_management.import.flash.completed'));
    }

    public function destroy(int $importId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $import = $this->imports->find($user, $importId) ?? abort(404);
        $this->imports->discard($import);

        return redirect()->route('app.supplier-management.import.create');
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($user instanceof User && $this->access->canEdit($user), 403);

        return $user;
    }

    private function page(?SupplierImport $import): Response
    {
        $user = $this->authorizedUser();
        $payload = null;

        if ($import !== null && ! $import->isCompleted()) {
            $preview = $this->imports->preview($user, $import);
            $payload = [
                'id' => (int) $import->id,
                'status' => $import->status,
                'file_name' => $import->file_name,
                'ignored_columns' => $import->ignored_columns ?? [],
                // What the page shows; what would be written stays on the server.
                'rows' => array_map(fn (array $row): array => array_diff_key($row, ['write' => true]), $preview['rows']),
                'summary' => $preview['summary'],
                'preview_hash' => $preview['hash'],
            ];
        }

        if ($import !== null && $import->isCompleted()) {
            $result = $import->result ?? [];
            // A link only to a supplier the person can still open.
            $ids = array_values(array_filter(array_column($result['rows'] ?? [], 'supplier_id')));
            $visible = array_flip($this->access->visibleSuppliers($user)->whereIn('suppliers.id', $ids)->pluck('suppliers.id')->map(fn ($id): int => (int) $id)->all());

            $payload = [
                'id' => (int) $import->id,
                'status' => $import->status,
                'file_name' => $import->file_name,
                'update_existing' => (bool) $import->update_existing,
                'completed_at' => $import->completed_at?->toIso8601String(),
                'completed_by_name' => $import->completedBy?->name,
                'result' => [
                    'created' => (int) ($result['created'] ?? 0),
                    'updated' => (int) ($result['updated'] ?? 0),
                    'skipped' => (int) ($result['skipped'] ?? 0),
                    'rejected' => (int) ($result['rejected'] ?? 0),
                    'rows' => array_map(fn (array $row): array => $row + [
                        'url' => isset($row['supplier_id'], $visible[(int) $row['supplier_id']])
                            ? route('app.supplier-management.show', ['supplierId' => $row['supplier_id']], false)
                            : null,
                    ], $result['rows'] ?? []),
                ],
            ];
        }

        return Inertia::render('App/SupplierManagement/Import', [
            'import' => $payload,
            'limits' => [
                'max_rows' => SupplierImportReader::MAX_ROWS,
                'max_megabytes' => SupplierImportReader::MAX_KILOBYTES / 1024,
            ],
            'template_url' => route('app.supplier-management.import.template', [], false),
            'categories' => Supplier::CATEGORIES,
        ]);
    }
}
