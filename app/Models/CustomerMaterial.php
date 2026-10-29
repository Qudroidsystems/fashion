<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Something a customer left with the house — fabric to sew, a garment to alter — from the
 * moment it is received and tagged until it goes back to them.
 */
class CustomerMaterial extends Model
{
    use BelongsToTenant, HasPublicId;

    public const KINDS = [
        'fabric' => 'Fabric', 'lace' => 'Lace', 'aso_oke' => 'Aso-oke', 'ankara' => 'Ankara', 'lining' => 'Lining',
        'accessories' => 'Buttons, zips & trims', 'garment' => 'Garment', 'other' => 'Other',
    ];

    public const PURPOSES = [
        'sew' => 'To sew', 'alteration' => 'To alter', 'repair' => 'To repair', 'copy' => 'Style to copy', 'other' => 'Other',
    ];

    public const UNITS = ['yd' => 'yards', 'm' => 'metres', 'pcs' => 'pieces', 'set' => 'sets'];

    /** status => [label, pill class] */
    public const STATUSES = [
        'held'        => ['In store', 'st-info'],
        'in_use'      => ['In the workroom', 'st-violet'],
        'returned'    => ['Returned', 'st-success'],
        'used'        => ['Used up', 'st-muted'],
        'written_off' => ['Written off', 'st-danger'],
    ];

    /** Still in the house's hands. */
    public const WITH_HOUSE = ['held', 'in_use'];

    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'order_id', 'order_line_id', 'branch_id', 'kind', 'purpose', 'description', 'colour',
        'quantity', 'unit', 'condition_notes', 'photos', 'location', 'status', 'received_at', 'received_by', 'collect_by',
        'returned_at', 'returned_by', 'returned_to', 'returned_to_phone', 'returned_quantity', 'return_note', 'delivery_id',
        'reminders_sent', 'last_reminded_at',
    ];

    protected $casts = [
        'photos' => 'array', 'quantity' => 'float', 'returned_quantity' => 'float',
        'received_at' => 'datetime', 'returned_at' => 'datetime', 'last_reminded_at' => 'datetime', 'collect_by' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerMaterialEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function scopeWithHouse($q)
    {
        return $q->whereIn('status', self::WITH_HOUSE);
    }

    public function isWithHouse(): bool
    {
        return in_array($this->status, self::WITH_HOUSE, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    /** "3 yards blue lace" */
    public function getSummaryAttribute(): string
    {
        $qty = $this->quantity ? rtrim(rtrim(number_format($this->quantity, 2), '0'), '.') . ' ' . (self::UNITS[$this->unit] ?? $this->unit) . ' ' : '';

        return trim($qty . ($this->colour ? $this->colour . ' ' : '') . $this->description);
    }

    public function photoUrls(): array
    {
        return collect($this->photos ?? [])->map(fn ($p) => Storage::disk('public')->url($p))->values()->all();
    }

    /** How long the house has had it, in days. */
    public function daysHeld(): int
    {
        return (int) $this->received_at->diffInDays($this->returned_at ?? now(), true);
    }
}
