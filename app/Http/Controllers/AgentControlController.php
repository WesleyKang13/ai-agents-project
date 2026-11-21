<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\Codex\AgentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentControlController extends Controller
{
    public function task(Request $request, Agent $agent, AgentStore $store): JsonResponse
    {
        return $this->submit($request, $agent, $store, 'task', ['text' => ['required', 'string', 'max:16000']]);
    }

    public function steer(Request $request, Agent $agent, AgentStore $store): JsonResponse
    {
        return $this->submit($request, $agent, $store, 'steer', ['text' => ['required', 'string', 'max:16000'], 'turn_id' => ['required', 'integer']]);
    }

    public function stop(Request $request, Agent $agent, AgentStore $store): JsonResponse
    {
        return $this->submit($request, $agent, $store, 'stop', ['turn_id' => ['required', 'integer']]);
    }

    public function decision(Request $request, Agent $agent, AgentStore $store): JsonResponse
    {
        return $this->submit($request, $agent, $store, 'decision', ['turn_id' => ['required', 'integer'], 'approval_id' => ['required', 'integer'], 'decision' => ['required', Rule::in(['allow', 'deny'])]]);
    }

    public function recover(Request $request, Agent $agent, AgentStore $store): JsonResponse
    {
        return $this->submit($request, $agent, $store, 'recover', []);
    }

    /** @param array<string, array<int, mixed>> $rules */
    private function submit(Request $request, Agent $agent, AgentStore $store, string $kind, array $rules): JsonResponse
    {
        abort_unless($agent->user_id === $request->user()->id, 404);
        $data = $request->validate(['request_key' => ['required', 'uuid'], ...$rules]);
        $key = $data['request_key'];
        unset($data['request_key']);
        $control = $store->submit($agent, $kind, $key, $data);

        return response()->json(['control_id' => $control->id, 'status' => $control->status], 202)->header('Cache-Control', 'no-store');
    }
}
