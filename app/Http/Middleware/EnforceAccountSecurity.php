<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAccountSecurity
{
    public function __construct(
        private SessionRegistry $sessions,
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($this->sessions->expired($request)) {
            $this->audit->record('auth.session.expired', $user, [], $request);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('ui.auth.session_expired'));
        }

        $this->sessions->touch($request);

        $route = $request->route()?->getName();

        if (
            $request->session()->get('view_as_user_id')
            && ! $request->isMethodSafe()
            && $route !== 'admin.view-as.stop'
        ) {
            abort(403);
        }

        if ($user->must_change_password && ! in_array($route, $this->passwordRoutes(), true)) {
            return redirect()->route('password.required');
        }

        if (! $user->must_change_password && $user->mustEnrollTwoFactor() && ! in_array($route, $this->enrollmentRoutes(), true)) {
            return redirect()->route('account.security');
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function passwordRoutes(): array
    {
        return [
            'password.required',
            'password.required.update',
            'logout',
            'locale.update',
        ];
    }

    /**
     * @return list<string>
     */
    private function enrollmentRoutes(): array
    {
        return [
            'account.security',
            'account.two-factor.enable',
            'account.two-factor.confirm',
            'logout',
            'locale.update',
        ];
    }
}
