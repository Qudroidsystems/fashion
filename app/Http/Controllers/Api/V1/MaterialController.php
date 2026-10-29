<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerMaterial;
use App\Models\MessageLog;
use App\Models\Order;
use App\Services\Materials\MaterialService;
use App\Services\Messaging\Messenger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer materials for the staff app.
 *   GET  /materials?show=with_house|overdue|returned|all&q=&customer=&order=   ·  GET /materials/meta
 *   GET  /materials/lookup?code=   (a scanned tag: its link or number)
 *   GET  /materials/{id}  ·  POST /materials (multipart, photos[])  ·  PATCH /materials/{id}
 *   POST /materials/{id}/photos · /move · /close · /notes   ·  POST /materials/return
 *   POST /customers/{customer}/materials/remind
 */
class MaterialController extends Controller
{
    public function __construct(protected MaterialService $materials, protected Messenger $messenger)
    {
        $this->middleware('permission:View customer materials', ['only' => ['index', 'show', 'lookup', 'meta']]);
        $this->middleware('permission:Manage customer materials', ['except' => ['index', 'show', 'lookup', 'meta']]);
    }

    public function meta(): JsonResponse
    {
        $opts = fn (array $a) => collect($a)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values();

        return response()->json(['success' => true, 'data' => [
            'kinds' => $opts(CustomerMaterial::KINDS), 'purposes' => $opts(CustomerMaterial::PURPOSES), 'units' => $opts(CustomerMaterial::UNITS),
            'channels' => collect($this->messenger->available())->map(fn ($ok, $k) => ['value' => $k, 'label' => MessageLog::CHANNELS[$k], 'ready' => $ok])->values(),
            'settings' => $this->materials->settings(), 'max_photos' => MaterialService::MAX_PHOTOS,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['show' => 'nullable|in:with_house,overdue,returned,all', 'q' => 'nullable|string|max:100']);
        $show = $request->query('show', 'with_house');
        $overdue = $this->materials->overdue()->pluck('id');
        $q = trim((string) $request->query('q'));
        $page = CustomerMaterial::with('customer', 'order:id,public_id,number,status', 'branch:id,name')
            ->when($show === 'with_house', fn ($w) => $w->withHouse())
            ->when($show === 'overdue', fn ($w) => $w->whereIn('id', $overdue))
            ->when($show === 'returned', fn ($w) => $w->whereNotIn('status', CustomerMaterial::WITH_HOUSE))
            ->when($request->filled('customer'), fn ($w) => $w->whereHas('customer', fn ($c) => $c->where('public_id', $request->query('customer'))))
            ->when($request->filled('order'), fn ($w) => $w->whereHas('order', fn ($o) => $o->where('public_id', $request->query('order'))))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")->orWhere('location', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))))
            ->orderByRaw("case when status in ('held','in_use') then 0 else 1 end")->latest('received_at')->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($m) => $this->summary($m, $overdue->contains($m->id))),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'counts' => ['with_house' => CustomerMaterial::withHouse()->count(), 'overdue' => $overdue->count()]],
        ]);
    }

    /** A scanned tag: the link printed in its QR code, or the number typed / read. */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->validate(['code' => 'required|string|max:300'])['code']);
        $ref = preg_match('~/materials/([0-9A-Za-z]{26})~', $code, $mm) ? $mm[1] : $code;
        $m = CustomerMaterial::where('public_id', $ref)->orWhere('number', strtoupper($ref))->first();
        abort_unless($m, 404, 'No item with that tag in this house.');

        return $this->show($m);
    }

    public function show(CustomerMaterial $material): JsonResponse
    {
        $material->load('customer', 'order', 'line', 'branch', 'receiver:id,name', 'returner:id,name', 'delivery', 'events.user:id,name');
        $tz = $this->materials->tz();
        $due = $this->materials->dueOn($material);
        $next = $this->materials->nextReminder($material);
        [, $text] = $this->materials->message($material->customer, collect([$material]));
        $iso = fn ($t) => $t?->copy()->timezone($tz)->toIso8601String();

        return response()->json(['success' => true, 'data' => $this->summary($material, $due && $due->lte(now($tz)->startOfDay())) + [
            'condition_notes' => $material->condition_notes, 'photos' => $material->photoUrls(),
            'order_line' => $material->line?->description, 'received_by' => $material->receiver?->name,
            'returned_at' => $iso($material->returned_at), 'returned_by' => $material->returner?->name, 'returned_to' => $material->returned_to,
            'returned_to_phone' => $material->returned_to_phone, 'returned_quantity' => $material->returned_quantity, 'return_note' => $material->return_note,
            'delivery' => $material->delivery?->number,
            'due_on' => $due?->toDateString(), 'next_reminder' => $next?->toDateString(),
            'reminders_sent' => $material->reminders_sent, 'last_reminded_at' => $iso($material->last_reminded_at),
            'whatsapp_link' => Messenger::whatsAppLink($material->customer, $text),
            'others' => CustomerMaterial::withHouse()->where('customer_id', $material->customer_id)->whereKeyNot($material->id)->get()->map(fn ($o) => $this->summary($o))->values(),
            'events' => $material->events->map(fn ($e) => [
                'event' => $e->event, 'label' => $e->label, 'detail' => $e->detail, 'by' => $e->user?->name, 'at' => $iso($e->created_at),
            ])->values(),
            'messages' => MessageLog::where('customer_id', $material->customer_id)->where('purpose', 'material_reminder')->latest('id')->limit(10)->get()
                ->map(fn ($l) => ['channel' => $l->channel, 'channel_label' => MessageLog::CHANNELS[$l->channel] ?? $l->channel, 'status' => $l->status, 'error' => $l->error, 'at' => $iso($l->created_at)])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['customer_id'] = $this->customer($data['customer'])->id;
        $m = $this->materials->receive($data, $request->user(), $request->file('photos', []));

        return $this->show($m)->setStatusCode(201);
    }

    public function update(Request $request, CustomerMaterial $material): JsonResponse
    {
        $data = $this->validated($request, false);
        $this->materials->update($material, $data, $request->user());

        return $this->show($material->fresh());
    }

    public function photos(Request $request, CustomerMaterial $material): JsonResponse
    {
        $request->validate(['photos' => 'required|array|min:1|max:' . MaterialService::MAX_PHOTOS, 'photos.*' => 'image|max:8192']);
        $this->materials->update($material, [], $request->user(), $request->file('photos', []));

        return $this->show($material->fresh());
    }

    public function move(Request $request, CustomerMaterial $material): JsonResponse
    {
        $data = $request->validate(['to' => 'required|in:in_use,held', 'location' => 'nullable|string|max:120']);
        $this->materials->move($material, $data['to'], $data['location'] ?? null, $request->user());

        return $this->show($material->fresh());
    }

    public function giveBack(Request $request): JsonResponse
    {
        $data = $request->validate([
            'materials' => 'required|array|min:1', 'materials.*' => 'string',
            'returned_to' => 'required|string|max:120', 'returned_to_phone' => 'nullable|string|max:30',
            'note' => 'nullable|string|max:255', 'quantities' => 'nullable|array', 'quantities.*' => 'nullable|numeric|min:0',
        ]);
        $items = CustomerMaterial::whereIn('public_id', $data['materials'])->get();
        abort_if($items->count() !== count(array_unique($data['materials'])), 404, 'Unknown item.');
        $this->materials->giveBack($items, $data, $request->user());

        return response()->json(['success' => true, 'data' => ['returned' => $items->pluck('public_id')]]);
    }

    public function close(Request $request, CustomerMaterial $material): JsonResponse
    {
        $data = $request->validate(['how' => 'required|in:used,written_off', 'note' => 'nullable|string|max:255']);
        $this->materials->close($material, $data['how'], (string) ($data['note'] ?? ''), $request->user());

        return $this->show($material->fresh());
    }

    public function note(Request $request, CustomerMaterial $material): JsonResponse
    {
        $this->materials->note($material, $request->validate(['note' => 'required|string|max:255'])['note'], $request->user());

        return $this->show($material->fresh());
    }

    public function remind(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate(['channels' => 'required|array|min:1', 'channels.*' => 'in:email,sms,whatsapp']);
        $logs = $this->materials->remind($customer, $data['channels'], $request->user());

        return response()->json(['success' => true, 'data' => [
            'sent' => $logs->where('status', 'sent')->pluck('channel')->values(),
            'results' => $logs->map(fn ($l) => ['channel' => $l->channel, 'label' => MessageLog::CHANNELS[$l->channel], 'status' => $l->status, 'error' => $l->error])->values(),
        ]]);
    }

    /* ------------------------------------------------------------------ */

    protected function summary(CustomerMaterial $m, bool $overdue = false): array
    {
        $c = $m->customer;

        return [
            'id' => $m->public_id, 'number' => $m->number, 'status' => $m->status, 'status_label' => $m->status_label,
            'with_house' => $m->isWithHouse(), 'overdue' => $overdue,
            'kind' => $m->kind, 'kind_label' => $m->kind_label, 'purpose' => $m->purpose, 'purpose_label' => CustomerMaterial::PURPOSES[$m->purpose] ?? $m->purpose,
            'description' => $m->description, 'colour' => $m->colour, 'quantity' => $m->quantity, 'unit' => $m->unit, 'summary' => $m->summary,
            'location' => $m->location, 'branch' => $m->branch?->name, 'collect_by' => $m->collect_by?->toDateString(),
            'received_at' => $m->received_at->copy()->timezone($this->materials->tz())->toIso8601String(), 'days_held' => $m->daysHeld(),
            'photo' => $m->photoUrls()[0] ?? null, 'photo_count' => count($m->photos ?? []),
            'customer' => $c ? ['id' => $c->public_id, 'name' => $c->name, 'phone' => $c->phone] : null,
            'order' => $m->order ? ['id' => $m->order->public_id, 'number' => $m->order->number, 'status' => $m->order->status] : null,
        ];
    }

    protected function customer(string $ref): Customer
    {
        return Customer::where('public_id', $ref)->firstOrFail();
    }

    protected function validated(Request $request, bool $creating): array
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'customer' => [$creating ? 'required' : 'prohibited', 'string'],
            'order' => ['nullable', 'string'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(CustomerMaterial::KINDS))],
            'purpose' => ['sometimes', Rule::in(array_keys(CustomerMaterial::PURPOSES))],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'colour' => 'nullable|string|max:60',
            'quantity' => 'nullable|numeric|min:0|max:99999',
            'unit' => ['nullable', Rule::in(array_keys(CustomerMaterial::UNITS))],
            'condition_notes' => 'nullable|string|max:2000',
            'location' => 'nullable|string|max:120',
            'collect_by' => 'nullable|date',
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'photos' => 'nullable|array|max:' . MaterialService::MAX_PHOTOS,
            'photos.*' => 'image|max:8192',
        ]);
        if ($request->exists('order')) {
            $data['order_id'] = $request->filled('order') ? (Order::where('public_id', $request->input('order'))->value('id') ?? abort(422, 'Unknown order.')) : null;
        }
        unset($data['order'], $data['photos']);

        return $data;
    }
}
