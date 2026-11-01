<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafeEmail;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public function requestForm(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255', new SafeEmail],
        ]);

        $key = $this->throttleKey($request);

        $limit = (int) config('fibes.rate_limits.password_reset_per_minute', 5);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw ValidationException::withMessages([
                'email' => __('ui.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 60);

        $email = Str::lower($request->string('email')->toString());
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user && $user->is_active) {
            Password::sendResetLink(['email' => $user->email]);
            app(AuditLogger::class)->record('auth.password.reset_requested', $user, [], $request);
        }

        return back()->with('status', __('ui.auth.reset_sent'));
    }

    public function resetForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'max:255', new SafeEmail],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $email = Str::lower($request->string('email')->toString());
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        $status = Password::reset(
            [
                'email' => $user?->email ?? $email,
                'password' => $request->string('password')->toString(),
                'password_confirmation' => $request->string('password_confirmation')->toString(),
                'token' => $request->string('token')->toString(),
            ],
            function (User $account, string $password) use ($request) {
                $account->replacePassword($password);
                app(AuditLogger::class)->record('auth.password.reset', $account, [], $request);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __('ui.auth.reset_invalid'),
            ]);
        }

        return redirect()->route('login')->with('status', __('ui.auth.reset_done'));
    }

    private function throttleKey(Request $request): string
    {
        $email = $request->input('email');
        $email = is_string($email) ? Str::lower($email) : '';

        return 'password-reset:'.sha1($email.'|'.$request->ip());
    }
}
