<?php

namespace App\Services\Codex;

use App\Models\Agent;
use App\Models\AgentApproval;
use App\Models\AgentEvent;
use App\Models\AgentMessage;
use App\Models\AgentTurn;
use Illuminate\Support\Facades\DB;

class AgentPresenter
{
    public function online(Agent $agent): bool
    {
        return $agent->heartbeat_at !== null && $agent->heartbeat_at->greaterThan(now()->subSeconds(config('agents.heartbeat_seconds')));
    }

    /** @return array<string, mixed> */
    public function status(Agent $agent): array
    {
        $online = $this->online($agent);
        $status = $online ? $agent->status : ($agent->active_turn_id === null ? 'offline' : 'needs_recovery');

        return [
            'id' => $agent->id, 'name' => $agent->name, 'workspace' => $agent->workspace_path,
            'project_id' => $agent->project_id, 'role' => $agent->role, 'instructions' => $agent->instructions,
            'worker_command' => 'php artisan agents:work '.$agent->id.' --user='.$agent->user_id,
            'workshop_url' => route('projects.workshop', ['project' => $agent->project_id, 'agent' => $agent->id], absolute: false),
            'status' => $status, 'online' => $online, 'active_turn_id' => $agent->active_turn_id,
            'notice' => $online ? $agent->notice : 'This agent’s worker is offline. Start its worker to reconnect and check the saved conversation. Only one worker can run at a time.',
            'can_recover' => $online && $agent->status === 'needs_recovery' && $agent->reconciled_at !== null,
            'urls' => collect(['state', 'events', 'tasks', 'steer', 'stop', 'decisions', 'recover'])->mapWithKeys(fn (string $action): array => [$action => route('agents.'.$action, $agent, absolute: false)])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(Agent $agent): array
    {
        return DB::transaction(function () use ($agent): array {
            $agent = Agent::query()->lockForUpdate()->findOrFail($agent->id);

            return [
                'agent' => $this->status($agent),
                'messages' => AgentMessage::query()->where('agent_id', $agent->id)->orderBy('id')->get()->map(fn (AgentMessage $message): array => $this->message($message))->all(),
                'turns' => AgentTurn::query()->where('agent_id', $agent->id)->orderBy('id')->get(['id', 'status', 'created_at'])->toArray(),
                'approvals' => AgentApproval::query()->where('agent_id', $agent->id)->whereIn('status', ['pending', 'deciding'])->orderBy('id')->get()->map(fn (AgentApproval $approval): array => $this->approval($approval))->all(),
                'activity' => AgentEvent::query()->where('agent_id', $agent->id)->where('type', 'activity')->orderByDesc('id')->limit(100)->get()->reverse()->values()->map(fn (AgentEvent $event): array => ['id' => $event->id, ...$event->data])->all(),
                'cursor' => AgentEvent::query()->where('agent_id', $agent->id)->max('id') ?? 0,
            ];
        });
    }

    /** @return array{id: int, turn_id: ?int, role: string, kind: string, body: string} */
    public function message(AgentMessage $message): array
    {
        return ['id' => $message->id, 'turn_id' => $message->agent_turn_id, 'role' => $message->role, 'kind' => $message->kind, 'body' => $message->body];
    }

    /** @return array<string, mixed> */
    public function approval(AgentApproval $approval): array
    {
        return ['id' => $approval->id, 'turn_id' => $approval->agent_turn_id, 'status' => $approval->status, ...$approval->context];
    }
}
