<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One message sent (or attempted) to a customer by e-mail, SMS or WhatsApp. */
class MessageLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

    protected $fillable = ['tenant_id', 'customer_id', 'channel', 'to', 'subject', 'body', 'status', 'error', 'purpose', 'outgoing_message_id', 'related_type', 'related_id', 'sent_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
