<?php

namespace App\Models;

use App\Support\Measurements\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parameter inside a template version, with a snapshot of its definition.
 * Tenant-scoped through its version (FR-TEN-002).
 */
class MeasurementTemplateParameter extends Model
{
    protected $fillable = [
        'template_version_id', 'parameter_id', 'code', 'label', 'data_type', 'is_bilateral', 'options',
        'min_mm', 'max_mm', 'change_threshold_mm', 'instructions', 'is_required', 'sort_order', 'guidance',
    ];

    protected $casts = [
        'is_bilateral' => 'boolean',
        'is_required'  => 'boolean',
        'options'      => 'array',
        'min_mm'       => 'float',
        'max_mm'       => 'float',
        'change_threshold_mm' => 'float',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplateVersion::class, 'template_version_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(MeasurementParameter::class);
    }

    public function isLength(): bool
    {
        return $this->data_type === 'length';
    }

    /** ['both'] or ['left', 'right']. */
    public function sides(): array
    {
        return $this->is_bilateral ? ['left', 'right'] : ['both'];
    }

    public function rangeText(string $unit): ?string
    {
        if (! $this->isLength() || ($this->min_mm === null && $this->max_mm === null)) {
            return null;
        }

        return trim(($this->min_mm !== null ? Units::format($this->min_mm, $unit) : '…') . ' – ' . ($this->max_mm !== null ? Units::format($this->max_mm, $unit) : '…') . ' ' . $unit);
    }
}
