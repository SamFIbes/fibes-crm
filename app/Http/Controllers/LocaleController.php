<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $locale = $request->validate([
            'locale' => ['required', 'in:'.implode(',', config('fibes.locales', ['en', 'ar']))],
        ])['locale'];

        $request->session()->put('locale', $locale);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        app()->setLocale($locale);

        return back();
    }
}
