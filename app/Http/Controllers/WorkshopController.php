<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\Codex\AgentPresenter;
use App\Services\Codex\AgentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkshopController extends Controller
{
    public function __invoke(Request $request, AgentPresenter $presenter, AgentWorkspace $workspaces): Response
    {
        $agent = Agent::query()->where('user_id', $request->user()->id)->first();

        return Inertia::render('Workshop', [
            'workshop' => $agent === null ? null : $presenter->snapshot($agent),
            'occupied' => $agent === null && Agent::query()->exists(),
            'workspaces' => $workspaces->choices(),
            'createAgentUrl' => route('agents.store', absolute: false),
            'milestone' => 'One agent controlled from the browser',
        ]);
    }
}
