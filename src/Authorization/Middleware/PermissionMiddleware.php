<?php

namespace Feeder\Core\Authorization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request,   Closure $next,    string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }


        if (!$user->hasPermission($permission)) {
            abort(403, 'Forbidden: You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
