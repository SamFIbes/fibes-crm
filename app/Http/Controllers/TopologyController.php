<?php

namespace App\Http\Controllers;

use App\Services\Integrations\ProviderTopology;
use Inertia\Inertia;
use Inertia\Response;

class TopologyController extends Controller
{
    public function index(ProviderTopology $topology): Response
    {
        return Inertia::render('Topology/Index', [
            'providers' => $topology->providers(),
        ]);
    }
}
