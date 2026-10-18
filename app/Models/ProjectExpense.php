<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money spent on a project outside the stock ledger (stock issued is counted separately). */
class ProjectExpense extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'category', 'description', 'amount', 'spent_on', 'paid_to', 'receipt_path', 'recorded_by'];

    protected $casts = ['amount' => 'float', 'spent_on' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
