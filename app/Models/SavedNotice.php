<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedNotice extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'external_id',
        'title',
        'buyer_name',
        'external_url',
        'summary',
        'publication_date',
        'deadline',
        'status',
        'cpv_code',
    ];

    protected $casts = [
        'publication_date' => 'datetime',
        'deadline' => 'datetime',
    ];
}
