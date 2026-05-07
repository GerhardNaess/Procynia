<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLog extends Model
{
    protected $fillable = [
        'customer_id',
        'stripe_invoice_id',
        'status',
        'amount_paid',
        'currency',
        'line_items',
        'invoice_date',
    ];

    protected function casts(): array
    {
        return [
            'line_items' => 'array',
            'invoice_date' => 'datetime',
            'amount_paid' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function amountFormatted(): string
    {
        return number_format($this->amount_paid / 100, 0, ',', ' ') . ' ' . strtoupper($this->currency);
    }
}
