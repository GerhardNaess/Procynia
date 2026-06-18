<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeDocumentCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'name',
        'description',
        'sort_order',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeDocumentTopic::class, 'knowledge_document_category_topic')
            ->withTimestamps();
    }

    public function scopeForCustomer(Builder $query, Customer|int $customer): Builder
    {
        $customerId = $customer instanceof Customer ? $customer->getKey() : $customer;

        return $query->where('customer_id', $customerId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('LOWER(name)')
            ->orderBy('id');
    }
}
