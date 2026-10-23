<?php

use App\Models\Customer;
use App\Models\MeasurementTemplate;
use App\Support\Tenancy\CurrentTenant;

/* Measuring with a template built on the spot (staff app). */

it('lists the house parameters and builds a published template from the chosen ones', function () {
    [$house, $owner] = makeHouse('Quick House');
    $tailor = addStaff($house, 'Tailor');
    $customer = CurrentTenant::run($house, fn () => Customer::create(['first_name' => 'Ada', 'phone' => '08039990000']));

    $params = collect($this->actingAs($tailor, 'sanctum')->getJson('/api/v1/measurement-parameters')->assertOk()->json('data'));
    expect($params->count())->toBeGreaterThan(5);
    $pick = $params->whereIn('code', ['chest', 'waist', 'hip'])->pluck('id')->values()->all();
    if (count($pick) < 2) {
        $pick = $params->take(3)->pluck('id')->all();
    }

    $tpl = $this->actingAs($tailor, 'sanctum')->postJson('/api/v1/measurement-templates/quick', [
        'name' => "Ada's kaftan", 'parameter_ids' => $pick, 'customer_id' => $customer->public_id,
    ])->assertCreated()->json('data');

    expect($tpl['version_id'])->not->toBeNull()
        ->and(collect($tpl['parameters'])->pluck('parameter_id')->all())->toBe($pick);

    // It is a normal, published, reusable template.
    $codes = collect($this->actingAs($tailor, 'sanctum')->getJson('/api/v1/measurement-templates')->json('data'))->pluck('name');
    expect($codes)->toContain("Ada's kaftan");

    // And measurements can be taken with it straight away.
    $first = $tpl['parameters'][0];
    $value = $first['sides'] === ['both'] ? '38' : ['left' => '24', 'right' => '24'];
    $this->actingAs($tailor, 'sanctum')->postJson("/api/v1/customers/{$customer->public_id}/measurement-sessions", [
        'template_version_id' => $tpl['version_id'], 'unit' => 'in', 'values' => [$first['code'] => $value],
    ])->assertCreated();

    // A second template with the same name gets its own code.
    $this->actingAs($tailor, 'sanctum')->postJson('/api/v1/measurement-templates/quick', ['name' => "Ada's kaftan", 'parameter_ids' => $pick])->assertCreated();
    expect(CurrentTenant::run($house, fn () => MeasurementTemplate::where('name', "Ada's kaftan")->distinct()->count('code')))->toBe(2);
});

it('refuses parameters from another house', function () {
    [$a, $ownerA] = makeHouse('A House');
    [$b] = makeHouse('B House');
    $foreign = CurrentTenant::run($b, fn () => \App\Models\MeasurementParameter::first()->id);

    $this->actingAs($ownerA, 'sanctum')->postJson('/api/v1/measurement-templates/quick', ['name' => 'X', 'parameter_ids' => [$foreign]])
        ->assertStatus(422);
});
