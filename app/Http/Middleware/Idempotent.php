<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honours an Idempotency-Key header on retry-prone writes (SRS §8.3):
 * the first successful response is stored for 24 h and replayed for the
 * same user + tenant + key, so an app retry never creates a duplicate.
 * A concurrent duplicate gets 409 while the first is still running.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || ! $request->isMethod('POST')) {
            return $next($request);
        }
        abort_unless(preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key), 422, 'Invalid Idempotency-Key.');

        $cacheKey = 'idem:' . sha1(implode('|', [CurrentTenant::id(), $request->user()?->id, $request->path(), $key]));
        $fingerprint = sha1(json_encode($request->all()));

        if ($stored = Cache::get($cacheKey)) {
            if ($stored['fingerprint'] !== $fingerprint) {
                return response()->json(['success' => false, 'code' => 'idempotency_conflict',
                    'message' => 'This Idempotency-Key was already used for a different request.'], 422);
            }

            return response($stored['body'], $stored['status'], $stored['headers'] + ['Idempotent-Replayed' => 'true']);
        }

        $lock = Cache::lock($cacheKey . ':lock', 30);
        if (! $lock->get()) {
            return response()->json(['success' => false, 'code' => 'request_in_progress',
                'message' => 'The same request is still being processed.'], 409);
        }

        try {
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status'      => $response->getStatusCode(),
                    'body'        => $response->getContent(),
                    'headers'     => ['Content-Type' => $response->headers->get('Content-Type')],
                ], now()->addDay());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
