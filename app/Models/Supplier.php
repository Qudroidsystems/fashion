<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone the house buys fabric and materials from (SRS FR-INV-005). */
class Supplier extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days',
        'bank_details', 'notes', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch($q, ?string $term)
    {
        $term = trim((string) $term);

        return $term === '' ? $q : $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('contact_person', 'like', "%{$term}%"));
    }
}
