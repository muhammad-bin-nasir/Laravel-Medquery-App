<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateAdminToken;
use App\Http\Middleware\AuthenticateTokenRefresh;
use App\Http\Middleware\AttachCorrelationId;
use App\Http\Middleware\InjectTenantContext;
use App\Http\Middleware\NormalizeAiErrorResponse;
use App\Http\Middleware\NormalizeApiErrorResponse;
use App\Http\Middleware\EnforceAppChatSettings;
use App\Http\Middleware\RequireActiveSubscription;
use App\Services\SiteLogService;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            AttachCorrelationId::class,
            NormalizeApiErrorResponse::class,
        ]);

        $middleware->alias([
            'admin.auth' => AuthenticateAdminToken::class,
            'token.refresh' => AuthenticateTokenRefresh::class,
            'tenant.context' => InjectTenantContext::class,
            'ai.error' => NormalizeAiErrorResponse::class,
            'subscription.active' => RequireActiveSubscription::class,
            'app.chat' => EnforceAppChatSettings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (\Throwable $e): void {
            try {
                app(SiteLogService::class)->recordException($e);
            } catch (\Throwable) {
                // Never let site-log persistence break exception reporting.
            }
        });
    })->create();
