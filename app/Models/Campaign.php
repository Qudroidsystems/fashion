<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A promotional message to a group of customers who said yes to promotions. */
class Campaign extends Model
{
    use BelongsToTenant, HasPublicId;

    public const AUDIENCES = [
        'all'            => 'Everyone who agreed to promotions',
        'vip'            => 'VIP customers',
        'tags'           => 'Customers with a tag',
        'birthday_month' => 'Birthday this month',
        'recent'         => 'Ordered in the last … days',
        'lapsed'         => 'Haven’t ordered for … days',
    ];

    /** status => [label, pill] */
    public const STATUSES = [
        'draft' => ['Draft', 'st-muted'], 'scheduled' => ['Scheduled', 'st-info'], 'sending' => ['Sending', 'st-violet'],
        'sent' => ['Sent', 'st-success'], 'cancelled' => ['Cancelled', 'st-muted'],
    ];

    protected $fillable = ['tenant_id', 'name', 'body', 'channels', 'audience', 'status', 'send_at', 'recipients', 'created_by'];

    protected $casts = ['channels' => 'array', 'audience' => 'array', 'send_at' => 'datetime'];

    public function messages(): MorphMany
    {
        return $this->morphMany(OutgoingMessage::class, 'related');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }
}
