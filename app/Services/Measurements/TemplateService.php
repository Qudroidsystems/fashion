<?php

namespace App\Services\Measurements;

use App\Models\MeasurementParameter;
use App\Models\MeasurementTemplate;
use App\Models\MeasurementTemplateVersion;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versioned measurement templates (SRS FR-MEA-004/005, scenario 31).
 *
 * Life cycle: create → draft v1 → publish → (edit = new draft v2 copied from v1) → publish …
 * Publishing snapshots every parameter's definition into the version and
 * makes it read-only. Sessions keep pointing at the version they used.
 */
class TemplateService
{
    public function create(array $data, array $items = []): MeasurementTemplate
    {
        return DB::transaction(function () use ($data, $items) {
            $template = MeasurementTemplate::create($data + ['created_by' => auth()->id()]);
            $draft = MeasurementTemplateVersion::create(['template_id' => $template->id, 'version_no' => 1, 'status' => 'draft']);
            $this->saveItems($draft, $items);
            AuditLogger::log('measurement_template.created', $template, after: ['name' => $template->name]);

            return $template;
        });
    }

    /** The editable draft of a template, created from the current version if there is none. */
    public function draftFor(MeasurementTemplate $template): MeasurementTemplateVersion
    {
        if ($draft = $template->draft()->first()) {
            return $draft;
        }

        return DB::transaction(function () use ($template) {
            $current = $template->currentVersion()->with('parameters')->first();
            $next = (int) $template->versions()->max('version_no') + 1;
            $draft = MeasurementTemplateVersion::create(['template_id' => $template->id, 'version_no' => $next, 'status' => 'draft']);

            foreach ($current?->parameters ?? [] as $p) {
                $draft->parameters()->create($p->only([
                    'parameter_id', 'code', 'label', 'data_type', 'is_bilateral', 'options', 'min_mm', 'max_mm',
                    'change_threshold_mm', 'instructions', 'is_required', 'sort_order', 'guidance',
                ]));
            }

            return $draft;
        });
    }

    /**
     * Replace a draft's parameter list.
     * $items: [['parameter_id' => 3, 'is_required' => true, 'guidance' => '…'], …] in display order.
     */
    public function saveItems(MeasurementTemplateVersion $draft, array $items): void
    {
        $this->assertDraft($draft);

        $ids = collect($items)->pluck('parameter_id')->map(fn ($v) => (int) $v)->unique()->values();
        $params = MeasurementParameter::whereIn('id', $ids)->get()->keyBy('id');   // tenant-scoped

        DB::transaction(function () use ($draft, $items, $params) {
            $draft->parameters()->delete();
            $order = 0;
            $seen = [];
            foreach ($items as $item) {
                $param = $params->get((int) ($item['parameter_id'] ?? 0));
                if (! $param || isset($seen[$param->id])) {
                    continue;
                }
                $seen[$param->id] = true;
                $draft->parameters()->create($param->snapshot() + [
                    'parameter_id' => $param->id,
                    'is_required'  => (bool) ($item['is_required'] ?? true),
                    'guidance'     => $item['guidance'] ?? null,
                    'sort_order'   => $order++,
                ]);
            }
        });
    }

    public function publish(MeasurementTemplateVersion $draft, ?string $notes = null): MeasurementTemplateVersion
    {
        $this->assertDraft($draft);

        if ($draft->parameters()->count() === 0) {
            throw ValidationException::withMessages(['parameters' => 'Add at least one measurement before publishing.']);
        }

        return DB::transaction(function () use ($draft, $notes) {
            // Refresh snapshots from the latest parameter definitions.
            foreach ($draft->parameters()->with('parameter')->get() as $tp) {
                if ($tp->parameter) {
                    $tp->update($tp->parameter->snapshot());
                }
            }

            $template = $draft->template;
            MeasurementTemplateVersion::where('template_id', $template->id)->where('status', 'published')->update(['status' => 'retired']);

            $draft->update([
                'status'       => 'published',
                'change_notes' => $notes,
                'published_at' => now(),
                'published_by' => auth()->id(),
            ]);
            $template->update(['current_version_id' => $draft->id]);

            AuditLogger::log('measurement_template.published', $template, after: [
                'version' => $draft->version_no,
                'measurements' => $draft->parameters()->pluck('code')->all(),
            ]);

            return $draft;
        });
    }

    protected function assertDraft(MeasurementTemplateVersion $version): void
    {
        if (! $version->isEditable()) {
            throw new \LogicException('Published template versions cannot be changed. Edit the draft instead.');
        }
    }
}
