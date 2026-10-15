<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SaleTender extends Model
{
    use BelongsToTenant;

    public const METHODS = ['cash' => 'Cash', 'card' => 'Card / POS terminal', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money'];

    protected $fillable = ['tenant_id', 'sale_id', 'method', 'amount', 'received', 'reference'];

    protected $casts = ['amount' => 'float', 'received' => 'float'];

    public function getMethodLabelAttribute(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
