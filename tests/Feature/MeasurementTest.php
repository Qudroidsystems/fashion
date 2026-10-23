<?php

use App\Models\Customer;
use App\Models\FollowUpTask;
use App\Models\MeasurementRequest;
use App\Models\MeasurementSession;
use App\Models\MeasurementTemplate;
use App\Models\MeasurementTemplateVersion;
use App\Models\MeasurementValue;
use App\Services\Measurements\MeasurementRequestService;
use App\Services\Measurements\MeasurementService;
use App\Services\Measurements\TemplateService;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
    $this->customer = CurrentTenant::run($this->house, fn () => Customer::create(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '08031234567']));
    $this->trouser = CurrentTenant::run($this->house, fn () => MeasurementTemplate::where('code', 'trouser')->first()->currentVersion()->with('parameters')->first());
});

/** Web form input filling every value of a version (lengths = $len inches, bilateral both sides). */
function trouserInput(MeasurementTemplateVersion $v, float $len = 30): array
{
    $values = [];
    foreach ($v->parameters as $tp) {
        foreach ($tp->sides() as $side) {
            $values[$tp->id][$side] = match ($tp->data_type) {
                'length' => (string) $len,
                'select' => $tp->options[0],
                default  => 'ok',
            };
        }
    }

    return $values;
}

function approvedSession($test, float $len = 30): MeasurementSession
{
    $test->actingAs($test->owner)->post("/customers/{$test->customer->public_id}/measurements", [
        'template_version_id' => $test->trouser->id, 'display_unit' => 'in',
        'values' => trouserInput($test->trouser, $len), 'action' => 'submit', 'approve_now' => 1,
    ])->assertRedirect();

    return MeasurementSession::withoutGlobalScopes()->where('status', 'approved')->latest('id')->first();
}

it('installs the starter parameters and published templates for a new house', function () {
    CurrentTenant::run($this->house, function () {
        expect(MeasurementTemplate::usable()->count())->toBe(5)
            ->and(MeasurementTemplateVersion::where('status', 'published')->count())->toBe(5)
            ->and(\App\Models\MeasurementParameter::where('code', 'chest')->value('min_mm'))->toEqual(660.4);
    });
});

it('scenario 32: stores inches canonically in millimetres and shows them in cm', function () {
    $this->actingAs($this->owner)->post("/customers/{$this->customer->public_id}/measurements", [
        'template_version_id' => $this->trouser->id, 'display_unit' => 'in',
        'values' => [$this->trouser->parameters->firstWhere('code', 'waist')->id => ['both' => '38 1/2']],
        'action' => 'save',
    ])->assertRedirect();

    $v = MeasurementValue::withoutGlobalScopes()->where('parameter_code', 'waist')->first();
    expect((float) $v->value_mm)->toBe(977.9)
        ->and($v->original_unit)->toBe('in')
        ->and((float) $v->original_value)->toBe(38.5);

    $session = MeasurementSession::withoutGlobalScopes()->first();
    $this->actingAs($this->owner)->get("/measurements/{$session->public_id}?unit=cm")->assertOk()->assertSee('97.8 cm');
    $this->actingAs($this->owner)->get("/measurements/{$session->public_id}/edit")->assertOk()->assertSee('38.5', false);
});

