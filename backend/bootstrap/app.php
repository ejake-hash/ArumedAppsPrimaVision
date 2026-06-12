<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*'
        ]);

        $middleware->alias([
            'role'                => \App\Http\Middleware\RoleMiddleware::class,
            'permission'          => \App\Http\Middleware\PermissionMiddleware::class,
            // Antrol (Antrean Online BPJS, Sisi B): validasi header x-token Mobile JKN.
            'verify-antrol-token' => \App\Http\Middleware\VerifyAntrolToken::class,
            // Token mesin untuk bridge/watcher alat penunjang (integrasi DICOM).
            'service-token'       => \App\Http\Middleware\VerifyServiceToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Untuk request API/JSON: JANGAN bocorkan "server error" mentah ke user.
        // Ubah error DB / exception tak terduga jadi pesan POPUP yang ramah (Indonesia).
        // Validasi (422), auth (401/403), 404/405 dibiarkan default Laravel — pesannya
        // sudah ramah & dipakai FE (mis. errors[] untuk highlight field form).
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // request web biasa → handler default
            }
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            $debug = config('app.debug') ? $e->getMessage() : null;

            // Error database → bedakan duplikat (user bisa perbaiki) vs fault lain.
            if ($e instanceof \Illuminate\Database\QueryException) {
                \Illuminate\Support\Facades\Log::error('API DB error: ' . $e->getMessage());
                $msg = $e->getMessage();
                $isUnique = $e->getCode() === '23505'
                    || str_contains($msg, 'Unique violation')
                    || str_contains($msg, 'duplicate key');
                if ($isUnique) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data sudah ada (duplikat). Periksa kembali isian, lalu coba simpan lagi.',
                        'errors'  => null,
                        'debug'   => $debug,
                    ], 422);
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak dapat disimpan karena melanggar aturan data. Periksa kembali isian Anda.',
                    'errors'  => null,
                    'debug'   => $debug,
                ], 422);
            }

            // Exception tak terduga lain → 500 ramah (detail hanya saat APP_DEBUG; selalu di-log).
            \Illuminate\Support\Facades\Log::error('API unhandled error: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server. Silakan coba lagi; bila berlanjut hubungi admin.',
                'errors'  => null,
                'debug'   => $debug,
            ], 500);
        });
    })->create();
