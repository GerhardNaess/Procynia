<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file that belongs to a styrende dokument.
 *
 * Separate from QualityItemWikiLink on purpose. A Wiki link reaches the knowledge the virksomhet
 * has written down about a subject; this reaches a file — the signed policy, the form the procedure
 * hands out, the completed control sheet. Both may point at the same subject matter without being
 * the same statement, so they have separate vocabularies and separate sections in the UI.
 *
 * The file itself is an EnterpriseWikiDocument: the virksomhet's one uploaded-file store, reused
 * rather than duplicated. See the migration for why. Removing this row removes the connection and
 * nothing else — the file stays where it is, still attached to every other item that uses it.
 *
 * Evidence on a control is the one capacity that may exist without a file: it carries its own
 * title, and the file is optional. Every other capacity is a statement about a file.
 *
 * Evidence is also history: when its file is deleted, the evidence stays and only loses the file
 * (see releaseEvidenceFromDocument()). Every other capacity goes with the file, by FK cascade.
 */
class QualityItemDocument extends Model
{
    /** The file is the document — the signed policy, the written procedure. */
    public const RELATION_TYPE_SOURCE = 'source';

    /** A form or template the work is carried out with. */
    public const RELATION_TYPE_TEMPLATE = 'template';

    /** A record that the work described actually happened. */
    public const RELATION_TYPE_EVIDENCE = 'evidence';

    /** Anything else that belongs with the document without being any of the above. */
    public const RELATION_TYPE_ATTACHMENT = 'attachment';

    /**
     * A verktøy from the Kvalitet library that a control is carried out with. Only ever written by
     * linking a QualityTool, never chosen in the general document form — see linkTool().
     */
    public const RELATION_TYPE_TOOL = 'tool';

    /** @var list<string> */
    public const RELATION_TYPES = [
        self::RELATION_TYPE_SOURCE,
        self::RELATION_TYPE_TEMPLATE,
        self::RELATION_TYPE_EVIDENCE,
        self::RELATION_TYPE_ATTACHMENT,
        self::RELATION_TYPE_TOOL,
    ];

    /**
     * The capacities the general Dokumenter form offers. Evidence and tools have their own sections
     * on a control, with their own forms, so they are written there and nowhere else.
     *
     * @var list<string>
     */
    public const GENERAL_RELATION_TYPES = [
        self::RELATION_TYPE_SOURCE,
        self::RELATION_TYPE_TEMPLATE,
        self::RELATION_TYPE_EVIDENCE,
        self::RELATION_TYPE_ATTACHMENT,
    ];

    /** A person made this connection. */
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'enterprise_wiki_document_id',
        'document_removed_at',
        'relation_type',
        'title',
        'note',
        'source',
        'created_by_user_id',
    ];

    protected $casts = [
        'document_removed_at' => 'datetime',
    ];

    /**
     * Detach evidence from a file that is about to be deleted, so the FK cascade leaves it standing.
     *
     * Name, description, author and timestamp are kept. Evidence attached before it had a name of
     * its own takes the file's name, which is also what the check constraint requires of a
     * file-less row. Must run before the document row is deleted, in the same transaction.
     */
    public static function releaseEvidenceFromDocument(EnterpriseWikiDocument $document): int
    {
        $evidence = static::query()
            ->where('enterprise_wiki_document_id', $document->id)
            ->where('relation_type', self::RELATION_TYPE_EVIDENCE);

        (clone $evidence)->whereNull('title')->update(['title' => (string) $document->original_filename]);

        return $evidence->update([
            'enterprise_wiki_document_id' => null,
            'document_removed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiDocument::class, 'enterprise_wiki_document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
