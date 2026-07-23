<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseWikiPageVersion extends Model
{
    protected $fillable = [
        'enterprise_wiki_page_id',
        'version_number',
        'is_current',
        'is_staged',
        'content_markdown',
        'content_blocks_json',
        'generated_by_model',
        'generation_prompt_hash',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'is_current' => 'boolean',
            'is_staged' => 'boolean',
            'content_blocks_json' => 'array',
            'created_by_user_id' => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaim::class, 'enterprise_wiki_page_version_id')
            ->orderBy('position_order');
    }

    public function documentOwnerApprovals(): HasMany
    {
        return $this->hasMany(EnterpriseWikiPageVersionDocumentOwnerApproval::class, 'enterprise_wiki_page_version_id');
    }
}
