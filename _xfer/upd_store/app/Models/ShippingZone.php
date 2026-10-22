<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Delivery fee for a group of states (no states = everywhere not covered by another zone). */
class ShippingZone extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'states', 'fee', 'free_over', 'eta', 'is_active', 'sort_order'];

    protected $casts = ['states' => 'array', 'fee' => 'float', 'free_over' => 'float', 'is_active' => 'boolean'];

    public function covers(?string $state): bool
    {
        $states = array_map(fn ($s) => mb_strtolower(trim($s)), (array) $this->states);

        return $states === [] || ($state !== null && in_array(mb_strtolower(trim($state)), $states, true));
    }

    /** Fee for this subtotal (free above the threshold). */
    public function feeFor(float $subtotal): float
    {
        return $this->free_over !== null && $this->free_over > 0 && $subtotal >= $this->free_over ? 0.0 : round((float) $this->fee, 2);
    }
}
