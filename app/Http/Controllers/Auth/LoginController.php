<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafeEmail;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginGuard;
use App\Services\Auth\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request, LoginGuard $guard, AuditLogger $audit, SessionRegistry $sessions): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255', new SafeEmail],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = Str::lower($request->string('email')->toString());
        $guard->ensureCanAttempt($request, $email);

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $password = $request->string('password')->toString();

        // Always run one password hash, so an unknown email takes as long as a
        // wrong password and the response time doesn't reveal who has an account.
        $matches = Hash::check($password, $user?->password ?? $this->dummyHash());

        if (! $user || ! $matches) {
            $guard->recordFailure($request, $email, $user);

            throw ValidationException::withMessages([
                'email' => __('ui.auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            $guard->clear($request, $email);

            throw ValidationException::withMessages([
                'email' => __('ui.auth.inactive'),
            ]);
        }

        $remember = $request->boolean('remember');

        if ($user->hasTwoFactorEnabled()) {
            // Failure counters are only cleared once the second step succeeds.
            $request->session()->regenerate();
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $remember);
            $request->session()->put('login.at', now()->getTimestamp());

            return redirect()->route('two-factor.login');
        }

        $guard->clear($request, $email);
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $sessions->rememberLogin($request);
        $user->markSeen();
        $audit->record('auth.login.succeeded', $user, [], $request);

        return $this->afterLogin($user);
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $viewing = $request->session()->get('view_as_user_id');

            if ($viewing) {
                $audit->record('auth.view_as.stopped', $user, ['user_id' => $viewing, 'reason' => 'logout'], $request);
            }

            $audit->record('auth.logout', $user, [], $request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function dummyHash(): string
    {
        $driver = (string) config('hashing.driver', 'bcrypt');
        $cost = (string) json_encode(config('hashing.'.$driver));

        // Same algorithm and cost as real passwords; made once and reused.
        return Cache::rememberForever('auth:dummy-hash:'.sha1($driver.$cost), fn () => Hash::make(Str::random(40)));
    }

    private function afterLogin(User $user): RedirectResponse
    {
        if ($user->must_change_password) {
            return redirect()->route('password.required');
        }

        return redirect()->intended(route('dashboard'));
    }
}
