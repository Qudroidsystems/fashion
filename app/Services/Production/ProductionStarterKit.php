<?php

namespace App\Services\Production;

use App\Models\ProductionWorkflow;
use App\Models\QualityChecklist;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;

/**
 * Starter workflows and a quality checklist for a new fashion house, so
 * production can start on day one. Houses edit or replace them in
 * Production › Setup. Safe to run again: it only adds what is missing.
 */
class ProductionStarterKit
{
    public const CHECKLIST = [
        ['label' => 'Measurements match the approved session', 'required' => true],
        ['label' => 'Matches the style and agreed design', 'required' => true],
        ['label' => 'Seams, hems and finishing are clean', 'required' => true],
        ['label' => 'No loose threads, marks or fabric faults', 'required' => true],
        ['label' => 'Zips, buttons and fastenings work', 'required' => true],
        ['label' => 'Pressed and ready to hand over', 'required' => false],
    ];

    public function install(Tenant $tenant): void
    {
        CurrentTenant::run($tenant, function () {
            if (ProductionWorkflow::exists()) {
                return;
            }
            $checklist = QualityChecklist::firstOrCreate(['name' => 'Final garment check'], ['items' => self::CHECKLIST]);
            $role = fn (string $name) => Role::ofCurrentTenant()->where('name', $name)->value('id');

            $bespoke = ProductionWorkflow::create([
                'name' => 'Bespoke garment', 'description' => 'Made-to-measure: cut, sew, fit, finish and check.',
                'line_kinds' => ['bespoke'], 'is_default' => true,
            ]);
            $stages = [
                ['Cutting', 'work', 8, 'Tailor', false, 'Cut from the approved measurements. Note fabric used on the material issue.'],
                ['Sewing', 'work', 24, 'Tailor', false, null],
                ['Fitting', 'fitting', 8, 'Designer', true, 'Book the customer in; record any alterations.'],
                ['Finishing', 'work', 8, 'Tailor', false, 'Hems, buttons, embellishment, pressing.'],
                ['Quality check', 'qc', 2, 'Manager', false, null],
            ];
            foreach ($stages as $i => [$name, $type, $hours, $roleName, $visible, $instructions]) {
                $bespoke->stages()->create([
                    'name' => $name, 'type' => $type, 'position' => $i + 1, 'duration_hours' => $hours, 'role_id' => $role($roleName),
                    'customer_visible' => $visible || $type === 'qc', 'checklist_id' => $type === 'qc' ? $checklist->id : null,
                    'instructions' => $instructions,
                ]);
            }

            $service = ProductionWorkflow::create([
                'name' => 'Alteration / service', 'description' => 'Adjustments and services on the customer’s garment.',
                'line_kinds' => ['service'], 'is_default' => true,
            ]);
            $service->stages()->create(['name' => 'Alteration work', 'type' => 'work', 'position' => 1, 'duration_hours' => 8, 'role_id' => $role('Tailor')]);
            $service->stages()->create(['name' => 'Quality check', 'type' => 'qc', 'position' => 2, 'duration_hours' => 1, 'role_id' => $role('Manager'), 'checklist_id' => $checklist->id, 'customer_visible' => true]);
        });
    }
}
