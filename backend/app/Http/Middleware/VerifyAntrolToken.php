<?php

namespace App\Http\Middleware;

use App\Services\AntrolTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware auth untuk WS Antrean sisi RS (dipanggil Mobile JKN / server BPJS).
 * Validasi header x-token + x-username (Antrol.md: header x-token, x-username).
 *
 * Respon gagal memakai ENVELOPE BPJS { metaData:{code,message}, response:null }
 * dengan code 201 (gagal) — bukan envelope internal — karena konsumennya BPJS.
 *
 * Endpoint /antrol/token TIDAK pakai middleware ini (ia memvalidasi x-username +
 * x-password sendiri untuk menerbitkan token).
 */
class VerifyAntrolToken
{
    public function __construct(private readonly AntrolTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token    = $request->header('x-token');
        $username = $request->header('x-username');

        if (! $this->tokens->validate($token, $username)) {
            return response()->json([
                'metaData' => ['code' => 201, 'message' => 'Token tidak valid atau kedaluwarsa.'],
                'response' => null,
            ], 200); // HTTP 200, status bisnis di metaData.code (pola BPJS)
        }

        return $next($request);
    }
}
