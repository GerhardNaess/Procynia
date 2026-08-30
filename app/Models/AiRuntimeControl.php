<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Singleton runtime safety control. A missing row is treated as stopped by the guard. */
class AiRuntimeControl extends Model
{
    protected $fillable = ['global_ai_stop', 'changed_by_user_id', 'reason'];

    protected function casts(): array { return ['global_ai_stop' => 'boolean', 'changed_by_user_id' => 'integer']; }

    public function changedByUser(): BelongsTo { return $this->belongsTo(User::class, 'changed_by_user_id'); }
}
