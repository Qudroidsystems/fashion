<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A saved delivery address of a shopper. */
class StoreAddress extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'account_id', 'label', 'name', 'phone', 'line1', 'line2', 'city', 'state', 'country', 'postal_code', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function getOneLineAttribute(): string
    {
        return collect([$this->line1, $this->line2, $this->city, $this->state, $this->country])->filter()->implode(', ');
    }
}
