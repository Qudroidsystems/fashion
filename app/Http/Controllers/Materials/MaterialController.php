<?php

namespace App\Http\Controllers\Materials;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMaterial;
use App\Models\MessageLog;
use App\Models\Order;
use App\Services\Materials\MaterialService;
use App\Services\Messaging\Messenger;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/** Customer materials on the website: register, receive, tag, move, give back, remind, settings. */
class MaterialController extends Controller
{
    public function __construct(protected MaterialService $materials, protected Messenger $messenger)
    {
        $this->middleware('permission:View customer materials', ['only' => ['index', 'show', 'tag']]);
        $this->middleware('permission:Manage customer materials', ['except' => ['index', 'show', 'tag']]);
    }

    public function index(Request $request): View
    {
        $show = $request->query('show', 'with_house');
        $q = trim((string) $request->query('q'));
        $overdueIds = $this->materials->overdue()->pluck('id');
        $rows = CustomerMaterial::with('customer:id,public_id,first_name,last_name,phone,customer_number', 'order:id,public_id,number,status', 'branch:id,name')
            ->when($show === 'with_house', fn ($w) => $w->withHouse())
            ->when($show === 'overdue', fn ($w) => $w->whereIn('id', $overdueIds))
            ->when($show === 'returned', fn ($w) => $w->whereNotIn('status', CustomerMaterial::WITH_HOUSE))
            ->when($request->filled('branch_id'), fn ($w) => $w->where('branch_id', $request->integer('branch_id')))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")
                ->orWhere('location', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))))
            ->orderByRaw("case when status in ('held','in_use') then 0 else 1 end")->latest('received_at')->paginate(30)->withQueryString();

        return view('materials.index', [
            'rows' => $rows, 'show' => $show, 'q' => $q, 'overdueIds' => $overdueIds->flip(), 'tz' => $this->materials->tz(),
            'counts' => [
                'with_house' => CustomerMaterial::withHouse()->count(),
                'overdue'    => $overdueIds->count(),
                'customers'  => CustomerMaterial::withHouse()->distinct()->count('customer_id'),
                'returned_month' => CustomerMaterial::where('status', 'returned')->where('returned_at', '>=', now($this->materials->tz())->startOfMonth()->utc())->count(),
            ],
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
            'settings' => $this->materials->settings(),
            'channels' => $this->messenger->available(),
        ]);
    }

    public function create(Request $request): View
    {
        $customer = $request->filled('customer') ? Customer::where('public_id', $request->query('customer'))->first() : null;
        $order = $request->filled('order') ? Order::where('public_id', $request->query('order'))->first() : null;
        $customer ??= $order?->customer;

        return view('materials.form', [
            'material' => new CustomerMaterial(['kind' => 'fabric', 'purpose' => 'sew', 'unit' => 'yd', 'customer_id' => $customer?->id, 'order_id' => $order?->id]),
            'customer' => $customer, 'order' => $order,
            'customers' => $customer ? collect() : Customer::where('status', 'active')->orderBy('first_name')->limit(1000)->get(['id', 'first_name', 'last_name', 'customer_number', 'phone']),
            'orders' => $customer ? Order::where('customer_id', $customer->id)->whereNotIn('status', ['closed', 'cancelled'])->latest('id')->get(['id', 'number', 'title', 'status']) : collect(),
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $m = $this->materials->receive($data, $request->user(), $request->file('photos', []));

        return redirect()->route('materials.show', $m)->with('success', "Received and tagged {$m->number}. Print the tag and attach it.")->with('print_tag', true);
    }

    public function show(CustomerMaterial $material): View
    {
        $material->load('customer', 'order', 'line', 'branch', 'receiver:id,name', 'returner:id,name', 'delivery', 'events.user:id,name');
        $c = $material->customer;
        [, $text] = $this->materials->message($c, collect([$material]));

        return view('materials.show', [
            'm' => $material, 'tz' => $this->materials->tz(),
            'due' => $this->materials->dueOn($material), 'next' => $this->materials->nextReminder($material),
            'others' => CustomerMaterial::withHouse()->where('customer_id', $c->id)->whereKeyNot($material->id)->get(),
            'messages' => MessageLog::where('customer_id', $c->id)->where('purpose', 'material_reminder')->latest('id')->limit(15)->get(),
            'channels' => $this->messenger->available(),
            'waLink' => Messenger::whatsAppLink($c, $text),
            'settings' => $this->materials->settings(),
        ]);
    }

    public function edit(CustomerMaterial $material): View
    {
        return view('materials.form', [
            'material' => $material, 'customer' => $material->customer, 'order' => $material->order, 'customers' => collect(),
            'orders' => Order::where('customer_id', $material->customer_id)->latest('id')->get(['id', 'number', 'title', 'status']),
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, CustomerMaterial $material): RedirectResponse
    {
        $data = $this->validated($request, false);
        $this->materials->update($material, $data, $request->user(), $request->file('photos', []));

        return redirect()->route('materials.show', $material)->with('success', 'Saved.');
    }

    /** Printable tag to pin to the item: number, owner, what it is, and a QR code that opens this record. */
    public function tag(CustomerMaterial $material): View
    {
        $material->load('customer', 'order');

        return view('materials.tag', [
            'm' => $material, 'house' => CurrentTenant::require(), 'tz' => $this->materials->tz(),
            'qr' => (string) QrCode::format('svg')->size(220)->margin(0)->errorCorrection('M')->generate(route('materials.show', $material)),
        ]);
    }

    public function move(Request $request, CustomerMaterial $material): RedirectResponse
    {
        $data = $request->validate(['to' => 'required|in:in_use,held', 'location' => 'nullable|string|max:120']);
        $this->materials->move($material, $data['to'], $data['location'] ?? null, $request->user());

        return back()->with('success', $data['to'] === 'in_use' ? 'Marked as in the workroom.' : 'Back in store.');
    }

    /** Give one or more of a customer's items back. */
    public function giveBack(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'materials' => 'required|array|min:1', 'materials.*' => 'string',
            'returned_to' => 'required|string|max:120', 'returned_to_phone' => 'nullable|string|max:30',
            'note' => 'nullable|string|max:255', 'quantities' => 'nullable|array', 'quantities.*' => 'nullable|numeric|min:0',
            'returned_at' => 'nullable|date|before_or_equal:now',
        ]);
        $items = CustomerMaterial::whereIn('public_id', $data['materials'])->get();
        abort_if($items->count() !== count(array_unique($data['materials'])), 404);
        $this->materials->giveBack($items, $data, $request->user());

        return redirect()->route('materials.show', $items->first())->with('success', $items->count() === 1 ? 'Given back.' : $items->count() . ' items given back.');
    }

    public function close(Request $request, CustomerMaterial $material): RedirectResponse
    {
        $data = $request->validate(['how' => 'required|in:used,written_off', 'note' => 'nullable|string|max:255']);
        $this->materials->close($material, $data['how'], (string) ($data['note'] ?? ''), $request->user());

        return back()->with('success', $data['how'] === 'used' ? 'Marked as used up.' : 'Written off.');
    }

    public function note(Request $request, CustomerMaterial $material): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|max:255']);
        $this->materials->note($material, $data['note'], $request->user());

        return back()->with('success', 'Note added.');
    }

    public function photos(Request $request, CustomerMaterial $material): RedirectResponse
    {
        $request->validate(['photos' => 'required|array|min:1|max:' . MaterialService::MAX_PHOTOS, 'photos.*' => 'image|max:8192']);
        $this->materials->update($material, [], $request->user(), $request->file('photos', []));

        return back()->with('success', 'Photo added.');
    }

    public function removePhoto(Request $request, CustomerMaterial $material, int $index): RedirectResponse
    {
        $this->materials->removePhoto($material, $index, $request->user());

        return back()->with('success', 'Photo removed.');
    }

    /** Send the customer a reminder now, about everything of theirs still here. */
    public function remind(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['channels' => 'required|array|min:1', 'channels.*' => 'in:email,sms,whatsapp']);
        $logs = $this->materials->remind($customer, $data['channels'], $request->user());
        $sent = $logs->where('status', 'sent');
        $failed = $logs->where('status', '!=', 'sent')->map(fn ($l) => MessageLog::CHANNELS[$l->channel] . ': ' . $l->error);

        return back()->with($sent->isNotEmpty() ? 'success' : 'error',
            ($sent->isNotEmpty() ? 'Reminder sent by ' . $sent->pluck('channel')->map(fn ($c) => MessageLog::CHANNELS[$c])->implode(', ') . '.' : 'Nothing was sent.')
            . ($failed->isNotEmpty() ? ' ' . $failed->implode(' · ') : ''));
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reminders' => 'nullable|boolean', 'staff_digest' => 'nullable|boolean',
            'channels' => 'nullable|array', 'channels.*' => 'in:email,sms,whatsapp',
            'unlinked_days' => 'required|integer|min:1|max:365', 'remind_after_days' => 'required|integer|min:0|max:90',
            'repeat_days' => 'required|integer|min:1|max:90', 'max_reminders' => 'required|integer|min:0|max:20',
        ]);
        $this->materials->saveSettings($data);

        return back()->with('success', 'Reminder settings saved.');
    }

    protected function validated(Request $request, bool $creating): array
    {
        $tenant = CurrentTenant::id();

        return $request->validate([
            'customer_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('tenant_id', $tenant)],
            'order_line_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'kind' => ['required', Rule::in(array_keys(CustomerMaterial::KINDS))],
            'purpose' => ['required', Rule::in(array_keys(CustomerMaterial::PURPOSES))],
            'description' => 'required|string|max:160',
            'colour' => 'nullable|string|max:60',
            'quantity' => 'nullable|numeric|min:0|max:99999',
            'unit' => ['nullable', Rule::in(array_keys(CustomerMaterial::UNITS))],
            'condition_notes' => 'nullable|string|max:2000',
            'location' => 'nullable|string|max:120',
            'collect_by' => 'nullable|date',
            'received_at' => 'nullable|date|before_or_equal:now',
            'photos' => 'nullable|array|max:' . MaterialService::MAX_PHOTOS,
            'photos.*' => 'image|max:8192',
        ]);
    }
}
