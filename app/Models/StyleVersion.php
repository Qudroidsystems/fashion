<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One numbered version of a style. Published versions are frozen (old orders stay reproducible). */
class StyleVersion extends Model
{
    use BelongsToTenant;

    public const CONTENT = [
        'description', 'technical_notes', 'base_price', 'price_options', 'estimated_cost',
        'lead_time_days', 'fabric_quantity', 'fabric_unit', 'fabric_notes',
    ];

    protected $fillable = [
        'tenant_id', 'style_id', 'version_no', 'status', 'description', 'technical_notes', 'base_price', 'price_options',
        'estimated_cost', 'lead_time_days', 'fabric_quantity', 'fabric_unit', 'fabric_notes', 'change_notes',
        'published_at', 'published_by', 'created_by',
    ];

    protected $casts = [
        'price_options'   => 'array',
        'base_price'      => 'decimal:2',
        'estimated_cost'  => 'decimal:2',
        'fabric_quantity' => 'decimal:2',
        'published_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (StyleVersion $v) {
            if ($v->getOriginal('status') !== 'draft') {
                $changed = array_diff(array_keys($v->getDirty()), ['status', 'updated_at']);
                if ($changed || ($v->isDirty('status') && $v->status !== 'retired')) {
                    throw new \LogicException('Published style versions are read-only. Create a new version instead.');
                }
            }
        });
        static::deleting(function (StyleVersion $v) {
            if ($v->status !== 'draft') {
                throw new \LogicException('Only draft style versions can be deleted.');
            }
        });
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(FashionStyle::class, 'style_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(StyleImage::class, 'style_version_id')->orderBy('sort_order')->orderBy('id');
    }

    public function templateLinks(): HasMany
    {
        return $this->hasMany(StyleTemplateLink::class, 'style_version_id')->orderBy('sort_order');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function cover(): ?StyleImage
    {
        return $this->images->first();
    }

    /** Price options cleaned up: [['label' => 'Lining', 'amount' => 5000.0], …]. */
    public function options(): array
    {
        return collect($this->price_options ?? [])
            ->filter(fn ($o) => trim((string) ($o['label'] ?? '')) !== '')
            ->map(fn ($o) => ['label' => trim($o['label']), 'amount' => (float) ($o['amount'] ?? 0)])
            ->values()->all();
    }

    public function getLabelAttribute(): string
    {
        return 'v' . $this->version_no . ($this->status === 'draft' ? ' (draft)' : '');
    }
}
