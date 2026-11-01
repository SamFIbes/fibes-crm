<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access\AccessGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private AccessGate $access) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $this->access->effective($request);

        if (! $user instanceof User) {
            abort(403);
        }

        foreach ($permissions as $permission) {
            if ($this->access->allows($user, $permission)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
