<?php

use App\Http\Middleware\AccountUsable;
use App\Http\Middleware\Authenticated;
use App\Http\Middleware\NotBanned;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Pas de page de login : un invité non authentifié reçoit une erreur, jamais une redirection.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*', 'mcp/*') ? null : '/');
        $middleware->alias([
            'jwt.auth' => Authenticated::class,
            'not_banned' => NotBanned::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'account.usable' => AccountUsable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API et MCP : toujours des erreurs JSON (401, 403, 422…), jamais de redirection vers une page
        // de login qui n'existe pas (ce qui finissait en 500 pour un client sans en-tête Accept).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'mcp/*') || $request->expectsJson());
    })->create();
