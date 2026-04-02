<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'saved_notice_id',
        'sequence_number',
        'label',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public static function defaultLabelForSequence(int $sequenceNumber): string
    {
        if ($sequenceNumber < 1) {
            throw new \InvalidArgumentException("Unsupported bid submission sequence [{$sequenceNumber}].");
        }

        if ($sequenceNumber === 1) {
            return 'Initial Submission';
        }

        return 'Revised Submission '.($sequenceNumber - 1);
    }
}
