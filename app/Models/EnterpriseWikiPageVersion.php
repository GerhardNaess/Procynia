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
        'content_markdown',
        'generated_by_model',
        'generation_prompt_hash',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaim::class, 'enterprise_wiki_page_version_id')
            ->orderBy('position_order');
    }
}
