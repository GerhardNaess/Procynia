<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeMetadataTerm extends Model
{
    public const TYPE_SERVICE_PRODUCT_TAG = 'service_product_tag';

    public const TYPE_THEME_TAG = 'theme_tag';

    public const TYPE_TOPIC = 'topic';

    public const TYPE_SUB_TOPIC = 'sub_topic';

    public const TYPE_KEYWORDS = 'keywords';

    public const TYPE_KEYWORD = 'keyword';

    public const TYPE_ROLE = 'role';

    public const TYPE_PROCESS = 'process';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_TECHNOLOGY = 'technology';

    public const TYPE_DOCUMENT_TYPE = 'document_type';

    public const TYPE_INDUSTRY = 'industry';

    public const TYPE_ALIASES = [
        self::TYPE_KEYWORD => self::TYPE_KEYWORDS,
    ];

    public const TYPES = [
        self::TYPE_SERVICE_PRODUCT_TAG,
        self::TYPE_THEME_TAG,
        self::TYPE_TOPIC,
        self::TYPE_SUB_TOPIC,
        self::TYPE_KEYWORDS,
        self::TYPE_ROLE,
        self::TYPE_PROCESS,
        self::TYPE_SYSTEM,
        self::TYPE_TECHNOLOGY,
        self::TYPE_DOCUMENT_TYPE,
        self::TYPE_INDUSTRY,
    ];

    public const TYPE_LABELS = [
        self::TYPE_SERVICE_PRODUCT_TAG => 'Tjeneste / produkt',
        self::TYPE_THEME_TAG => 'Tema',
        self::TYPE_TOPIC => 'Emne',
        self::TYPE_SUB_TOPIC => 'Underemne',
        self::TYPE_KEYWORDS => 'Nøkkelord',
        self::TYPE_ROLE => 'Rolle',
        self::TYPE_PROCESS => 'Prosess',
        self::TYPE_SYSTEM => 'System',
        self::TYPE_TECHNOLOGY => 'Teknologi',
        self::TYPE_DOCUMENT_TYPE => 'Dokumenttype',
        self::TYPE_INDUSTRY => 'Bransje',
    ];

    protected $fillable = [
        'customer_id',
        'type',
        'canonical_name',
        'synonyms',
        'description',
        'approved',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'synonyms' => 'array',
            'approved' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
