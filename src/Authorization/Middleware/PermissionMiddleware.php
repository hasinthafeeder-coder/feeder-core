<?php

namespace Feeder\Core\Authorization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $required = array_values(array_filter(array_map(
            static fn (string $slug) => trim($slug),
            explode('|', $permission),
        )));

        if ($required === []) {
            abort(403, 'Forbidden: You do not have permission to access this resource.');
        }

        foreach ($required as $slug) {
            if ($user->hasPermission($slug)) {
                return $next($request);
            }
        }

        abort(403, 'Forbidden: You do not have permission to access this resource.');
    }
}
