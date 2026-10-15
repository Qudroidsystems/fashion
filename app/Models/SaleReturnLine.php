<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sale_return_id', 'sale_line_id', 'quantity', 'amount'];

    protected $casts = ['quantity' => 'float', 'amount' => 'float'];

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }
}
