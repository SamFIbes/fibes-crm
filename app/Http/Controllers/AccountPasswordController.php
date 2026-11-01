<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountPasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Account/PasswordRequired');
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user = $request->user();
        $user->replacePassword($validated['password']);
        $audit->record('auth.password.changed', $user, ['reason' => 'required'], $request);

        return redirect()->route('dashboard')->with('status', __('ui.security.password_updated'));
    }
}
