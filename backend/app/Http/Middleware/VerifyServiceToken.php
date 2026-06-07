<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware auth untuk endpoint MESIN integrasi penunjang (/integrasi/penunjang/*),
 * dipanggil bridge/feeder/watcher alat (Orthanc/OCT/USG) — BUKAN login manusia (JWT).
 *
 * Validasi `Authorization: Bearer <token>` (atau header `X-Service-Token`) terhadap
 * config('services.penunjang_bridge.token') ← .env PENUNJANG_BRIDGE_TOKEN, pakai
 * hash_equals (konstanta waktu). Balikan envelope internal { success:false, ... } 401.
 */
class VerifyServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.penunjang_bridge.token', '');

        $provided = $request->bearerToken() ?: (string) $request->header('X-Service-Token', '');

        if ($expected === '' || ! hash_equals($expected, (string) $provided)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Service token tidak valid.',
                'errors'  => null,
            ], 401);
        }

        return $next($request);
    }
}
