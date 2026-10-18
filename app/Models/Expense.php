<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A house running cost (rent, salaries, power…). Voided, never deleted. */
class Expense extends Model
{
    use BelongsToTenant, HasPublicId;

    /** Starting categories; houses can type their own. */
    public const CATEGORIES = [
        'Rent', 'Salaries & wages', 'Power & fuel', 'Transport & logistics', 'Marketing & adverts', 'Repairs & maintenance',
        'Workshop supplies', 'Internet & phone', 'Bank charges', 'Tax & levies', 'Professional fees', 'Other',
    ];

    public const METHODS = Payment::METHODS;

    protected $fillable = [
        'tenant_id', 'number', 'branch_id', 'category', 'description', 'amount', 'spent_on', 'method', 'paid_to', 'reference',
        'receipt_path', 'status', 'void_reason', 'voided_at', 'voided_by', 'recorded_by',
    ];

    protected $casts = ['amount' => 'float', 'spent_on' => 'date', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Expenses cannot be deleted. Void it instead.'));
    }

    public function scopeCounted(Builder $q): Builder
    {
        return $q->where('status', 'recorded');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Corrections are allowed on the day it was recorded, by whoever can manage expenses. */
    public function isEditable(): bool
    {
        return $this->status === 'recorded' && $this->created_at?->isSameDay(now());
    }
}
