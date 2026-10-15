<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods brought back against an earlier sale (SRS FR-POS-004). */
class SaleReturn extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['tenant_id', 'number', 'sale_id', 'shift_id', 'refund_method', 'amount', 'restock', 'reason', 'user_id'];

    protected $casts = ['amount' => 'float', 'restock' => 'boolean'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
