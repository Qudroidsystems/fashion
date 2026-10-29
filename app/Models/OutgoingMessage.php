<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A message waiting to go to a customer (or already sent), with what happened on each channel. */
class OutgoingMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'purpose', 'related_type', 'related_id', 'subject', 'body', 'channels', 'first_only',
        'status', 'attempts', 'send_after', 'sent_at', 'result', 'dedupe_key', 'created_by',
    ];

    protected $casts = ['channels' => 'array', 'first_only' => 'boolean', 'send_after' => 'datetime', 'sent_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }
}
