<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * In-app notifications (database channel) for the topbar bell. Only the
 * current fashion house's notifications (or platform-wide ones) are shown.
 *
 * Notification classes should put 'title', 'body', 'url' and 'icon' in
 * toDatabase(); set tenant_id via App\Notifications\Concerns (future).
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $items = $this->scoped($request)->latest()->paginate(25);

        return view('notifications.index', [
            'pagetitle' => 'Notifications',
            'items'     => $items,
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $items = $this->scoped($request)->latest()->limit(15)->get()->map(fn ($n) => [
            'id'    => $n->id,
            'title' => $n->data['title'] ?? 'Notification',
            'body'  => $n->data['body'] ?? '',
            'url'   => $n->data['url'] ?? route('notifications.index'),
            'icon'  => $n->data['icon'] ?? 'ri-notification-3-line',
            'time'  => $n->created_at->diffForHumans(),
            'read'  => (bool) $n->read_at,
        ]);

        return response()->json([
            'unread' => $this->scoped($request)->whereNull('read_at')->count(),
            'items'  => $items,
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->scoped($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    protected function scoped(Request $request)
    {
        $tenantId = CurrentTenant::id();

        return $request->user()->notifications()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }
}
