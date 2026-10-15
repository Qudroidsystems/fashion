<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A bespoke design the house makes to measure (SRS FR-MEA-006). Content lives in versions. */
class FashionStyle extends Model
{
    use BelongsToTenant, HasUlids;

    public const AUDIENCES = ['any' => 'Anyone', 'female' => 'Women', 'male' => 'Men', 'child' => 'Children'];

    protected $fillable = ['tenant_id', 'code', 'name', 'category_id', 'audience', 'status', 'show_in_catalogue', 'current_version_id', 'created_by'];

    protected $casts = ['show_in_catalogue' => 'boolean'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogueCategory::class, 'category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StyleVersion::class, 'style_id')->orderByDesc('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(StyleVersion::class, 'current_version_id');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(StyleVersion::class, 'style_id')->where('status', 'draft');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'style_id');
    }

    /** Published, active and switched on for the customer catalogue. */
    public function scopeInCatalogue($q)
    {
        return $q->where('status', 'active')->where('show_in_catalogue', true)->whereNotNull('current_version_id');
    }

    public function scopeSearch($q, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
    }

    /** Version shown on cards: the published one, else the draft. */
    public function displayVersion(): ?StyleVersion
    {
        return $this->relationLoaded('currentVersion') && $this->currentVersion ? $this->currentVersion
            : ($this->currentVersion ?? $this->draft);
    }
}