it('captures, submits and approves; approved sessions are read-only', function () {
    $this->actingAs($this->owner)->get("/customers/{$this->customer->public_id}/measurements/new")->assertOk()->assertSee('Trouser');
    $this->actingAs($this->owner)->get("/customers/{$this->customer->public_id}/measurements/new?template_version_id={$this->trouser->id}")->assertOk()->assertSee('Thigh');

    $s = approvedSession($this);
    expect($s->verified_by)->toBe($this->owner->id);

    $this->actingAs($this->owner)->get("/customers/{$this->customer->public_id}")->assertOk()->assertSee('Current measurements')->assertSee('Trouser');
    $this->actingAs($this->owner)->get("/measurements/{$s->public_id}/edit")->assertForbidden();
    $this->actingAs($this->owner)->put("/measurements/{$s->public_id}", ['display_unit' => 'in'])->assertForbidden();
    expect(fn () => $s->update(['notes' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $s->delete())->toThrow(LogicException::class);
});

it('refuses to submit when required values are missing', function () {
    $this->actingAs($this->owner)->post("/customers/{$this->customer->public_id}/measurements", [
        'template_version_id' => $this->trouser->id, 'display_unit' => 'in',
        'values' => [$this->trouser->parameters->firstWhere('code', 'waist')->id => ['both' => '32']], 'action' => 'submit',
    ])->assertSessionHasErrors('values');

    expect(MeasurementSession::withoutGlobalScopes()->first()->status)->toBe('draft');
});

it('corrects an approved session by superseding it', function () {
    $old = approvedSession($this);

    $this->actingAs($this->owner)->post("/measurements/{$old->public_id}/correct", ['reason' => 'Waist typo'])->assertRedirect();
    $new = MeasurementSession::withoutGlobalScopes()->where('supersedes_id', $old->id)->first();
    expect($new->status)->toBe('draft')->and($new->values()->count())->toBe($old->values()->count());

    $this->actingAs($this->owner)->put("/measurements/{$new->public_id}", [
        'display_unit' => 'in', 'values' => trouserInput($this->trouser, 31), 'action' => 'submit', 'approve_now' => 1,
    ])->assertRedirect();

    expect($old->fresh()->status)->toBe('superseded')->and($new->fresh()->status)->toBe('approved');
});

it('scenario 31: a new template version leaves existing sessions on the old version', function () {
    $s = approvedSession($this);
    $templates = app(TemplateService::class);

    CurrentTenant::run($this->house, function () use ($templates, $s) {
        $this->actingAs($this->owner);
        $template = $this->trouser->template;
        $draft = $templates->draftFor($template);
        $items = $draft->parameters->map(fn ($p) => ['parameter_id' => $p->parameter_id, 'is_required' => false])->all();
        $templates->saveItems($draft, array_slice($items, 0, 3));
        $templates->publish($draft, 'Shorter');

        expect($template->fresh()->current_version_id)->toBe($draft->id)
            ->and($this->trouser->fresh()->status)->toBe('retired')
            ->and($this->trouser->parameters()->count())->toBeGreaterThan(3)
            ->and($s->fresh()->template_version_id)->toBe($this->trouser->id);

        expect(fn () => $templates->saveItems($this->trouser->fresh(), []))->toThrow(LogicException::class);
    });

    $this->actingAs($this->owner)->get("/measurements/{$s->public_id}")->assertOk()->assertSee('Thigh');
});

it('scenarios 33 and 34: a link submission waits for review and creates one review task', function () {
    $approved = approvedSession($this, 30);

    // Staff create a link; the token is shown once.
    $this->actingAs($this->owner)->post("/customers/{$this->customer->public_id}/measurement-requests", [
        'template_version_id' => $this->trouser->id, 'days' => 7, 'message' => 'For the wedding',
    ])->assertRedirect()->assertSessionHas('measurement_link');
    $url = session('measurement_link')['url'];
    $token = basename(parse_url($url, PHP_URL_PATH));
    $req = MeasurementRequest::withoutGlobalScopes()->first();
    expect($req->token_hash)->toBe(hash('sha256', $token))->and($req->token_hash)->not->toBe($token);

    auth()->logout();
    $this->get("/m/{$token}")->assertOk()->assertSee('For the wedding')->assertDontSee('762');

    // Customer sends values ~10% larger → flagged.
    $input = trouserInput($this->trouser, 33);
    $this->post("/m/{$token}", ['unit' => 'in', 'values' => $input, 'confirm' => '1'])->assertOk()->assertSee('Thank you');

    $linkSession = MeasurementSession::withoutGlobalScopes()->where('capture_method', 'customer_link')->first();
    expect($linkSession->status)->toBe('submitted')
        ->and($approved->fresh()->status)->toBe('approved')              // profile not replaced
        ->and($linkSession->change_summary['flagged'])->not->toBeEmpty()
        ->and($req->fresh()->status)->toBe('submitted');

    $tasks = FollowUpTask::withoutGlobalScopes()->where('source_key', 'measurement-review:' . $linkSession->public_id);
    expect($tasks->count())->toBe(1);

    // Link cannot be reused.
    $this->get("/m/{$token}")->assertStatus(410)->assertSee('already');

    // Sent back and resubmitted: still exactly one review task.
    $this->actingAs($this->owner)->post("/measurements/{$linkSession->public_id}/reject", ['reason' => 'Check hip'])->assertRedirect();
    $this->actingAs($this->owner)->put("/measurements/{$linkSession->public_id}", ['display_unit' => 'in', 'values' => $input, 'action' => 'submit'])->assertRedirect();
    expect($tasks->count())->toBe(1);

    $this->actingAs($this->owner)->get('/measurements')->assertOk()->assertSee('Ngozi');
    $this->actingAs($this->owner)->get("/measurements/{$linkSession->public_id}")->assertOk()->assertSee('differ noticeably');
});

it('rejects invalid, revoked and attempt-locked links', function () {
    $this->get('/m/' . str_repeat('a', 48))->assertNotFound();

    [$req, $token] = CurrentTenant::run($this->house, function () {
        $this->actingAs($this->owner);

        return app(MeasurementRequestService::class)->create($this->customer, $this->trouser, 3);
    });
    auth()->logout();

    $this->post("/m/{$token}", ['unit' => 'in', 'values' => [$this->trouser->parameters[0]->id => ['both' => '30']], 'confirm' => '1'])
        ->assertRedirect()->assertSessionHasErrors('values');
    expect($req->fresh()->failed_attempts)->toBe(1);

    $req->forceFill(['failed_attempts' => MeasurementRequest::MAX_FAILED_ATTEMPTS])->save();
    $this->get("/m/{$token}")->assertStatus(410)->assertSee('locked');

    $req->forceFill(['failed_attempts' => 0])->save();
    $this->actingAs($this->owner)->post("/measurement-requests/{$req->public_id}/revoke")->assertRedirect();
    auth()->logout();
    $this->get("/m/{$token}")->assertStatus(410)->assertSee('cancelled');
});

it('keeps measurements inside their house and enforces permissions', function () {
    $s = approvedSession($this);
    [$other, $otherOwner] = makeHouse('Other');

    $this->actingAs($otherOwner)->get("/measurements/{$s->public_id}")->assertNotFound();
    $this->actingAs($otherOwner)->get('/measurements')->assertOk()->assertDontSee('Ngozi');

    $cashier = addStaff($this->house, 'Cashier');
    $this->actingAs($cashier)->get("/measurements/{$s->public_id}")->assertForbidden();

    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor)->get("/measurements/{$s->public_id}")->assertOk();
    // Tailors measure customers (they can take measurements) but can't approve them.
    $this->actingAs($tailor)->get("/customers/{$this->customer->public_id}/measurements/new")->assertOk();
    $this->actingAs($tailor)->post("/measurements/{$s->public_id}/approve")->assertForbidden();
    $this->actingAs($cashier)->get("/customers/{$this->customer->public_id}/measurements/new")->assertForbidden();
});

it('exposes measurements over the API', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/measurement-templates')->assertOk()->assertJsonCount(5, 'data');

    $values = [];
    foreach ($this->trouser->parameters as $tp) {
        if ($tp->isLength()) {
            $values[$tp->code] = $tp->is_bilateral ? ['left' => '24', 'right' => '24 1/4'] : '30';
        }
    }

    $res = $this->postJson("/api/v1/customers/{$this->customer->public_id}/measurement-sessions", [
        'template_version_id' => $this->trouser->id, 'unit' => 'in', 'values' => $values, 'submit' => true,
    ])->assertCreated()->assertJsonPath('data.status', 'submitted');
    $id = $res->json('data.id');

    $this->getJson("/api/v1/measurement-sessions/{$id}?unit=cm")->assertOk()->assertJsonPath('data.unit', 'cm');
    $this->postJson("/api/v1/measurement-sessions/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->patchJson("/api/v1/measurement-sessions/{$id}", ['values' => ['waist' => '31']])->assertStatus(409);
    $this->getJson("/api/v1/measurement-sessions/{$id}/comparison")->assertOk()->assertJsonPath('data.compared_with', null);

    $this->postJson("/api/v1/customers/{$this->customer->public_id}/measurement-sessions", [
        'template_version_id' => $this->trouser->id, 'unit' => 'in', 'values' => ['not_a_code' => '3'],
    ])->assertUnprocessable();

    $this->postJson("/api/v1/customers/{$this->customer->public_id}/measurement-requests", ['template_version_id' => $this->trouser->id])
        ->assertCreated()->assertJsonStructure(['data' => ['id', 'url', 'expires_at']]);
});

it('reminds staff when approved measurements are due for review, once per month', function () {
    $s = approvedSession($this);
    MeasurementSession::withoutEvents(fn () => MeasurementSession::withoutGlobalScopes()->whereKey($s->id)->update(['verified_at' => now()->subMonths(7), 'submitted_at' => now()->subMonths(7)]));

    $this->artisan('fhmp:measurement-reviews')->assertSuccessful();
    $this->artisan('fhmp:measurement-reviews')->assertSuccessful();

    expect(FollowUpTask::withoutGlobalScopes()->where('source_key', 'like', 'measurement-due:%')->count())->toBe(1);
});

it('records no staff measurer for link submissions even when a user is logged in', function () {
    [$req, $token] = CurrentTenant::run($this->house, function () {
        $this->actingAs($this->owner);
        return app(MeasurementRequestService::class)->create($this->customer, $this->trouser, 3);
    });
    CurrentTenant::run($this->house, fn () => app(MeasurementRequestService::class)
        ->submit($req->fresh(['customer', 'templateVersion.parameters']), trouserInput($this->trouser), 'in'));

    expect(MeasurementSession::withoutGlobalScopes()->where('capture_method', 'customer_link')->value('measured_by'))->toBeNull();
});
