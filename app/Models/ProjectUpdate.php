<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A progress note, optionally with pictures, that can be published to the client. */
class ProjectUpdate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'title', 'body', 'images', 'customer_visible', 'status', 'published_at', 'published_by', 'created_by',
    ];

    protected $casts = ['images' => 'array', 'customer_visible' => 'boolean', 'published_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->customer_visible && $this->status === 'published';
    }

    public function imageUrls(): array
    {
        return collect($this->images ?? [])->map(fn ($p) => Storage::disk('public')->url($p))->all();
    }
}
