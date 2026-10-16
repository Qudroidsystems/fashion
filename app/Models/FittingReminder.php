<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FittingReminder extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'fitting_id', 'remind_at', 'hours_before', 'status', 'sent_at'];

    protected $casts = ['remind_at' => 'datetime', 'sent_at' => 'datetime'];

    public function fitting(): BelongsTo
    {
        return $this->belongsTo(Fitting::class);
    }
}
