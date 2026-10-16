<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spotlight (Ctrl/⌘+K) live search inside the current fashion house.
 * Add each new module's records here (customers, orders, styles…) as they land.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $user = $request->user();

        if (mb_strlen($q) < 2 || ! CurrentTenant::check()) {
            return response()->json(['results' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $results = collect();

        if ($user->can('View customer')) {
            \App\Models\Customer::where('status', '!=', 'merged')->search($q)->limit(8)
                ->get(['id', 'public_id', 'first_name', 'last_name', 'customer_number', 'phone'])
                ->each(fn ($c) => $results->push([
                    'title' => $c->name, 'url' => route('customers.show', $c), 'icon' => 'mdi-account-heart',
                    'category' => 'Customers', 'subtitle' => trim($c->customer_number . ' · ' . $c->phone, ' ·'),
                ]));
        }

        if ($user->can('View style')) {
            \App\Models\FashionStyle::where('status', 'active')->search($q)->limit(6)->get(['id', 'public_id', 'name', 'code'])
                ->each(fn ($s) => $results->push([
                    'title' => $s->name, 'url' => route('styles.show', $s), 'icon' => 'mdi-hanger',
                    'category' => 'Styles', 'subtitle' => $s->code,
                ]));
        }

        if ($user->can('View product')) {
            \App\Models\Product::where('status', 'active')->search($q)->limit(6)->get(['id', 'public_id', 'name', 'code'])
                ->each(fn ($p) => $results->push([
                    'title' => $p->name,
                    'url' => $user->can('Manage product') ? route('products.edit', $p) : route('products.index', ['q' => $p->code]),
                    'icon' => 'mdi-shopping', 'category' => 'Products', 'subtitle' => $p->code,
                ]));
        }

        if ($user->can('View order')) {
            \App\Models\Order::where(fn ($w) => $w->where('number', 'like', $like)->orWhere('title', 'like', $like))->with('customer:id,first_name,last_name')
                ->latest('id')->limit(6)->get()
                ->each(fn ($o) => $results->push([
                    'title' => $o->number . ($o->title ? ' · ' . $o->title : ''), 'url' => route('orders.show', $o), 'icon' => 'mdi-clipboard-text',
                    'category' => 'Orders', 'subtitle' => $o->customer?->name . ' · ' . $o->status_label,
                ]));
        }
        if ($user->can('Manage quotation')) {
            \App\Models\Quotation::where('number', 'like', $like)->limit(4)->get(['id', 'public_id', 'number', 'title'])
                ->each(fn ($q) => $results->push(['title' => $q->number, 'url' => route('quotations.show', $q), 'icon' => 'mdi-file-document-edit', 'category' => 'Quotations', 'subtitle' => $q->title]));
        }
        if ($user->can('View payment')) {
            \App\Models\Invoice::where('number', 'like', $like)->limit(4)->get(['id', 'public_id', 'number', 'total'])
                ->each(fn ($i) => $results->push(['title' => $i->number, 'url' => route('invoices.show', $i), 'icon' => 'mdi-receipt', 'category' => 'Invoices', 'subtitle' => \App\Support\Money::format($i->total)]));
            \App\Models\Payment::where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference', 'like', $like))->limit(4)->get(['id', 'public_id', 'number', 'amount', 'reference'])
                ->each(fn ($p) => $results->push(['title' => $p->number, 'url' => route('payments.show', $p), 'icon' => 'mdi-cash', 'category' => 'Payments', 'subtitle' => \App\Support\Money::format($p->amount) . ($p->reference ? ' · ' . $p->reference : '')]));
        }

        if ($user->can('View production')) {
            \App\Models\ProductionTask::where('number', 'like', $like)->with('order:id,number')->latest('id')->limit(4)->get()
                ->each(fn ($t) => $results->push(['title' => $t->number . ' · ' . $t->name, 'url' => route('production.tasks.show', $t), 'icon' => 'mdi-view-column', 'category' => 'Production', 'subtitle' => $t->order?->number . ' · ' . $t->status_label]));
            \App\Models\Fitting::where('number', 'like', $like)->with('customer:id,first_name,last_name')->latest('id')->limit(4)->get()
                ->each(fn ($f) => $results->push(['title' => $f->number, 'url' => route('fittings.show', $f), 'icon' => 'mdi-human-handsup', 'category' => 'Fittings', 'subtitle' => $f->customer?->name]));
        }

        if ($user->can('Use POS')) {
            \App\Models\Sale::where('number', 'like', $like)->latest('id')->limit(4)->get(['id', 'public_id', 'number', 'total', 'status'])
                ->each(fn ($s) => $results->push(['title' => $s->number, 'url' => route('pos.sales.show', $s), 'icon' => 'mdi-cash-register', 'category' => 'POS sales', 'subtitle' => \App\Support\Money::format($s->total) . ($s->status === 'voided' ? ' · voided' : '')]));
        }

        if ($user->can('View inventory')) {
            \App\Models\InventoryItem::active()->search($q)->limit(6)->get(['id', 'public_id', 'name', 'code', 'unit'])
                ->each(fn ($i) => $results->push([
                    'title' => $i->name, 'url' => route('inventory.items.show', $i), 'icon' => 'mdi-package-variant',
                    'category' => 'Stock', 'subtitle' => $i->code,
                ]));
            \App\Models\Supplier::active()->search($q)->limit(4)->get(['id', 'public_id', 'name', 'code'])
                ->each(fn ($s) => $results->push([
                    'title' => $s->name, 'url' => route('suppliers.edit', $s), 'icon' => 'mdi-truck',
                    'category' => 'Suppliers', 'subtitle' => $s->code,
                ]));
            \App\Models\PurchaseOrder::where('number', 'like', $like)->limit(4)->get(['id', 'public_id', 'number', 'status'])
                ->each(fn ($po) => $results->push([
                    'title' => $po->number, 'url' => route('purchase-orders.show', $po), 'icon' => 'mdi-file-document-outline',
                    'category' => 'Purchase orders', 'subtitle' => $po->status_label,
                ]));
        }

        if ($user->can('View user')) {
            User::inCurrentTenant()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone_number', 'like', $like))
                ->limit(6)->get(['id', 'name', 'email'])
                ->each(fn ($u) => $results->push([
                    'title' => $u->name, 'url' => route('users.show', $u->id), 'icon' => 'mdi-account',
                    'category' => 'Staff', 'subtitle' => $u->email,
                ]));
        }

        if ($user->can('View branch') || $user->can('Manage branch')) {
            Branch::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->limit(4)->get(['id', 'name', 'code'])
                ->each(fn ($b) => $results->push([
                    'title' => $b->name, 'url' => route('settings.branches.index'), 'icon' => 'mdi-store-marker',
                    'category' => 'Branches', 'subtitle' => $b->code,
                ]));
        }

        return response()->json(['results' => $results->values()]);
    }
}
