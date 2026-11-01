<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HomeController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user() ? 'dashboard' : 'login');
    }

    public function missing(): never
    {
        throw new NotFoundHttpException;
    }
}
