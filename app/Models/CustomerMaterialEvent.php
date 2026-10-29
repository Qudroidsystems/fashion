<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What happened to a customer's material, and who did it. */
class CustomerMaterialEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const LABELS = [
        'received' => 'Received', 'moved' => 'Moved', 'in_use' => 'Taken to the workroom', 'back_in_store' => 'Back in store',
        'returned' => 'Returned to the customer', 'used' => 'Used up', 'written_off' => 'Written off', 'reminded' => 'Customer reminded',
        'linked' => 'Linked to an order', 'note' => 'Note', 'photo' => 'Photo added', 'updated' => 'Details changed',
    ];

    protected $fillable = ['tenant_id', 'customer_material_id', 'event', 'detail', 'meta', 'user_id', 'created_at'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLabelAttribute(): string
    {
        return self::LABELS[$this->event] ?? ucfirst($this->event);
    }
}
