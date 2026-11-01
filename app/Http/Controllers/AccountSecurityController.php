<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountSecurityController extends Controller
{
    public function edit(Request $request, TwoFactorAuthentication $twoFactor): Response
    {
        $user = $request->user();
        $pending = filled($user->two_factor_secret) && $user->two_factor_confirmed_at === null;
        $secret = $pending ? $user->two_factor_secret : null;

        return Inertia::render('Account/Security', [
            'pending' => $pending,
            'qr' => is_string($secret) ? $twoFactor->qrSvg($user, $secret) : null,
            'secret' => $secret,
        ]);
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user = $request->user();
        $user->replacePassword($validated['password']);
        $audit->record('auth.password.changed', $user, ['reason' => 'account'], $request);

        return back()->with('status', __('ui.security.password_updated'));
    }

    public function enable(Request $request, TwoFactorAuthentication $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => $twoFactor->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $audit->record('auth.two_factor.enabled', $user, [], $request);

        return back();
    }

    public function confirm(Request $request, TwoFactorAuthentication $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $user = $request->user();

        if (! $twoFactor->verifyCode($user, $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => __('ui.security.confirm_invalid'),
            ]);
        }

        $plain = $twoFactor->freshRecoveryCodes();
        $twoFactor->storeRecoveryCodes($user, $plain);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $audit->record('auth.two_factor.confirmed', $user, [], $request);

        return back()
            ->with('status', __('ui.security.enabled'))
            ->with('recovery_codes', $plain);
    }

    public function disable(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if ($user->requiresTwoFactor()) {
            throw ValidationException::withMessages([
                'current_password' => __('ui.security.disable_blocked'),
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $audit->record('auth.two_factor.disabled', $user, [], $request);

        return back()->with('status', __('ui.security.disabled'));
    }
}
