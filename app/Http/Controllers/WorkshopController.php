<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Project;
use App\Services\Codex\AgentPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkshopController extends Controller
{
    public function __invoke(Request $request, Project $project, AgentPresenter $presenter): Response
    {
        abort_unless($project->user_id === $request->user()->id, 404);
        $agents = $project->agents()->where('user_id', $request->user()->id)->orderBy('id')->get();
        $request->validate(['agent' => ['sometimes', 'required', 'integer']]);
        $agent = $request->has('agent') ? $agents->firstWhere('id', $request->integer('agent')) : null;
        abort_if($request->has('agent') && $agent === null, 404);

        return Inertia::render('Workshop', [
            'project' => $project->summary(),
            'agents' => $agents->map(fn (Agent $agent): array => $presenter->status($agent)),
            'workshop' => fn (): ?array => $agent === null ? null : $presenter->snapshot($agent),
            'createAgentUrl' => route('agents.store', $project, absolute: false),
        ]);
    }
}
