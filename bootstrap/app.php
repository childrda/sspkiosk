<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            require base_path('routes/kiosk.php');
            require base_path('routes/slack.php');
            require base_path('routes/admin.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('admin.login'));

        $middleware->validateCsrfTokens(except: [
            'slack/interactions',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->alias([
            'kiosk.signed' => \App\Http\Middleware\ValidateKioskRequest::class,
            'kiosk.web' => \App\Http\Middleware\EnsureKioskWebSession::class,
            'slack.signature' => \App\Http\Middleware\VerifySlackSignature::class,
            'admin' => \App\Http\Middleware\EnsureAdminUser::class,
            'reset.mode' => \App\Http\Middleware\EnsureResetPasswordModeConfigured::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response, \Throwable $e, \Illuminate\Http\Request $request) {
            if (
                $response->getStatusCode() === 419
                && $request->is('kiosk/*')
                && ! $request->expectsJson()
            ) {
                return redirect()
                    ->route('kiosk.reset.index')
                    ->with('error', 'This screen timed out. Please start again.');
            }

            return $response;
        });
    })->create();
