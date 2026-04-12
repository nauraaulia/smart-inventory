<?php

namespace App\Http\Middleware;

use App\Models\UserLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!auth()->check()) {
            return $response;
        }

        $route = $request->route();
        $routeName = $route?->getName();
        $uri = $route?->uri() ?: $request->path();

        if ($this->shouldSkip($request, $routeName, $uri)) {
            return $response;
        }

        $method = strtoupper($request->method());
        $module = $this->resolveModule($routeName, $uri);

        UserLog::create([
            'user_id' => auth()->id(),
            'action' => 'Activity',
            'module' => $module,
            'description' => "{$method} {$uri}",
            'ip_address' => $request->ip(),
        ]);

        return $response;
    }

    private function shouldSkip(Request $request, ?string $routeName, string $uri): bool
    {
        if ($request->expectsJson()) {
            return true;
        }

        if (str_starts_with($uri, 'livewire') || str_starts_with($uri, '_debugbar')) {
            return true;
        }

        if ($routeName && str_contains($routeName, 'livewire')) {
            return true;
        }

        return false;
    }

    private function resolveModule(?string $routeName, string $uri): string
    {
        if (!empty($routeName)) {
            return str_replace(['.', '-', '_'], ' ', ucfirst($routeName));
        }

        $first = explode('/', trim($uri, '/'))[0] ?? 'General';

        return ucfirst($first);
    }
}
