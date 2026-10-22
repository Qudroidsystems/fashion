<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Homepage slide (hero) or promotion banner on the shop. */
class StoreBanner extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'placement', 'eyebrow', 'title', 'subtitle', 'button_text', 'link', 'image_path', 'text_colour',
        'sort_order', 'is_active', 'starts_at', 'ends_at'];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function scopeLive($q, string $placement)
    {
        return $q->where('placement', $placement)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function getImageUrlAttribute(): string
    {
        return asset('storage/' . $this->image_path);
    }
}
