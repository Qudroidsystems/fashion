<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shopper's review of a product; shown once the house approves it. */
class ProductReview extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'account_id', 'name', 'email', 'rating', 'title', 'body', 'verified_buyer', 'status', 'reply', 'moderated_by'];

    protected $casts = ['rating' => 'integer', 'verified_buyer' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(StoreAccount::class, 'account_id');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }

    /** Recalculate the product's average and count from approved reviews. */
    public static function refreshProduct(int $productId): void
    {
        $row = static::where('product_id', $productId)->approved()->selectRaw('count(*) c, coalesce(avg(rating),0) a')->first();
        Product::whereKey($productId)->update(['rating_count' => (int) $row->c, 'rating_avg' => round((float) $row->a, 2)]);
    }
}
