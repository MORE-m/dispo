<?php

use App\Exceptions\CatalogAdminConflictException;
use App\Exceptions\FieldDefinitionConflictException;
use App\Exceptions\FieldSetAssignmentConflictException;
use App\Exceptions\PriceListAdminConflictException;
use App\Exceptions\PriceListSelectionConflictException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('queue:work --stop-when-empty --max-time=50 --timeout=45 --tries=3 --backoff=30')
            ->everyMinute()
            ->withoutOverlapping()
            ->name('queue-work-stop-when-empty');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Konflikte werden immer als JSON ausgeliefert, damit auch Inertia-Posts
        // die fachliche Meldung statt einer generischen 409-Fehlerseite erhalten.
        $exceptions->render(function (FieldSetAssignmentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        });

        $exceptions->render(function (CatalogAdminConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        });

        $exceptions->render(function (PriceListAdminConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        });

        $exceptions->render(function (PriceListSelectionConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        });

        $exceptions->render(function (FieldDefinitionConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        });
    })->create();
