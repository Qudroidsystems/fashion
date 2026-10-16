<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionStage extends Model
{
    use BelongsToTenant;

    public const TYPES = ['work' => 'Work', 'fitting' => 'Fitting', 'qc' => 'Quality check'];

    protected $fillable = [
        'tenant_id', 'workflow_id', 'name', 'type', 'position', 'duration_hours', 'role_id', 'requires_previous',
        'customer_visible', 'checklist_id', 'instructions',
    ];

    protected $casts = ['requires_previous' => 'boolean', 'customer_visible' => 'boolean', 'duration_hours' => 'integer', 'position' => 'integer'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ProductionWorkflow::class, 'workflow_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(QualityChecklist::class, 'checklist_id');
    }
}
