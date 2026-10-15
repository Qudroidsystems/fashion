<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Consent per channel and purpose (SRS FR-CRM-004, FR-NOT-008). */
class CustomerConsent extends Model
{
    use BelongsToTenant;

    public const CHANNELS = ['sms' => 'SMS', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'push' => 'App push'];

    public const PURPOSES = ['transactional' => 'Order & appointment updates', 'marketing' => 'Promotions & offers'];

    protected $fillable = ['tenant_id', 'customer_id', 'channel', 'purpose', 'granted', 'source', 'recorded_at', 'recorded_by'];

    protected $casts = ['granted' => 'boolean', 'recorded_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
