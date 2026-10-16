<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone being dressed for a project (SRS FR-PRJ-003). */
class ProjectParticipant extends Model
{
    use BelongsToTenant;

    public const PAYS = ['client' => 'Client pays', 'self' => 'Pays for themselves'];

    protected $fillable = [
        'tenant_id', 'project_id', 'customer_id', 'name', 'phone', 'email', 'role', 'group_name', 'pays', 'status', 'notes', 'sort_order',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Garments: order lines for this person, on whichever order pays for them. */
    public function garments(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'participant_id');
    }
}
