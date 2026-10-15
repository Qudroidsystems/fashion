<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Material handed to production for a job (SRS FR-INV-006, FR-PRD-…, scenario 38). */
class MaterialIssue extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'number', 'branch_id', 'customer_id', 'order_id', 'project_id', 'job_reference',
        'issued_to', 'issued_by', 'issued_at', 'total_cost', 'notes',
    ];

    protected $casts = ['issued_at' => 'datetime', 'total_cost' => 'float'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialIssueLine::class);
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
