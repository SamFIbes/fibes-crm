<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ModuleController extends Controller
{
    public function leads(): Response
    {
        return $this->page('leads');
    }

    public function opportunities(): Response
    {
        return $this->page('opportunities');
    }

    public function surveys(): Response
    {
        return $this->page('surveys');
    }

    public function quotes(): Response
    {
        return $this->page('quotes');
    }

    public function settings(): Response
    {
        return $this->page('settings');
    }

    public function customers(): Response
    {
        return $this->page('customers');
    }

    public function tickets(): Response
    {
        return $this->page('tickets');
    }

    public function tasks(): Response
    {
        return $this->page('tasks');
    }

    public function billing(): Response
    {
        return $this->page('billing');
    }

    public function tech(): Response
    {
        return $this->page('tech');
    }

    public function network(): Response
    {
        return $this->page('network');
    }

    public function audit(): Response
    {
        return $this->page('audit');
    }

    private function page(string $module): Response
    {
        return Inertia::render('Modules/Placeholder', [
            'module' => $module,
        ]);
    }
}
