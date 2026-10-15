<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Moves stock between two locations of the house (SRS FR-INV-006). */
class StockTransfer extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['tenant_id', 'number', 'from_branch_id', 'to_branch_id', 'notes', 'created_by', 'transferred_at'];

    protected $casts = ['transferred_at' => 'datetime'];

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
