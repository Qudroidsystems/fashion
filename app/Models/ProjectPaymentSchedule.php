<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** When the client agreed to pay what (§6.4 step 23). Paid status is worked out from payments. */
class ProjectPaymentSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'label', 'due_date', 'amount', 'sort_order'];

    protected $casts = ['due_date' => 'date', 'amount' => 'float'];
}
