<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One value in a session; lengths in canonical millimetres (SRS §7.2). Scoped through its session. */
class MeasurementValue extends Model
{
    protected $fillable = [
        'session_id', 'template_parameter_id', 'parameter_code', 'side', 'value_mm', 'number_value',
        'original_value', 'original_unit', 'text_value', 'validation_state', 'note',
    ];

    protected $casts = [
        'value_mm'       => 'float',
        'number_value'   => 'float',
        'original_value' => 'float',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(MeasurementSession::class, 'session_id');
    }

    public function templateParameter(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplateParameter::class, 'template_parameter_id');
    }

    public function key(): string
    {
        return $this->parameter_code . ':' . $this->side;
    }
}
