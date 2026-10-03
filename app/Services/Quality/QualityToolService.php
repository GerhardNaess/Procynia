<?php

namespace App\Services\Quality;

use App\Models\EnterpriseWikiDocument;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityTool;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Verktøy: the library of documents a control is carried out with, and the controls using each.
 *
 * Nothing here stores a file. A tool is a name and a purpose for a file already in the virksomhet's
 * document archive, and a use is a quality_item_documents row in the `tool` capacity — so removing a
 * use is the seam's ordinary unlink, which leaves the file and the library entry where they are.
 */
class QualityToolService
{
    /**
     * Put a file from the archive into the library.
     *
     * @param  array{title: string, description?: ?string, category?: ?string}  $attributes
     */
    public function register(
        int $customerId,
        EnterpriseWikiDocument $document,
        array $attributes,
        ?User $actor = null,
    ): QualityTool {
        if ((int) $document->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'enterprise_wiki_document_id' => __('procynia.quality.errors.document_not_found'),
            ]);
        }

        $title = trim((string) ($attributes['title'] ?? ''));

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('procynia.quality.errors.tool_title_required'),
            ]);
        }

        $category = $this->nullableText($attributes['category'] ?? null);

        if ($category !== null && ! in_array($category, QualityTool::CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'category' => __('procynia.quality.errors.unknown_tool_category'),
            ]);
        }

        // One entry per file. An upload of bytes the archive already holds lands here too, since
        // the upload service hands back the existing document rather than a copy.
        $alreadyTool = QualityTool::query()
            ->where('customer_id', $customerId)
            ->where('enterprise_wiki_document_id', $document->id)
            ->exists();

        if ($alreadyTool) {
            throw ValidationException::withMessages([
                'enterprise_wiki_document_id' => __('procynia.quality.errors.tool_document_already_registered'),
            ]);
        }

        return QualityTool::query()->create([
            'customer_id' => $customerId,
            'enterprise_wiki_document_id' => $document->id,
            'title' => $title,
            'description' => $this->nullableText($attributes['description'] ?? null),
            'category' => $category,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * Say that a control is carried out with a tool. Linking it twice is a no-op.
     */
    public function linkToControl(int $customerId, QualityItem $control, QualityTool $tool, ?User $actor = null): QualityItemDocument
    {
        if ((int) $control->customer_id !== $customerId || (int) $tool->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'quality_tool_id' => __('procynia.quality.errors.tool_not_found'),
            ]);
        }

        if ($control->quality_type !== QualityItem::TYPE_CONTROL) {
            throw ValidationException::withMessages([
                'quality_tool_id' => __('procynia.quality.errors.tool_requires_control'),
            ]);
        }

        return QualityItemDocument::query()->firstOrCreate(
            [
                'quality_item_id' => $control->id,
                'enterprise_wiki_document_id' => $tool->enterprise_wiki_document_id,
                'relation_type' => QualityItemDocument::RELATION_TYPE_TOOL,
            ],
            [
                'customer_id' => $customerId,
                'source' => QualityItemDocument::SOURCE_MANUAL,
                'created_by_user_id' => $actor?->id,
            ],
        );
    }

    /**
     * The whole library, each tool with the controls that use it.
     *
     * @return list<array<string, mixed>>
     */
    public function library(int $customerId): array
    {
        $tools = QualityTool::query()
            ->where('customer_id', $customerId)
            ->with('document:id,original_filename')
            ->get();

        $controlsByDocument = QualityItemDocument::query()
            ->where('quality_item_documents.customer_id', $customerId)
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_TOOL)
            ->whereIn('enterprise_wiki_document_id', $tools->pluck('enterprise_wiki_document_id'))
            ->with('item:id,title,code')
            ->get()
            ->filter(static fn (QualityItemDocument $use): bool => $use->item !== null)
            ->groupBy('enterprise_wiki_document_id');

        return $tools
            ->map(fn (QualityTool $tool): array => $this->row($tool) + [
                'controls' => ($controlsByDocument[(int) $tool->enterprise_wiki_document_id] ?? collect())
                    ->map(static fn (QualityItemDocument $use): array => [
                        'id' => (int) $use->item->id,
                        'title' => $use->item->title,
                        'code' => $use->item->code,
                        'url' => route('app.quality.items.show', ['item' => $use->item->id]),
                    ])
                    ->sortBy(static fn (array $control): string => mb_strtolower((string) $control['title']))
                    ->values()
                    ->all(),
            ])
            ->sortBy(static fn (array $row): string => mb_strtolower((string) $row['title']))
            ->values()
            ->all();
    }

    /**
     * The tools one control is carried out with. `link_id` is the seam row, which is what removal
     * deletes.
     *
     * @return list<array<string, mixed>>
     */
    public function forControl(int $customerId, QualityItem $control): array
    {
        $uses = QualityItemDocument::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $control->id)
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_TOOL)
            ->get(['id', 'enterprise_wiki_document_id']);

        $linkByDocument = $uses->pluck('id', 'enterprise_wiki_document_id');

        return $this->toolsForDocuments($customerId, $uses->pluck('enterprise_wiki_document_id'))
            ->map(fn (QualityTool $tool): array => $this->row($tool) + [
                'link_id' => (int) $linkByDocument[(int) $tool->enterprise_wiki_document_id],
            ])
            ->values()
            ->all();
    }

    /**
     * Library entries a control does not use yet, for the picker on the control.
     *
     * @return list<array{id: int, title: string, category: ?string}>
     */
    public function optionsForControl(int $customerId, QualityItem $control): array
    {
        $used = QualityItemDocument::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $control->id)
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_TOOL)
            ->pluck('enterprise_wiki_document_id');

        return QualityTool::query()
            ->where('customer_id', $customerId)
            ->whereNotIn('enterprise_wiki_document_id', $used)
            ->orderBy('title')
            ->get(['id', 'title', 'category'])
            ->map(static fn (QualityTool $tool): array => [
                'id' => (int) $tool->id,
                'title' => $tool->title,
                'category' => $tool->category,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $documentIds
     * @return Collection<int, QualityTool>
     */
    private function toolsForDocuments(int $customerId, Collection $documentIds): Collection
    {
        return QualityTool::query()
            ->where('customer_id', $customerId)
            ->whereIn('enterprise_wiki_document_id', $documentIds)
            ->with('document:id,original_filename')
            ->orderBy('title')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(QualityTool $tool): array
    {
        return [
            'id' => (int) $tool->id,
            'title' => $tool->title,
            'description' => $tool->description,
            'category' => $tool->category,
            'filename' => $tool->document?->original_filename,
            'open_url' => route('app.quality.tools.file', ['tool' => $tool->id]),
            'download_url' => route('app.quality.tools.file', ['tool' => $tool->id, 'download' => 1]),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }
}
