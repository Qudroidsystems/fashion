<?php

namespace App\Services\Production;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Handover / collection (SRS FR-PRD-008, §6.2 steps 15–16). Only finished
 * garments can go. If money is still owed, the house setting decides: block
 * (default) or allow with the outstanding conditions written down.
 */
class DeliveryService
{
    public function __construct(protected ProductionService $production)
    {
    }

    /** $data: line_ids[], method, handed_over_at?, recipient_name, recipient_phone?, relationship?, confirmation, confirmation_ref?, outstanding_conditions?, address? */
    public function handOver(Order $order, array $data, User $by, ?UploadedFile $evidence = null): Delivery
    {
        if (! in_array($order->status, ['ready', 'in_production', 'fitting'], true)) {
            throw ValidationException::withMessages(['order' => 'Only orders with finished garments can be handed over.']);
        }
        $lines = $order->lines()->whereIn('id', $data['line_ids'] ?? [])->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['line_ids' => 'Tick the garments being handed over.']);
        }
        if ($notReady = $lines->firstWhere('status', '!=', 'ready')) {
            throw ValidationException::withMessages(['line_ids' => "“{$notReady->description}” is not ready yet."]);
        }
        $balance = $order->balance();
        $tenant = CurrentTenant::get();
        if ($balance > 0.004) {
            if (! $tenant->setting('release_with_balance', false)) {
                throw ValidationException::withMessages(['balance' => 'The customer still owes ' . Money::format($balance) . '. Take the payment first, or allow handover with a balance in House settings.']);
            }
            if (! trim((string) ($data['outstanding_conditions'] ?? ''))) {
                throw ValidationException::withMessages(['outstanding_conditions' => 'Write down how and when the ' . Money::format($balance) . ' still owed will be paid.']);
            }
        }
        if (($data['confirmation'] ?? 'none') === 'photo' && ! $evidence) {
            throw ValidationException::withMessages(['evidence' => 'Add the photo.']);
        }

        return DB::transaction(function () use ($order, $lines, $data, $by, $balance, $evidence, $tenant) {
            $path = $evidence?->storeAs('tenants/' . $tenant->public_id . '/deliveries', Str::random(12) . '.' . $evidence->extension(), 'public');
            $delivery = Delivery::create([
                'number' => DocumentNumber::next('delivery'), 'order_id' => $order->id, 'line_ids' => $lines->pluck('id')->all(),
                'method' => $data['method'] ?? 'collection',
                'handed_over_at' => ! empty($data['handed_over_at']) ? Carbon::parse($data['handed_over_at'], $tenant->timezone)->utc() : now(),
                'recipient_name' => $data['recipient_name'], 'recipient_phone' => $data['recipient_phone'] ?? null,
                'relationship' => $data['relationship'] ?? null, 'confirmation' => $data['confirmation'] ?? 'none',
                'confirmation_ref' => $data['confirmation_ref'] ?? null, 'evidence_path' => $path,
                'balance_at_handover' => max(0, $balance), 'outstanding_conditions' => $data['outstanding_conditions'] ?? null,
                'address' => $data['address'] ?? null, 'user_id' => $by->id,
            ]);
            $order->lines()->whereIn('id', $lines->pluck('id'))->update(['status' => 'delivered']);

            $remaining = $order->lines()->whereNotIn('status', ['delivered', 'cancelled'])->count();
            if ($remaining === 0) {
                // All garments gone: the order is delivered (ready → delivered, via ready if needed).
                $this->production->moveOrder($order->fresh(), 'delivered', ($delivery->method === 'delivery' ? 'Delivered to ' : 'Collected by ') . $delivery->recipient_name);
            }
            AuditLogger::log('order.handed_over', $order, after: [
                'delivery' => $delivery->number, 'lines' => $lines->pluck('description')->all(), 'recipient' => $delivery->recipient_name,
                'balance' => $delivery->balance_at_handover,
            ], description: $delivery->outstanding_conditions);

            return $delivery;
        });
    }
}
