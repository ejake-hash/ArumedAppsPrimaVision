<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Gate berdasarkan nama role.
     * Usage di route: ->middleware('role:superadmin')
     *                 ->middleware('role:superadmin,admisi')
     *
     * Superadmin selalu lolos (regardless of allowed list).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
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

        $roleName = $user->role?->name;

        if ($roleName === 'superadmin') {
            return $next($request);
        }

        if (! in_array($roleName, $roles, true)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Akses ditolak — role Anda tidak diizinkan',
                'errors'  => null,
            ], 403);
        }

        return $next($request);
    }
}
