<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'invoice_id', 'order_line_id', 'description', 'quantity', 'unit_price', 'line_total'];

    protected $casts = ['quantity' => 'float', 'unit_price' => 'float', 'line_total' => 'float'];
}
