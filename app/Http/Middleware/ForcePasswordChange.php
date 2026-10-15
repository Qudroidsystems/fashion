<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** A user with a temporary password must choose their own before doing anything else. */
class ForcePasswordChange
{
    protected array $allowed = ['profile.settings', 'profile.update-password', 'logout'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs(...$this->allowed)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message'  => 'Please change your temporary password first.',
                    'redirect' => route('profile.settings'),
                ], 403);
            }

            return redirect()->route('profile.settings', ['tab' => 'security'])
                ->with('warning', 'Please choose your own password to continue.');
        }

        return $next($request);
    }
}
