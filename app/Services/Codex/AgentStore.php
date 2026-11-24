<?php

namespace App\Services\Codex;

use App\Models\Agent;
use App\Models\AgentApproval;
use App\Models\AgentControl;
use App\Models\AgentEvent;
use App\Models\AgentMessage;
use App\Models\AgentTurn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentStore
{
    public function __construct(private AgentWorkspace $workspaces, private AgentPresenter $presenter, private AgentOutput $output) {}

    public function create(User $user, Project $project, string $name, ?string $role = null, ?string $instructions = null): Agent
    {
        abort_unless($project->user_id === $user->id, 404);
        $path = $this->workspaces->project($project);

        return Agent::query()->create(['project_id' => $project->id, 'user_id' => $user->id, 'name' => $name, 'role' => $role, 'instructions' => $instructions, 'workspace_key' => 'project', 'workspace_path' => $path, 'status' => 'offline']);
    }

    /** @param array<string, mixed> $payload */
    public function submit(Agent $agent, string $kind, string $key, array $payload): AgentControl
    {
        return DB::transaction(function () use ($agent, $kind, $key, $payload): AgentControl {
            $agent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            $fingerprint = hash('sha256', json_encode([$kind, $payload], JSON_THROW_ON_ERROR));
            $existing = AgentControl::query()->where('agent_id', $agent->id)->where('request_key', $key)->first();

            if ($existing !== null) {
                abort_unless($existing->fingerprint === $fingerprint, 409, 'This request key has already been used for a different action.');
                abort_if($kind === 'decision', 409, 'This permission request already has a decision.');

                return $existing;
            }

            abort_unless($this->presenter->online($agent), 409, 'The agent worker is offline. Reconnect it before sending controls.');
            $turn = null;

            if ($kind === 'task') {
                $this->workspaces->agent($agent);
                abort_unless($agent->status === 'idle' && $agent->active_turn_id === null, 409, 'The agent is busy. Send a steering message or stop the current task.');
                $turn = AgentTurn::query()->create(['agent_id' => $agent->id, 'status' => 'queued']);
                $agent->active_turn_id = $turn->id;
                $agent->status = 'queued';
                $agent->notice = null;
                $this->message($agent, $turn->id, 'user', $payload['text'], 'task');
            } elseif ($kind === 'recover') {
                abort_unless($agent->status === 'needs_recovery' && $agent->reconciled_at !== null, 409, 'The worker must check the saved conversation before recovery.');
                $agent->status = 'recovering';
            } else {
                abort_unless($agent->active_turn_id !== null && $agent->active_turn_id === ($payload['turn_id'] ?? null), 409, 'This task has ended or changed. Refresh the conversation.');
                abort_unless(in_array($agent->status, ['queued', 'running', 'waiting_permission'], true), 409, 'This action is no longer available for the current task.');
                $turn = AgentTurn::query()->where('agent_id', $agent->id)->findOrFail($agent->active_turn_id);

                if ($kind === 'steer') {
                    abort_unless($turn->codex_id !== null && $agent->status === 'running', 409, 'Wait until the task is running before sending a steering message.');
                } elseif ($kind === 'stop') {
                    $agent->status = 'stopping';
                } elseif ($kind === 'decision') {
                    $approval = AgentApproval::query()->where('agent_id', $agent->id)->lockForUpdate()->find($payload['approval_id']);
                    abort_unless($approval !== null && $approval->status === 'pending' && $approval->agent_turn_id === $turn->id && $approval->connection_id === $agent->connection_id, 409, 'This permission request has expired or already has a decision.');
                    $approval->update(['status' => 'deciding', 'decision' => $payload['decision']]);
                    $this->event($agent, 'approval', $this->presenter->approval($approval));
                }
            }

            $control = AgentControl::query()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn?->id, 'request_key' => $key, 'kind' => $kind, 'fingerprint' => $fingerprint, 'payload' => $payload, 'status' => 'pending']);
            $agent->save();
            $this->event($agent, 'status', $this->presenter->status($agent));

            return $control;
        });
    }

    /** Caller holds the agent row lock so event IDs follow commit order. @param array<string, mixed> $data */
    public function event(Agent $agent, string $type, array $data): AgentEvent
    {
        return AgentEvent::query()->create(['agent_id' => $agent->id, 'type' => $type, 'data' => $data]);
    }

    public function message(Agent $agent, ?int $turnId, string $role, string $body, string $kind = 'task', ?string $itemId = null): AgentMessage
    {
        $message = AgentMessage::query()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turnId, 'item_id' => $itemId, 'role' => $role, 'kind' => $kind, 'body' => $this->output->text($body)]);
        $this->event($agent, 'message', $this->presenter->message($message));

        return $message;
    }
}
