<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verktøy: a document in the virksomhet's archive that helps somebody carry out a control right.
 *
 * The file is an EnterpriseWikiDocument and stays one — this row only says what the file is FOR.
 * Where it is used is a QualityItemDocument in the `tool` capacity, on the control, pointing at the
 * same file. See the migration for why the two halves live where they do.
 */
class QualityTool extends Model
{
    /** @var list<string> */
    public const CATEGORIES = [
        'guide',
        'checklist',
        'template',
        'method',
        'standard_text',
        'reference',
    ];

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_document_id',
        'title',
        'description',
        'category',
        'created_by_user_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
