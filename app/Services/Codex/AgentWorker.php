<?php

namespace App\Services\Codex;

use App\Models\Agent;
use App\Models\AgentApproval;
use App\Models\AgentControl;
use App\Models\AgentMessage;
use App\Models\AgentTurn;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AgentWorker
{
    private Agent $agent;

    private bool $reconciling = true;

    private float $lastHeartbeat = 0;

    /** @var array<int, array{control: int, kind: string, turn: ?int, deadline: float}> */
    private array $requests = [];

    /** @var array<string, string> */
    private array $text = [];

    private ?float $stopDeadline = null;

    private bool $rotate = false;

    public function __construct(private AppServerClient $client, private AgentStore $store, private AgentPresenter $presenter, private AgentWorkspace $workspaces, private AgentOutput $output) {}

    public function run(Agent $agent, Closure $stopping): bool
    {
        do {
            $this->rotate = false;
            $this->reconciling = true;
            $this->requests = $this->text = [];
            $this->stopDeadline = null;
            $this->lastHeartbeat = 0;
            $success = $this->connection($agent, $stopping);

            if (! $success || ! $this->rotate || $stopping()) {
                return $success;
            }

            $this->client->renew();
            $agent->refresh();
        } while (true);
    }

    private function connection(Agent $agent, Closure $stopping): bool
    {
        $this->agent = $agent;
        $notice = in_array($agent->notice, ['Task interrupted.', 'Task completed.', 'The task failed. You can send a new task.'], true) ? $agent->notice : null;
        $failed = false;

        try {
            if ($agent->server_pid !== null && $agent->server_start !== null) {
                OwnedProcess::cleanRecorded((int) $agent->server_pid, $agent->server_start);
            }

            $workspace = $this->workspaces->resolve($agent->workspace_key, $agent->workspace_path);
            $uncertain = $agent->active_turn_id !== null || AgentControl::query()->where('agent_id', $agent->id)->whereIn('status', ['pending', 'processing', 'uncertain'])->exists();
            $this->change(function (Agent $agent): void {
                $agent->fill(['status' => 'recovering', 'connection_id' => (string) Str::uuid(), 'heartbeat_at' => now(), 'reconciled_at' => null, 'notice' => 'Connecting and checking the saved conversation.']);
                AgentApproval::query()->where('agent_id', $agent->id)->whereIn('status', ['pending', 'deciding'])->update(['status' => 'expired']);
                AgentControl::query()->where('agent_id', $agent->id)->whereIn('status', ['pending', 'processing'])->update(['status' => 'uncertain']);
            });
            $this->client->start([(string) config('services.codex.binary'), 'app-server', '--listen', 'stdio://'], $workspace, fn (array $message) => $this->observe($message));
            $ownership = $this->client->ownership();
            $this->change(function (Agent $agent) use ($ownership): void {
                $agent->fill(['server_pid' => $ownership['pid'] ?? null, 'server_start' => $ownership['start'] ?? null]);
            }, false);
            $this->rpc('initialize', ['clientInfo' => ['name' => 'ai_agents_workshop', 'title' => 'AI Agents Workshop', 'version' => '0.3.0']]);
            $this->client->notify('initialized');
            $account = $this->rpc('account/read', ['refreshToken' => false]);

            if (($account['account']['type'] ?? null) !== 'chatgpt') {
                throw new RuntimeException('Existing ChatGPT CLI authentication is required.');
            }

            $params = ['cwd' => $workspace, 'sandbox' => 'workspace-write', 'approvalPolicy' => 'untrusted', 'approvalsReviewer' => 'user', 'developerInstructions' => 'Work in the selected workspace. Never access or disclose credentials, personal files or unrelated repositories. Do not spawn agents. Ask the user before actions requiring approval. If denied, stop that action without trying alternatives.'];
            $resuming = $this->agent->thread_id !== null;

            if ($resuming) {
                $params['threadId'] = $this->agent->thread_id;
                $params['excludeTurns'] = true;
            }

            $thread = $this->rpc($resuming ? 'thread/resume' : 'thread/start', $params);
            $this->change(function (Agent $agent) use ($thread): void {
                $agent->thread_id = $thread['thread']['id'];
            }, false);

            if ($resuming) {
                $this->reconcile();
            }

            $this->reconciling = false;
            $this->change(function (Agent $agent) use ($uncertain, $notice): void {
                $agent->fill(['status' => $uncertain ? 'needs_recovery' : 'idle', 'reconciled_at' => now(), 'active_turn_id' => null, 'notice' => $uncertain ? 'The previous connection ended. Saved history has been checked. Review the conversation, then resume when you are ready. No task or permission will be replayed.' : $notice]);
                AgentTurn::query()->where('agent_id', $agent->id)->whereIn('status', ['queued', 'starting', 'running'])->update(['status' => 'uncertain']);
            });

            while (! $stopping()) {
                $this->heartbeat();
                $this->client->poll();
                $this->receiveResponses();

                if ($this->rotate) {
                    break;
                }

                $this->control();
                $this->guardTimeouts();
                usleep(20000);
            }

            if ($this->agent->active_turn_id !== null) {
                $turn = AgentTurn::query()->find($this->agent->active_turn_id);

                if ($turn?->codex_id !== null) {
                    $this->rpc('turn/interrupt', ['threadId' => $this->agent->thread_id, 'turnId' => $turn->codex_id]);
                    $deadline = microtime(true) + 3;

                    while ($this->agent->active_turn_id !== null && microtime(true) < $deadline) {
                        $this->client->poll();
                        usleep(20000);
                    }
                }
            }
        } catch (Throwable) {
            $failed = true;
        } finally {
            $this->client->close();
            $rotating = $this->rotate && ! $failed && ! $stopping();
            $this->change(function (Agent $agent) use ($failed, $rotating): void {
                $agent->fill(['server_pid' => null, 'server_start' => null, 'reconciled_at' => null]);

                if (! $rotating) {
                    $agent->fill(['heartbeat_at' => null, 'status' => $failed || $agent->active_turn_id !== null ? 'needs_recovery' : 'offline', 'notice' => 'The agent worker stopped. Restart it to check the saved conversation before continuing.']);
                }
                AgentApproval::query()->where('agent_id', $agent->id)->whereIn('status', ['pending', 'deciding'])->update(['status' => 'expired']);
                AgentControl::query()->where('agent_id', $agent->id)->where('status', 'processing')->update(['status' => 'uncertain']);
            });
        }

        return ! $failed;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function rpc(string $method, array $params): array
    {
        return $this->client->request($method, $params, (float) config('agents.request_timeout'), fn () => $this->heartbeat());
    }

    private function heartbeat(): void
    {
        if (microtime(true) - $this->lastHeartbeat >= 1) {
            Agent::query()->whereKey($this->agent->id)->update(['heartbeat_at' => now()]);
            $this->lastHeartbeat = microtime(true);
        }
    }

    private function change(Closure $change, bool $event = true): void
    {
        DB::transaction(function () use ($change, $event): void {
            $this->agent = Agent::query()->lockForUpdate()->findOrFail($this->agent->id);
            $change($this->agent);
            $this->agent->save();

            if ($event) {
                $this->store->event($this->agent, 'status', $this->presenter->status($this->agent));
            }
        });
    }

    private function control(): void
    {
        $control = DB::transaction(function (): ?AgentControl {
            $agent = Agent::query()->lockForUpdate()->findOrFail($this->agent->id);
            $control = AgentControl::query()->where('agent_id', $agent->id)->where('status', 'pending')->orderBy('id')->first();

            if ($control === null) {
                return null;
            }

            if ($control->kind === 'stop' && AgentTurn::query()->find($control->agent_turn_id)?->codex_id === null && $this->requests !== []) {
                return null;
            }

            $control->update(['status' => 'processing']);

            return $control;
        });

        if ($control === null) {
            return;
        }

        $this->agent->refresh();
        $payload = $control->payload;
        $turn = $control->agent_turn_id === null ? null : AgentTurn::query()->find($control->agent_turn_id);

        if ($control->kind === 'recover') {
            $this->reconcile();
            $this->change(function (Agent $agent) use ($control): void {
                $agent->fill(['status' => 'idle', 'active_turn_id' => null, 'notice' => null, 'reconciled_at' => now()]);
                $control->update(['status' => 'completed']);
                AgentControl::query()->where('agent_id', $agent->id)->where('status', 'uncertain')->update(['status' => 'acknowledged']);
            });

            return;
        }

        if ($turn === null || $this->agent->active_turn_id !== $turn->id) {
            $control->update(['status' => 'rejected']);

            return;
        }

        if ($control->kind === 'decision') {
            $this->decide($control, $turn);

            return;
        }

        if ($control->kind === 'task' && $this->agent->status === 'stopping') {
            $this->finish($turn, 'interrupted');
            $control->update(['status' => 'completed']);

            return;
        }

        if ($control->kind !== 'task' && $turn->codex_id === null) {
            $control->update(['status' => 'rejected']);

            return;
        }

        $params = ['threadId' => $this->agent->thread_id];
        $method = match ($control->kind) {
            'task' => 'turn/start', 'steer' => 'turn/steer', 'stop' => 'turn/interrupt'
        };

        if ($control->kind !== 'stop') {
            $params['input'] = [['type' => 'text', 'text' => $payload['text']]];
            $params['clientUserMessageId'] = $control->request_key;
        }

        if ($control->kind === 'steer') {
            $params['expectedTurnId'] = $turn->codex_id;
        } elseif ($control->kind === 'stop') {
            $params['turnId'] = $turn->codex_id;
            $this->stopDeadline = microtime(true) + 10;
        } else {
            $turn->update(['status' => 'starting']);
        }

        $id = $this->client->beginRequest($method, $params);
        $this->requests[$id] = ['control' => $control->id, 'kind' => $control->kind, 'turn' => $turn->id, 'deadline' => microtime(true) + config('agents.request_timeout')];
    }

    private function receiveResponses(): void
    {
        foreach ($this->requests as $id => $pending) {
            $response = $this->client->takeResponse($id);

            if ($response === null) {
                continue;
            }

            unset($this->requests[$id]);
            $control = AgentControl::query()->findOrFail($pending['control']);
            $turn = AgentTurn::query()->findOrFail($pending['turn']);

            if (isset($response['error'])) {
                $control->update(['status' => 'rejected']);

                if ($pending['kind'] === 'task') {
                    $this->finish($turn, 'failed');
                } else {
                    $this->change(function (Agent $agent) use ($pending): void {
                        $this->store->event($agent, 'activity', ['label' => $pending['kind'] === 'steer' ? 'Steering was not delivered. The task may have ended.' : 'The stop request was not accepted. Reconnect the worker if the task does not finish.', 'detail' => '', 'phase' => 'failed']);
                    }, false);
                }

                continue;
            }

            $this->change(function (Agent $agent) use ($control, $turn, $pending, $response): void {
                $control->update(['status' => 'completed']);

                if ($pending['kind'] === 'task') {
                    $turn->codex_id = $response['result']['turn']['id'];

                    if (in_array($turn->status, ['queued', 'starting'], true)) {
                        $turn->status = 'running';
                    }

                    $turn->save();

                    if ($agent->active_turn_id === $turn->id && in_array($agent->status, ['queued', 'running'], true)) {
                        $agent->status = 'running';
                    }
                } elseif ($pending['kind'] === 'steer') {
                    $this->store->message($agent, $turn->id, 'user', $control->payload['text'], 'steer');
                }
            });
        }
    }

    private function decide(AgentControl $control, AgentTurn $turn): void
    {
        $approval = AgentApproval::query()->find($control->payload['approval_id']);
        $native = $approval?->request_payload;
        $pending = collect($this->client->pendingRequests())->contains(fn (array $request): bool => $request['id'] === ($native['id'] ?? null));

        if ($approval === null || $approval->status !== 'deciding' || $approval->connection_id !== $this->agent->connection_id || $approval->agent_turn_id !== $turn->id || ! $pending) {
            $control->update(['status' => 'rejected']);
            $approval?->update(['status' => 'expired']);

            return;
        }

        $allow = $control->payload['decision'] === 'allow';

        if ($native['method'] === 'item/permissions/requestApproval') {
            $result = ['permissions' => $allow ? (object) $native['params']['permissions'] : (object) [], 'scope' => 'turn'];
        } else {
            $decision = $allow ? 'accept' : 'decline';

            if (isset($native['params']['availableDecisions']) && ! in_array($decision, $native['params']['availableDecisions'], true)) {
                $decision = 'cancel';
            }

            $result = ['decision' => $decision];
        }

        $this->client->respond($native['id'], $result);
        $this->change(function (Agent $agent) use ($approval, $control, $allow): void {
            $approval->update(['status' => $allow ? 'accepted' : 'denied']);
            $control->update(['status' => 'completed']);
            $this->store->event($agent, 'approval', $this->presenter->approval($approval));

            if ($agent->status === 'waiting_permission') {
                $agent->status = 'running';
            }
        });
    }

    /** @param array<string, mixed> $message */
    private function observe(array $message): void
    {
        if ($this->reconciling) {
            if (isset($message['id'])) {
                $this->client->reject($message['id']);
            }

            return;
        }

        $method = $message['method'];
        $params = $message['params'] ?? [];

        if (($params['threadId'] ?? null) !== $this->agent->thread_id) {
            if (isset($message['id'])) {
                $this->client->reject($message['id']);
            }

            return;
        }

        if (isset($message['id'])) {
            $this->approval($message);

            return;
        }

        $turn = $this->agent->active_turn_id === null ? null : AgentTurn::query()->find($this->agent->active_turn_id);

        if ($method === 'serverRequest/resolved') {
            $this->change(function (Agent $agent) use ($params): void {
                $approval = AgentApproval::query()->where('agent_id', $agent->id)->where('connection_id', $agent->connection_id)->where('request_key', json_encode($params['requestId'], JSON_THROW_ON_ERROR))->whereIn('status', ['pending', 'deciding'])->first();

                if ($approval !== null) {
                    $approval->update(['status' => 'expired']);
                    $this->store->event($agent, 'approval', $this->presenter->approval($approval));
                }
            }, false);
        } elseif ($method === 'turn/started' && $turn !== null) {
            $this->change(function (Agent $agent) use ($turn, $params): void {
                $turn->update(['codex_id' => $params['turn']['id'], 'status' => 'running']);
                $agent->status = $agent->status === 'stopping' ? 'stopping' : 'running';
            });
        } elseif ($method === 'turn/completed' && $turn !== null && ($turn->codex_id === null || $turn->codex_id === $params['turn']['id'])) {
            $turn->codex_id = $params['turn']['id'];
            $this->finish($turn, $params['turn']['status']);
        } elseif ($method === 'item/agentMessage/delta' && $turn !== null) {
            $id = $params['itemId'];
            $this->text[$id] = mb_substr(($this->text[$id] ?? '').$params['delta'], 0, 131072);
            $this->assistant($turn, $id, $this->text[$id]);
        } elseif ($method === 'item/completed' && ($params['item']['type'] ?? '') === 'agentMessage' && $turn !== null) {
            $this->assistant($turn, $params['item']['id'], $params['item']['text']);
            unset($this->text[$params['item']['id']]);
        } elseif (in_array($method, ['item/started', 'item/completed'], true)) {
            $item = $params['item'];

            if (in_array($item['type'], ['userMessage', 'agentMessage', 'reasoning'], true)) {
                return;
            }

            $this->change(function (Agent $agent) use ($item, $method): void {
                $label = match ($item['type']) {
                    'commandExecution' => 'Running a command', 'fileChange' => 'Changing files', default => 'Using a tool'
                };
                $this->store->event($agent, 'activity', ['label' => $label, 'detail' => $this->output->text($item['command'] ?? '', 8000), 'phase' => $method === 'item/started' ? 'running' : ($item['status'] ?? 'completed')]);
            }, false);
        } elseif ($method === 'item/commandExecution/outputDelta') {
            $this->change(function (Agent $agent) use ($params): void {
                $this->store->event($agent, 'activity', ['label' => 'Command output', 'detail' => $this->output->text($params['delta'] ?? '', 8000), 'phase' => 'output']);
            }, false);
        }
    }

    /** @param array<string, mixed> $request */
    private function approval(array $request): void
    {
        if (! in_array($request['method'], ['item/commandExecution/requestApproval', 'item/fileChange/requestApproval', 'item/permissions/requestApproval'], true)) {
            $this->client->reject($request['id']);

            return;
        }

        $params = $request['params'];
        $turn = AgentTurn::query()->find($this->agent->active_turn_id);

        if ($turn === null || ($turn->codex_id !== null && $turn->codex_id !== $params['turnId'])) {
            $this->client->reject($request['id']);

            return;
        }

        $this->change(function (Agent $agent) use ($request, $params, $turn): void {
            $turn->update(['codex_id' => $params['turnId']]);
            $context = ['action' => match ($request['method']) {
                'item/commandExecution/requestApproval' => 'Run command', 'item/fileChange/requestApproval' => 'Change files', default => 'Grant requested access for this turn'
            }, 'command' => $this->output->text($params['command'] ?? '', 12000), 'cwd' => $this->output->text($params['cwd'] ?? $agent->workspace_path, 2000), 'reason' => $this->output->text($params['reason'] ?? '', 4000), 'permissions' => $this->output->text(isset($params['permissions']) ? json_encode($params['permissions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : '', 8000)];
            $approval = AgentApproval::query()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'connection_id' => $agent->connection_id, 'request_key' => json_encode($request['id'], JSON_THROW_ON_ERROR), 'request_payload' => $request, 'context' => $context, 'status' => 'pending']);
            $agent->status = $agent->status === 'stopping' ? 'stopping' : 'waiting_permission';
            $this->store->event($agent, 'approval', $this->presenter->approval($approval));
        });
    }

    private function assistant(AgentTurn $turn, string $itemId, string $body): void
    {
        $this->change(function (Agent $agent) use ($turn, $itemId, $body): void {
            $message = AgentMessage::query()->firstOrNew(['agent_id' => $agent->id, 'item_id' => $itemId]);
            $message->fill(['agent_turn_id' => $turn->id, 'role' => 'assistant', 'kind' => 'task', 'body' => $this->output->text($body)])->save();
            $this->store->event($agent, 'message', $this->presenter->message($message));
        }, false);
    }

    private function finish(AgentTurn $turn, string $status): void
    {
        $this->stopDeadline = null;
        $this->rotate = true;
        $this->change(function (Agent $agent) use ($turn, $status): void {
            $turn->status = in_array($status, ['completed', 'interrupted', 'failed'], true) ? $status : 'failed';
            $turn->save();
            AgentApproval::query()->where('agent_turn_id', $turn->id)->whereIn('status', ['pending', 'deciding'])->update(['status' => 'expired']);
            AgentControl::query()->where('agent_turn_id', $turn->id)->whereIn('kind', ['task', 'stop'])->whereIn('status', ['pending', 'processing'])->update(['status' => 'completed']);
            AgentControl::query()->where('agent_turn_id', $turn->id)->whereIn('status', ['pending', 'processing'])->update(['status' => 'rejected']);
            $agent->fill(['active_turn_id' => null, 'status' => $this->rotate ? 'recovering' : 'idle', 'notice' => match ($turn->status) {
                'completed' => 'Task completed.', 'interrupted' => 'Task interrupted.', default => 'The task failed. You can send a new task.'
            }]);
            $this->store->event($agent, 'turn', ['id' => $turn->id, 'status' => $turn->status]);
        });
    }

    private function guardTimeouts(): void
    {
        foreach ($this->requests as $pending) {
            if (microtime(true) > $pending['deadline']) {
                throw new RuntimeException('A control response timed out.');
            }
        }

        if ($this->stopDeadline !== null && microtime(true) > $this->stopDeadline) {
            throw new RuntimeException('The interrupted turn did not finish.');
        }

        if ($this->agent->active_turn_id !== null) {
            $turn = AgentTurn::query()->find($this->agent->active_turn_id);

            if ($turn !== null && $turn->created_at->lessThan(now()->subSeconds(config('agents.turn_timeout')))) {
                throw new RuntimeException('The task exceeded the worker time limit.');
            }
        }
    }

    private function reconcile(): void
    {
        $cursor = null;

        do {
            $page = $this->rpc('thread/turns/list', ['threadId' => $this->agent->thread_id, 'limit' => 100, 'itemsView' => 'full', 'sortDirection' => 'asc', 'cursor' => $cursor]);

            foreach ($page['data'] as $saved) {
                $this->change(function (Agent $agent) use ($saved): void {
                    $turn = AgentTurn::query()->where('agent_id', $agent->id)->where('codex_id', $saved['id'])->first();

                    if ($turn === null) {
                        $origin = collect($saved['items'] ?? [])->first(fn (array $item): bool => $item['type'] === 'userMessage' && is_string($item['clientId'] ?? null));
                        $control = $origin === null ? null : AgentControl::query()->where('agent_id', $agent->id)->where('kind', 'task')->where('request_key', $origin['clientId'])->first();
                        $turn = $control === null ? null : AgentTurn::query()->where('agent_id', $agent->id)->whereNull('codex_id')->find($control->agent_turn_id);

                        if ($turn !== null) {
                            $turn->update(['codex_id' => $saved['id']]);
                        } else {
                            $turn = AgentTurn::query()->create(['agent_id' => $agent->id, 'codex_id' => $saved['id'], 'status' => 'uncertain']);
                        }
                    }

                    $turn->update(['status' => in_array($saved['status'], ['completed', 'failed', 'interrupted'], true) ? $saved['status'] : 'uncertain']);

                    foreach ($saved['items'] ?? [] as $item) {
                        if ($item['type'] === 'agentMessage') {
                            $message = AgentMessage::query()->firstOrNew(['agent_id' => $agent->id, 'item_id' => $item['id']]);
                            $message->fill(['agent_turn_id' => $turn->id, 'role' => 'assistant', 'kind' => 'task', 'body' => $this->output->text($item['text'])])->save();
                            $this->store->event($agent, 'message', $this->presenter->message($message));
                        } elseif ($item['type'] === 'userMessage' && ! AgentMessage::query()->where('agent_turn_id', $turn->id)->where('role', 'user')->exists()) {
                            $body = collect($item['content'])->where('type', 'text')->pluck('text')->implode("\n");
                            $this->store->message($agent, $turn->id, 'user', $body, 'task', $item['id']);
                        }
                    }

                    $this->store->event($agent, 'turn', ['id' => $turn->id, 'status' => $turn->status]);
                }, false);
            }

            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor !== null);

        $read = $this->rpc('thread/read', ['threadId' => $this->agent->thread_id, 'includeTurns' => false]);

        if (($read['thread']['status']['type'] ?? null) !== 'idle') {
            throw new RuntimeException('The saved conversation is not confirmed idle.');
        }
    }
}
