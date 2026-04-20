<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNotice;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RequirementAnswerBasisService
{
    public function __construct(
        private readonly DocumentTextExtractor $documentTextExtractor,
    ) {
    }

    /**
     * Purpose: Create one or more document-based answer basis items for a visible AI case.
     * Inputs: The saved notice, uploaded files, and optional creator user.
     * Returns: The persisted answer basis item collection.
     * Side effects: Stores uploaded files and extracts text for generation use.
     */
    public function createDocumentItems(
        SavedNotice $savedNotice,
        array $files,
        ?User $createdByUser,
    ): Collection {
        $createdItems = new Collection();

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $createdItems->push($this->createDocumentItem($savedNotice, $file, $createdByUser));
        }

        return $createdItems;
    }

    /**
     * Purpose: Create one text-based answer basis item for a visible AI case.
     * Inputs: The saved notice, title, body text, and optional creator user.
     * Returns: The persisted answer basis item.
     * Side effects: Stores a new answer basis row in the database.
     */
    public function createTextItem(
        SavedNotice $savedNotice,
        string $title,
        string $bodyText,
        ?User $createdByUser,
    ): SavedNoticeAiAnswerBasisItem {
        $normalizedTitle = $this->normalizeTitle($title);
        $normalizedBodyText = $this->normalizeBodyText($bodyText);

        if ($normalizedTitle === '') {
            throw new RuntimeException('Answer basis title cannot be empty.');
        }

        if ($normalizedBodyText === '') {
            throw new RuntimeException('Answer basis text cannot be empty.');
        }

        return DB::transaction(function () use ($savedNotice, $createdByUser, $normalizedTitle, $normalizedBodyText): SavedNoticeAiAnswerBasisItem {
            return SavedNoticeAiAnswerBasisItem::query()->create([
                'saved_notice_id' => $savedNotice->id,
                'created_by_user_id' => $createdByUser?->id,
                'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT,
                'title' => $normalizedTitle,
                'original_filename' => null,
                'body_text' => $normalizedBodyText,
                'stored_path' => null,
                'mime_type' => null,
                'file_size_bytes' => null,
            ]);
        });
    }

    /**
     * Purpose: Remove one answer basis item and its stored source file when applicable.
     * Inputs: The answer basis item to delete.
     * Returns: None.
     * Side effects: Removes database rows and deletes the stored file when present.
     */
    public function deleteItem(SavedNoticeAiAnswerBasisItem $answerBasisItem): void
    {
        $storedPath = (string) ($answerBasisItem->stored_path ?? '');

        DB::transaction(function () use ($answerBasisItem): void {
            $answerBasisItem->delete();
        });

        if ($storedPath !== '' && Storage::disk('local')->exists($storedPath)) {
            Storage::disk('local')->delete($storedPath);
        }
    }

    /**
     * Purpose: Synchronize the selected answer basis items for one requirement.
     * Inputs: The requirement and the selected basis item ids.
     * Returns: The canonical selected basis item collection.
     * Side effects: Updates the requirement-to-basis selection pivot rows.
     */
    public function syncRequirementSelection(
        SavedNoticeAiRequirement $requirement,
        array $answerBasisItemIds,
    ): Collection {
        $normalizedIds = $this->normalizeSelectedIds($answerBasisItemIds);

        $selectedItemIds = SavedNoticeAiAnswerBasisItem::query()
            ->where('saved_notice_id', $requirement->saved_notice_id)
            ->whereIn('id', $normalizedIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        $selectedItemIds = collect($selectedItemIds)
            ->map(static fn (mixed $value): int => (int) $value)
            ->values()
            ->all();

        DB::transaction(function () use ($requirement, $selectedItemIds): void {
            $requirement->answerBasisItems()->sync($selectedItemIds);
        });

        if ($selectedItemIds === []) {
            return new Collection();
        }

        $orderMap = array_flip($selectedItemIds);

        return SavedNoticeAiAnswerBasisItem::query()
            ->where('saved_notice_id', $requirement->saved_notice_id)
            ->whereIn('id', $selectedItemIds)
            ->get()
            ->sortBy(static fn (SavedNoticeAiAnswerBasisItem $item): int => $orderMap[$item->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Purpose: Create one document-based answer basis item.
     * Inputs: The saved notice, uploaded file, and optional creator user.
     * Returns: The persisted answer basis item.
     * Side effects: Stores the uploaded file and extracts text for generation use.
     */
    private function createDocumentItem(
        SavedNotice $savedNotice,
        UploadedFile $file,
        ?User $createdByUser,
    ): SavedNoticeAiAnswerBasisItem {
        $originalFilename = $file->getClientOriginalName();
        $extension = Str::lower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'));
        $storedFilename = Str::ulid().'.'.$extension;
        $storedPath = Storage::disk('local')->putFileAs(
            sprintf('saved-notices/%d/ai-answer-basis-items', $savedNotice->id),
            $file,
            $storedFilename,
        );

        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('Failed to store answer basis document.');
        }

        try {
            $bodyText = $this->normalizeBodyText(
                (string) $this->documentTextExtractor->extractText(Storage::disk('local')->path($storedPath)),
            );

            if ($bodyText === '') {
                throw new RuntimeException('Answer basis document did not yield any extractable text.');
            }

            return DB::transaction(function () use ($savedNotice, $createdByUser, $originalFilename, $storedPath, $file, $bodyText): SavedNoticeAiAnswerBasisItem {
                return SavedNoticeAiAnswerBasisItem::query()->create([
                    'saved_notice_id' => $savedNotice->id,
                    'created_by_user_id' => $createdByUser?->id,
                    'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_DOCUMENT,
                    'title' => $originalFilename,
                    'original_filename' => $originalFilename,
                    'body_text' => $bodyText,
                    'stored_path' => $storedPath,
                    'mime_type' => $file->getClientMimeType(),
                    'file_size_bytes' => (int) $file->getSize(),
                ]);
            });
        } catch (Throwable $exception) {
            if (Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    /**
     * Purpose: Normalize user-provided answer basis title text.
     * Inputs: Raw title text.
     * Returns: Cleaned text with canonical whitespace.
     * Side effects: None.
     */
    private function normalizeTitle(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Purpose: Normalize user-provided answer basis body text.
     * Inputs: Raw body text.
     * Returns: Cleaned text with canonical line endings and trimmed whitespace.
     * Side effects: None.
     */
    private function normalizeBodyText(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);

        return trim($normalized);
    }

    /**
     * Purpose: Normalize selected answer basis item identifiers.
     * Inputs: The raw submitted id list.
     * Returns: A unique, positive integer id list.
     * Side effects: None.
     */
    private function normalizeSelectedIds(array $answerBasisItemIds): array
    {
        return collect($answerBasisItemIds)
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }
}
