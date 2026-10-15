<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A thing that can be measured (chest, sleeve length, posture note…) — SRS FR-MEA-001/003. */
class MeasurementParameter extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'length'  => 'Length / circumference',
        'number'  => 'Number',
        'text'    => 'Text note',
        'select'  => 'Choice from a list',
        'boolean' => 'Yes / no',
    ];

    public const AREAS = ['upper' => 'Upper body', 'lower' => 'Lower body', 'full' => 'Full body', 'other' => 'Other'];

    protected $fillable = [
        'tenant_id', 'code', 'label', 'body_area', 'data_type', 'is_bilateral', 'options', 'min_mm', 'max_mm',
        'change_threshold_mm', 'description', 'instructions', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_bilateral' => 'boolean',
        'is_active'    => 'boolean',
        'options'      => 'array',
        'min_mm'       => 'float',
        'max_mm'       => 'float',
        'change_threshold_mm' => 'float',
    ];

    /** Fields copied into a template version when it is published. */
    public function snapshot(): array
    {
        return $this->only(['code', 'label', 'data_type', 'is_bilateral', 'options', 'min_mm', 'max_mm', 'change_threshold_mm', 'instructions']);
    }
}
