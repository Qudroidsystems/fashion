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
