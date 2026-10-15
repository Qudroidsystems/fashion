<?php

namespace App\Services\Catalogue;

use App\Models\FashionStyle;
use App\Models\MeasurementTemplate;
use App\Models\StyleImage;
use App\Models\StyleVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Versioned bespoke styles (SRS FR-MEA-006, §7 "fashion style → style versions → template links").
 *
 *  create → draft v1 → publish → edit = draft v2 copied from v1 (text, prices, pictures, templates) → publish …
 *
 * Publishing pins the current version of every linked measurement template, retires the
 * previous style version and freezes this one. Orders will reference style_version_id.
 */
class StyleService
{
    public const MAX_IMAGES = 12;

    public function create(array $style, array $content = [], array $templateIds = []): FashionStyle
    {
        return DB::transaction(function () use ($style, $content, $templateIds) {
            $model = FashionStyle::create($style + [
                'code'       => ($style['code'] ?? null) ?: DocumentNumber::next('style'),
                'created_by' => auth()->id(),
            ]);
            $draft = StyleVersion::create(['style_id' => $model->id, 'version_no' => 1, 'status' => 'draft', 'created_by' => auth()->id()]);
            $this->updateDraft($draft, $content, $templateIds);
            AuditLogger::log('style.created', $model, after: $model->only(['code', 'name']));

            return $model;
        });
    }

    /** The style's draft, created from the published version when there is none. */
    public function draftFor(FashionStyle $style): StyleVersion
    {
        if ($draft = $style->draft()->first()) {
            return $draft;
        }

        return DB::transaction(function () use ($style) {
            $current = $style->currentVersion()->with('images', 'templateLinks')->first();
            $next = (int) $style->versions()->max('version_no') + 1;

            $draft = StyleVersion::create(($current ? $current->only(StyleVersion::CONTENT) : []) + [
                'style_id' => $style->id, 'version_no' => $next, 'status' => 'draft', 'created_by' => auth()->id(),
            ]);

            foreach ($current?->templateLinks ?? [] as $link) {
                $draft->templateLinks()->create(['template_id' => $link->template_id, 'sort_order' => $link->sort_order]);
            }
            foreach ($current?->images ?? [] as $img) {
                $draft->images()->create($img->only(['path', 'caption', 'sort_order']));
            }

            return $draft;
        });
    }

    public function updateDraft(StyleVersion $draft, array $content, ?array $templateIds = null): StyleVersion
    {
        $this->assertDraft($draft);

        DB::transaction(function () use ($draft, $content, $templateIds) {
            if (array_key_exists('price_options', $content)) {
                $content['price_options'] = collect($content['price_options'] ?? [])
                    ->filter(fn ($o) => trim((string) ($o['label'] ?? '')) !== '')
                    ->map(fn ($o) => ['label' => mb_substr(trim($o['label']), 0, 80), 'amount' => round((float) ($o['amount'] ?? 0), 2)])
                    ->values()->all();
            }
            $draft->update(array_intersect_key($content, array_flip(StyleVersion::CONTENT)));

            if ($templateIds !== null) {
                $ids = MeasurementTemplate::whereIn('id', array_map('intval', $templateIds))->pluck('id');   // tenant-scoped
                $draft->templateLinks()->delete();
                foreach (array_values(array_unique(array_map('intval', $templateIds))) as $i => $id) {
                    if ($ids->contains($id)) {
                        $draft->templateLinks()->create(['template_id' => $id, 'sort_order' => $i]);
                    }
                }
            }
        });

        return $draft->fresh();
    }

    /** @param UploadedFile[] $files */
    public function addImages(StyleVersion $draft, array $files): void
    {
        $this->assertDraft($draft);
        $count = $draft->images()->count();
        if ($count + count($files) > self::MAX_IMAGES) {
            throw ValidationException::withMessages(['images' => 'A style can have up to ' . self::MAX_IMAGES . ' pictures.']);
        }

        $dir = 'tenants/' . CurrentTenant::require()->public_id . '/styles/' . $draft->style->public_id;
        foreach ($files as $i => $file) {
            $path = $file->storeAs($dir, Str::lower(Str::random(16)) . '.' . $file->extension(), 'public');
            $draft->images()->create(['path' => $path, 'sort_order' => $count + $i]);
        }
    }

    public function removeImage(StyleImage $image): void
    {
        $draft = StyleVersion::findOrFail($image->style_version_id);
        $this->assertDraft($draft);
        $path = $image->path;
        $image->delete();
        $this->deleteFileIfUnused($path);
        $this->renumberImages($draft);
    }

    public function makeCover(StyleImage $image): void
    {
        $draft = StyleVersion::findOrFail($image->style_version_id);
        $this->assertDraft($draft);
        $image->update(['sort_order' => -1]);
        $this->renumberImages($draft);
    }

    public function publish(StyleVersion $draft, ?string $notes = null): StyleVersion
    {
        $this->assertDraft($draft);
        $draft->load('templateLinks.template', 'style');

        $usable = $draft->templateLinks->filter(fn ($l) => $l->template?->is_active && $l->template?->current_version_id);
        if ($usable->isEmpty()) {
            throw ValidationException::withMessages(['templates' => 'Choose at least one published measurement template before publishing.']);
        }

        return DB::transaction(function () use ($draft, $notes, $usable) {
            foreach ($draft->templateLinks as $link) {
                $usable->contains($link) ? $link->update(['template_version_id' => $link->template->current_version_id]) : $link->delete();
            }

            $style = $draft->style;
            StyleVersion::where('style_id', $style->id)->where('status', 'published')->get()
                ->each(fn ($v) => $v->update(['status' => 'retired']));

            $draft->update([
                'status' => 'published', 'change_notes' => $notes ? mb_substr($notes, 0, 255) : null,
                'published_at' => now(), 'published_by' => auth()->id(),
            ]);
            $style->update(['current_version_id' => $draft->id]);

            AuditLogger::log('style.published', $style, after: [
                'version' => $draft->version_no, 'base_price' => $draft->base_price, 'lead_time_days' => $draft->lead_time_days,
            ], description: "Published {$style->code} v{$draft->version_no}");

            return $draft->fresh();
        });
    }

    /** Throw away a draft (not allowed for a style that was never published — archive it instead). */
    public function discardDraft(StyleVersion $draft): void
    {
        $this->assertDraft($draft);
        if (! $draft->style->current_version_id) {
            throw ValidationException::withMessages(['draft' => 'This is the only version of the style. Archive the style instead.']);
        }

        DB::transaction(function () use ($draft) {
            $paths = $draft->images()->pluck('path');
            $draft->images()->delete();
            $draft->templateLinks()->delete();
            $draft->delete();
            $paths->each(fn ($p) => $this->deleteFileIfUnused($p));
        });
    }

    protected function renumberImages(StyleVersion $draft): void
    {
        $draft->images()->get()->values()->each(fn ($img, $i) => $img->update(['sort_order' => $i]));
    }

    protected function deleteFileIfUnused(string $path): void
    {
        if (! StyleImage::withoutGlobalScopes()->where('path', $path)->exists()) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function assertDraft(StyleVersion $version): void
    {
        if (! $version->isEditable()) {
            throw new \LogicException('Published style versions cannot be changed. Edit the draft instead.');
        }
    }
}
