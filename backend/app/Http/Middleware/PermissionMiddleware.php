<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Gate berdasarkan permission key.
     * Usage di route: ->middleware('permission:admisi.write')
     *                 ->middleware('permission:admisi.write|kasir.write')   (OR)
     *
     * Superadmin selalu lolos.
     */
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Unauthenticated',
                'errors'  => null,
            ], 401);
        }

        if ($user->isSuperadmin()) {
            return $next($request);
        }

        // Dukung "key1|key2" sebagai OR di-dalam satu argumen.
        $flat = [];
        foreach ($keys as $k) {
            foreach (explode('|', $k) as $part) {
                $part = trim($part);
                if ($part !== '') $flat[] = $part;
            }
        }

        foreach ($flat as $key) {
            if ($user->hasPermission($key)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => 'Akses ditolak — permission tidak mencukupi',
            'errors'  => ['required_any' => $flat],
        ], 403);
    }
}
