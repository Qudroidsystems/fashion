<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Models\FollowUpTask;
use App\Services\Audit\AuditLogger;
use App\Services\Crm\CustomerService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SRS §8.2 Customers:
 *   GET/POST /customers · GET/PATCH /customers/{id} · GET /customers/{id}/timeline
 *   POST /customers/{id}/follow-ups
 * {id} is the customer's public_id (ULID).
 */
class CustomerController extends Controller
{
    public function __construct(protected CustomerService $service)
    {
        $this->middleware('permission:View customer', ['only' => ['index', 'show', 'timeline']]);
        $this->middleware('permission:Update customer', ['only' => ['storeFollowUp']]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:100']);

        $page = Customer::active()->search($request->q)->with('tags:id,name,color')
            ->latest('id')->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($c) => $this->summary($c)),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $data = $request->customerData();

        if (! $request->boolean('confirm_duplicate')) {
            $dupes = $this->service->findDuplicates($data);
            if ($dupes->isNotEmpty()) {
                return response()->json([
                    'success'    => false,
                    'code'       => 'possible_duplicate',
                    'message'    => 'This looks like an existing customer. Send confirm_duplicate=true to create anyway.',
                    'duplicates' => $dupes->map(fn ($c) => $this->summary($c) + ['match' => $c->match_reasons])->values(),
                ], 409);
            }
        }

        $customer = DB::transaction(function () use ($request, $data) {
            $customer = Customer::create($data);
            $this->service->syncTags($customer, $request->tagNames());
            $this->service->syncConsents($customer, (array) $request->input('consents', []), 'app');

            return $customer;
        });
        AuditLogger::log('customer.created', $customer, after: $customer->only(['customer_number', 'first_name', 'last_name']));

        return response()->json(['success' => true, 'data' => $this->detail($customer->fresh())], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->detail($customer)]);
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        abort_if($customer->status === 'merged', 409, 'This customer was merged into another record.');

        $data = $request->customerData();
        DB::transaction(function () use ($request, $customer, $data) {
            $customer->update($data);
            if ($request->has('tags')) {
                $this->service->syncTags($customer, $request->tagNames());
            }
            if ($request->has('consents')) {
                $this->service->syncConsents($customer, (array) $request->input('consents'), 'app');
            }
        });
        AuditLogger::log('customer.updated', $customer, description: 'Updated from the app');

        return response()->json(['success' => true, 'data' => $this->detail($customer->fresh())]);
    }

    public function timeline(Request $request, Customer $customer): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->timeline($customer, $request->user())->map(fn ($i) => [
                'type' => $i['type'], 'title' => $i['title'], 'body' => $i['body'],
                'by' => $i['by'], 'at' => $i['at']->toIso8601String(),
            ]),
        ]);
    }

    public function storeFollowUp(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:160',
            'details'     => 'nullable|string|max:2000',
            'due_at'      => 'nullable|date',
            'assigned_to' => ['nullable', Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', CurrentTenant::id())],
        ]);

        $task = FollowUpTask::create([
            'customer_id' => $customer->id,
            'title'       => $data['title'],
            'details'     => $data['details'] ?? null,
            'due_at'      => isset($data['due_at']) ? Carbon::parse($data['due_at'])->utc() : null,
            'assigned_to' => $data['assigned_to'] ?? $request->user()->id,
            'status'      => 'open',
            'created_by'  => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => $task->only(['id', 'title', 'details', 'due_at', 'assigned_to', 'status'])], 201);
    }

    protected function summary(Customer $c): array
    {
        return [
            'id'              => $c->public_id,
            'customer_number' => $c->customer_number,
            'name'            => $c->full_name,
            'phone'           => $c->phone,
            'email'           => $c->email,
            'is_vip'          => $c->is_vip,
            'tags'            => $c->relationLoaded('tags') ? $c->tags->pluck('name') : [],
        ];
    }

    protected function detail(Customer $c): array
    {
        $c->loadMissing(['tags', 'consents', 'branch']);

        return $this->summary($c) + [
            'title' => $c->title, 'first_name' => $c->first_name, 'last_name' => $c->last_name,
            'gender' => $c->gender, 'whatsapp' => $c->whatsapp, 'alt_phone' => $c->alt_phone,
            'birthday' => $c->birthday?->format('Y-m-d'), 'address' => $c->address, 'city' => $c->city, 'state' => $c->state,
            'occupation' => $c->occupation, 'source' => $c->source, 'preferences' => $c->preferences,
            'branch' => $c->branch?->only(['id', 'name']), 'status' => $c->status,
            'consents' => $c->consents->map(fn ($x) => ['channel' => $x->channel, 'purpose' => $x->purpose, 'granted' => $x->granted]),
            'created_at' => $c->created_at->toIso8601String(),
        ];
    }
}
