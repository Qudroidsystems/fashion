<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Handover of garments to the customer or their representative (SRS FR-PRD-008). */
class Delivery extends Model
{
    use BelongsToTenant, HasPublicId;

    public const CONFIRMATIONS = ['signature' => 'Signed for', 'id_checked' => 'ID checked', 'code' => 'Collection code', 'photo' => 'Photo taken', 'none' => 'No confirmation'];

    protected $fillable = [
        'tenant_id', 'number', 'order_id', 'line_ids', 'method', 'handed_over_at', 'recipient_name', 'recipient_phone', 'relationship',
        'confirmation', 'confirmation_ref', 'evidence_path', 'balance_at_handover', 'outstanding_conditions', 'address', 'user_id',
    ];

    protected $casts = ['line_ids' => 'array', 'handed_over_at' => 'datetime', 'balance_at_handover' => 'float'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getEvidenceUrlAttribute(): ?string
    {
        return $this->evidence_path ? Storage::disk('public')->url($this->evidence_path) : null;
    }
}
