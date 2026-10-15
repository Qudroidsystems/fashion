<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Numbering\DocumentNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A fashion house's customer (SRS §5.3). */
class Customer extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    public const SOURCES = [
        'walk_in' => 'Walk-in', 'referral' => 'Referral', 'instagram' => 'Instagram',
        'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'whatsapp' => 'WhatsApp',
        'website' => 'Website', 'event' => 'Event / exhibition', 'other' => 'Other',
    ];

    /** Free-text style preferences captured on the profile. */
    public const PREFERENCE_FIELDS = [
        'fit'     => 'Preferred fit',
        'fabrics' => 'Favourite fabrics',
        'colours' => 'Colours',
        'styles'  => 'Styles they like',
        'avoid'   => 'Dislikes / avoid',
    ];

    protected $fillable = [
        'tenant_id', 'customer_number', 'branch_id', 'title', 'first_name', 'last_name', 'gender',
        'phone', 'alt_phone', 'whatsapp', 'email', 'birthday', 'address', 'city', 'state',
        'occupation', 'source', 'is_vip', 'preferences', 'assigned_to', 'status',
        'merged_into_id', 'user_id', 'last_activity_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'birthday'         => 'date',
        'is_vip'           => 'boolean',
        'preferences'      => 'array',
        'last_activity_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        static::creating(function (Customer $c) {
            $c->customer_number ??= DocumentNumber::next('customer');
            $c->created_by ??= auth()->id();
        });

        static::saving(function (Customer $c) {
            $c->phone_normalized = static::normalizePhone($c->phone);
            $c->email = $c->email ? mb_strtolower(trim($c->email)) : null;
            if ($c->isDirty() && $c->exists) {
                $c->updated_by = auth()->id() ?? $c->updated_by;
            }
        });
    }

    /** Last 10 digits, so 0803…, +234803… and 234803… match each other. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : substr($digits, -10);
    }

    /* Relations ----------------------------------------------------------- */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'merged_into_id')->withTrashed();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_tag_links');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest();
    }

    public function consents(): HasMany
    {
        return $this->hasMany(CustomerConsent::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUpTask::class);
    }

    public function measurementSessions(): HasMany
    {
        return $this->hasMany(MeasurementSession::class);
    }

    public function measurementRequests(): HasMany
    {
        return $this->hasMany(MeasurementRequest::class);
    }

    /* Scopes -------------------------------------------------------------- */

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
        $digits = static::normalizePhone($term);

        $fullName = $q->getConnection()->getDriverName() === 'sqlite'
            ? "(first_name || ' ' || coalesce(last_name, ''))"
            : "concat(first_name, ' ', coalesce(last_name, ''))";

        return $q->where(function ($w) use ($like, $digits, $fullName) {
            $w->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("$fullName like ?", [$like])
                ->orWhere('customer_number', 'like', $like)
                ->orWhere('email', 'like', $like);
            if ($digits && strlen($digits) >= 4) {
                $w->orWhere('phone_normalized', 'like', '%' . $digits . '%');
            }
        });
    }

    /* Helpers ------------------------------------------------------------- */

    public function getFullNameAttribute(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->first_name . ' ' . $this->last_name);
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getInitialsAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1) . mb_substr((string) $this->last_name, 0, 1));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function hasConsent(string $channel, string $purpose = 'marketing'): bool
    {
        return (bool) $this->consents->first(fn ($c) => $c->channel === $channel && $c->purpose === $purpose)?->granted;
    }

    /** Next birthday as a date in the tenant timezone (or null). */
    public function nextBirthday(?string $tz = null): ?\Carbon\Carbon
    {
        if (! $this->birthday) {
            return null;
        }
        $today = now($tz)->startOfDay();
        $next = $this->birthday->copy()->setYear($today->year);
        if ($next->lt($today)) {
            $next->addYear();
        }

        return $next;
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }
}
