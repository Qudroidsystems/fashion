<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\OutgoingMessage;
use App\Services\Appointments\AppointmentService;
use App\Services\Messaging\CustomerUpdates;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutDefer();
    Notification::fake();
    [$this->house, $this->owner] = makeHouse('Diary House');
    $this->customer = CurrentTenant::run($this->house, fn () => Customer::create(['first_name' => 'Kemi', 'last_name' => 'Ade', 'phone' => '08031234567', 'email' => 'kemi@example.com']));
    $this->designer = addStaff($this->house, 'Designer', ['name' => 'Tola Designer']);
});

afterEach(fn () => Carbon::setTestNow());

function inDays($test, int $days, int $hour = 11): string
{
    return now($test->house->timezone)->addDays($days)->setTime($hour, 0)->format('Y-m-d\TH:i');
}

it('books an appointment, confirms it to the customer now and reminds them the day before', function () {
    $this->actingAs($this->owner)->post('/appointments', [
        'customer_id' => $this->customer->id, 'type' => 'consultation', 'starts_at' => inDays($this, 5), 'duration_minutes' => 60,
        'staff_id' => $this->designer->id, 'notes' => 'Wedding party of six',
    ])->assertRedirect()->assertSessionHas('success');

    $a = CurrentTenant::run($this->house, fn () => Appointment::first());
    expect($a->number)->toStartWith('APT-')->and($a->status)->toBe('booked')->and($a->staff_id)->toBe($this->designer->id);

    $msgs = CurrentTenant::run($this->house, fn () => OutgoingMessage::orderBy('id')->get());
    expect($msgs->pluck('purpose')->all())->toBe(['appointment_booked', 'appointment_reminder'])
        ->and($msgs[0]->status)->toBe('sent')->and($msgs[0]->body)->toContain('consultation')
        ->and($msgs[1]->status)->toBe('queued')->and($msgs[1]->send_after->diffInHours($a->starts_at, true))->toBe(24.0);
    Notification::assertSentTo($this->designer, \App\Notifications\AppointmentBooked::class);

    // Reminder goes when its time comes.
    Carbon::setTestNow($a->starts_at->copy()->subHours(23));
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->sendWaiting());
    expect($msgs[1]->fresh()->status)->toBe('sent');

    $this->actingAs($this->owner)->get('/appointments?from=' . now($this->house->timezone)->toDateString())->assertOk()->assertSee('Kemi Ade')->assertSee('Consultation');
    $this->actingAs($this->owner)->get("/appointments/{$a->public_id}")->assertOk()->assertSee('Wedding party of six')->assertSee('Appointment booked');
    $this->actingAs($this->owner)->get("/customers/{$this->customer->public_id}")->assertOk()->assertSee('Coming up')->assertSee('Consultation');
});

it('moves the reminder when the appointment moves, and drops it when cancelled', function () {
    $svc = fn () => app(AppointmentService::class);
    $a = CurrentTenant::run($this->house, fn () => $svc()->book(['customer_id' => $this->customer->id, 'type' => 'measurement', 'starts_at' => inDays($this, 4)], $this->owner));

    CurrentTenant::run($this->house, fn () => $svc()->reschedule($a, inDays($this, 6, 15), null, $this->owner));
    $q = fn () => CurrentTenant::run($this->house, fn () => OutgoingMessage::where('purpose', 'appointment_reminder')->orderBy('id')->get());
    expect($q()->pluck('status')->all())->toBe(['cancelled', 'queued'])
        ->and(CurrentTenant::run($this->house, fn () => OutgoingMessage::where('purpose', 'appointment_booked')->count()))->toBe(2);

    $this->actingAs($this->owner)->post("/appointments/{$a->public_id}/status", ['status' => 'cancelled'])->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post("/appointments/{$a->public_id}/status", ['status' => 'cancelled', 'note' => 'Travelling'])->assertRedirect();
    expect($a->fresh()->status)->toBe('cancelled')->and($q()->pluck('status')->all())->toBe(['cancelled', 'cancelled']);
});

it('does not double-book a staff member unless told to', function () {
    $svc = fn () => app(AppointmentService::class);
    CurrentTenant::run($this->house, fn () => $svc()->book(['customer_id' => $this->customer->id, 'type' => 'consultation', 'starts_at' => inDays($this, 3), 'duration_minutes' => 60, 'staff_id' => $this->designer->id], $this->owner));

    $clash = ['customer_id' => $this->customer->id, 'type' => 'collection', 'starts_at' => now($this->house->timezone)->addDays(3)->setTime(11, 30)->format('Y-m-d\TH:i'), 'duration_minutes' => 30, 'staff_id' => $this->designer->id];
    $this->actingAs($this->owner)->post('/appointments', $clash)->assertSessionHasErrors('starts_at');
    $this->actingAs($this->owner)->post('/appointments', $clash + ['allow_clash' => 1])->assertRedirect()->assertSessionHasNoErrors();
    // Right after it ends is fine.
    $this->actingAs($this->owner)->post('/appointments', ['starts_at' => inDays($this, 3, 12)] + $clash)->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/appointments', ['starts_at' => now()->subDay()->format('Y-m-d\TH:i')] + $clash)->assertSessionHasErrors('starts_at');
});

it('works from the staff app', function () {
    $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/appointments', [
        'customer' => $this->customer->public_id, 'type' => 'collection', 'starts_at' => inDays($this, 2, 16), 'duration_minutes' => 15,
    ])->assertCreated()->assertJsonPath('data.type_label', 'Collection')->assertJsonPath('data.customer.name', 'Kemi Ade');
    $id = $res->json('data.id');

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/appointments?days=7')->assertOk()
        ->assertJsonPath('data.0.entries.0.id', $id)->assertJsonPath('data.0.entries.0.kind', 'appointment');
    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/appointments/{$id}")->assertOk()->assertJsonPath('data.messages.0.label', 'Appointment reminder');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/appointments/{$id}/status", ['status' => 'done', 'note' => 'Collected both'])
        ->assertOk()->assertJsonPath('data.status', 'done');
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/appointments/meta')->assertOk()->assertJsonPath('data.types.0.value', 'consultation');

    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor, 'sanctum')->getJson('/api/v1/appointments')->assertForbidden();
});
