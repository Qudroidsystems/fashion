<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Received provider callback; the unique (provider, event_key) stops replays (SRS FR-ADM-004). Platform-level, not tenant-scoped. */
class WebhookEvent extends Model
{
    protected $fillable = ['tenant_id', 'provider', 'event_key', 'event_type', 'signature_valid', 'status', 'message', 'payload'];

    protected $casts = ['payload' => 'array', 'signature_valid' => 'boolean'];
}
