<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something a visitor sent from the house's public website. Not a customer until staff convert it. */
class SiteEnquiry extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TYPES = ['enquiry' => 'Enquiry', 'booking' => 'Fitting / appointment request', 'measurements' => 'Wants to send measurements'];

    public const STATUSES = [
        'new' => ['New', 'st-info'], 'contacted' => ['Contacted', 'st-warning'], 'converted' => ['Customer', 'st-success'],
        'closed' => ['Closed', 'st-muted'], 'spam' => ['Spam', 'st-danger'],
    ];

    protected $fillable = [
        'tenant_id', 'type', 'name', 'phone', 'email', 'message', 'style_id', 'product_id', 'preferred_date', 'preferred_time',
        'status', 'customer_id', 'handled_by', 'handled_at', 'ip', 'user_agent',
    ];

    protected $casts = ['preferred_date' => 'date', 'handled_at' => 'datetime'];

    public function style(): BelongsTo
    {
        return $this->belongsTo(FashionStyle::class, 'style_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
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
