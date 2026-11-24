<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Project;
use App\Services\Codex\AgentPresenter;
use App\Services\Codex\AgentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function store(Request $request, Project $project, AgentStore $store, AgentPresenter $presenter): JsonResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'role' => ['nullable', 'string', 'max:120'], 'instructions' => ['nullable', 'string', 'max:16000']]);
        $agent = $store->create($request->user(), $project, $data['name'], $data['role'] ?? null, $data['instructions'] ?? null);

        return response()->json($presenter->snapshot($agent), 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Agent $agent, AgentPresenter $presenter): JsonResponse
    {
        abort_unless($agent->ownedBy($request->user()), 404);

        return response()->json($presenter->snapshot($agent))->header('Cache-Control', 'no-store');
    }
}
