<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only audit trail of the current fashion house (SRS FR-IAM-005). */
class AuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View audit log');
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'action'  => 'nullable|string|max:60',
            'user_id' => 'nullable|integer',
            'from'    => 'nullable|date',
            'to'      => 'nullable|date',
            'q'       => 'nullable|string|max:100',
        ]);

        $logs = AuditLog::with('user:id,name,email')
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v . '%'))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', \Carbon\Carbon::parse($v)->addDay()))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('description', 'like', '%' . $v . '%'))
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('audit.index', [
            'pagetitle' => 'Audit Log',
            'logs'      => $logs,
            'filters'   => $filters,
            'actions'   => AuditLog::select('action')->distinct()->orderBy('action')->pluck('action'),
            'users'     => User::inCurrentTenant()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
