<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Planned spend by category (FR-PRJ-002 expenses/budget). */
class ProjectBudgetLine extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = [
        'fabric' => 'Fabric', 'accessories' => 'Accessories & trims', 'labour' => 'Outside labour', 'embellishment' => 'Beading & embellishment',
        'transport' => 'Transport & logistics', 'other' => 'Other',
    ];

    protected $fillable = ['tenant_id', 'project_id', 'category', 'description', 'amount'];

    protected $casts = ['amount' => 'float'];
}
