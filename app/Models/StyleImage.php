<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A picture of a style version. Rows are copied into new drafts; files are shared until unused. */
class StyleImage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'style_version_id', 'path', 'caption', 'sort_order'];

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->path);
    }
}
