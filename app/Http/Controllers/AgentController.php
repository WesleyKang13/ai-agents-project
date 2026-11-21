<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\Codex\AgentPresenter;
use App\Services\Codex\AgentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function store(Request $request, AgentStore $store, AgentPresenter $presenter): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'workspace' => ['required', 'string', 'max:80']]);
        $agent = $store->create($request->user(), trim($data['name']), $data['workspace']);

        return response()->json($presenter->snapshot($agent), 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Agent $agent, AgentPresenter $presenter): JsonResponse
    {
        abort_unless($agent->user_id === $request->user()->id, 404);

        return response()->json($presenter->snapshot($agent))->header('Cache-Control', 'no-store');
    }
}
