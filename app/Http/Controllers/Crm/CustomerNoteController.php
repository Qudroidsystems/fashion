<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerNoteController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Update customer');
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'body'         => 'required|string|max:5000',
            'is_sensitive' => 'nullable|boolean',
            'is_pinned'    => 'nullable|boolean',
        ]);

        // Only people who may read sensitive notes may write them.
        $sensitive = $request->boolean('is_sensitive') && $request->user()->can('View customer sensitive notes');

        CustomerNote::create([
            'customer_id'  => $customer->id,
            'body'         => $data['body'],
            'is_sensitive' => $sensitive,
            'is_pinned'    => $request->boolean('is_pinned'),
            'created_by'   => $request->user()->id,
        ]);
        $customer->touchActivity();

        return redirect()->to(route('customers.show', $customer) . '#notes')->with('success', 'Note added.');
    }

    public function destroy(Request $request, Customer $customer, CustomerNote $note): RedirectResponse
    {
        abort_unless($note->customer_id === $customer->id, 404);
        abort_if($note->is_sensitive && ! $request->user()->can('View customer sensitive notes'), 404);
        abort_unless($note->created_by === $request->user()->id || $request->user()->can('Delete customer'), 403);

        AuditLogger::log('customer.note_deleted', $customer, before: ['note' => mb_substr($note->is_sensitive ? '(sensitive note)' : $note->body, 0, 200)]);
        $note->delete();

        return redirect()->to(route('customers.show', $customer) . '#notes')->with('success', 'Note removed.');
    }
}
