<?php

namespace App\Services\Measurements;

use App\Models\Customer;
use App\Models\MeasurementRequest;
use App\Models\MeasurementSession;
use App\Models\MeasurementTemplateVersion;
use App\Models\User;
use App\Notifications\MeasurementSubmitted;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Secure customer measurement links (SRS §5.5):
 *  - 48-char random token, only its SHA-256 hash is stored (FR-LNK-002);
 *  - scoped to one request, expiring, revocable, attempt-limited;
 *  - a submission becomes a "submitted" session and never replaces the
 *    approved profile until staff approve it (FR-LNK-005);
 *  - previous measurements are never shown on the link (FR-LNK-007).
 */
class MeasurementRequestService
{
    public function __construct(protected MeasurementService $measurements)
    {
    }

    /** @return array{0: MeasurementRequest, 1: string} the request and the plain token (show once). */
    public function create(Customer $customer, MeasurementTemplateVersion $version, int $days = 7, ?string $message = null, ?string $deadline = null): array
    {
        if ($version->status === 'draft') {
            throw ValidationException::withMessages(['template' => 'Choose a published template.']);
        }

        $token = Str::random(48);
        $request = MeasurementRequest::create([
            'customer_id'         => $customer->id,
            'template_version_id' => $version->id,
            'token_hash'          => MeasurementRequest::hashToken($token),
            'status'              => 'pending',
            'message'             => $message,
            'expires_at'          => now()->addDays(max(1, min($days, 60))),
            'deadline'            => $deadline,
            'created_by'          => auth()->id(),
        ]);

        AuditLogger::log('measurement.request.created', $customer, description: 'Measurement link sent (' . $version->label . ')');

        return [$request, $token];
    }

    public function url(string $token): string
    {
        return route('measure.form', ['token' => $token]);
    }

    /** Find a request by its plain token across tenants (public page — no session). */
    public function findByToken(string $token): ?MeasurementRequest
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        return MeasurementRequest::withoutTenantScope()
            ->where('token_hash', MeasurementRequest::hashToken($token))
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes(), 'templateVersion' => fn ($q) => $q->withoutGlobalScopes()])
            ->first();
    }

    public function revoke(MeasurementRequest $request, User $by): void
    {
        if ($request->status === 'pending') {
            $request->update(['status' => 'revoked', 'revoked_by' => $by->id]);
            AuditLogger::log('measurement.request.revoked', $request->customer, description: 'Measurement link revoked');
        }
    }

    /**
     * Customer submits through the link. Must run inside the request's tenant.
     * Returns the new submitted session.
     */
    public function submit(MeasurementRequest $request, array $values, string $unit, ?string $note = null): MeasurementSession
    {
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['link' => 'This link is no longer active.']);
        }

        try {
            return DB::transaction(function () use ($request, $values, $unit, $note) {
                $session = $this->measurements->start($request->customer, $request->templateVersion, [
                    'capture_method' => 'customer_link',
                    'display_unit'   => $unit,
                    'measured_by'    => null,
                    'branch_id'      => $request->customer->branch_id,
                    'notes'          => $note ? 'Customer note: ' . mb_substr($note, 0, 1000) : null,
                ]);
                $this->measurements->saveValues($session, $values, $unit);
                $session = $this->measurements->submit($session);

                $request->update(['status' => 'submitted', 'submitted_at' => now(), 'session_id' => $session->id]);

                if ($owner = User::find($request->created_by)) {
                    $owner->notify(new MeasurementSubmitted($session));
                }

                return $session;
            });
        } catch (ValidationException $e) {
            $request->increment('failed_attempts');
            throw $e;
        }
    }
}
