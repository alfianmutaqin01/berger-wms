<?php

use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\TrackUserSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'session.track' => TrackUserSession::class,
            'portal' => EnsurePortalAccess::class,
        ]);

        // Cookie ini adalah token perangkat kami sendiri (lihat
        // AuthController::trackNewSession()), bukan cookie sesi Laravel —
        // dikecualikan dari enkripsi supaya nilainya bisa dibandingkan
        // langsung ke kolom user_sessions.session_id tanpa dekripsi.
        $middleware->encryptCookies(except: ['device_token']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
