<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class WorkshopController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Workshop', [
            'agents' => [],
            'milestone' => 'Application foundation',
        ]);
    }
}
