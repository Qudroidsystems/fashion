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
