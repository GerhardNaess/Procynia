<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An external application registered to call the Procynia API — for example Siggjo.
 *
 * Product-neutral: Siggjo is one row. The application is identified by its Entra application id
 * and home tenant; Procynia stores no credential for it (D5).
 *
 * @property int $id
 * @property string $name
 * @property string $entra_app_id
 * @property string $home_tenant_id
 * @property bool $is_enabled
 */
class ApiClient extends Model
{
    protected $fillable = [
        'name',
        'description',
        'entra_app_id',
        'home_tenant_id',
        'is_enabled',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
