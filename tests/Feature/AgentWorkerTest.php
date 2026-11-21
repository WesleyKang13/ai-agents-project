<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentApproval;
use App\Models\AgentControl;
use App\Models\AgentEvent;
use App\Models\AgentMessage;
use App\Models\AgentTurn;
use App\Models\User;
use App\Services\Codex\AgentStore;
use App\Services\Codex\AgentWorker;
use App\Services\Codex\AppServerClient;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AgentWorkerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Closure $observer;

    /** @var list<array{id: int, method: string, params: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<int, array<string, mixed>> */
    private array $responses = [];

    /** @var list<array<string, mixed>> */
    private array $nativeRequests = [];

    /** @var list<array<string, mixed>> */
    private array $decisions = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agents.workspaces.sandbox.path' => sys_get_temp_dir()]);
    }

    public function test_streams_and_persists_completion_even_before_the_start_response(): void
    {
        $agent = $this->agent();
        $phase = 0;
        $done = false;
        $this->peer(function () use ($agent, &$phase, &$done): void {
            if ($phase++ === 0) {
                $this->task($agent);
            } else {
                $this->started();
                $this->emit('item/agentMessage/delta', ['turnId' => 'turn-native', 'itemId' => 'message-native', 'delta' => 'HELLO']);
                $this->completed('completed');
                $this->responses[10] = ['id' => 10, 'result' => ['turn' => ['id' => 'turn-native']]];
                $done = true;
            }
        });
        $this->assertTrue($this->worker()->run($agent, function () use (&$done): bool {
            return $done;
        }));
        $this->assertSame('completed', AgentTurn::query()->sole()->status);
        $this->assertDatabaseHas('agent_messages', ['role' => 'assistant', 'body' => 'HELLO']);
        $this->assertSame('completed', AgentControl::query()->sole()->status);
        $this->assertCount(1, $this->calls);
        $this->assertSame('turn/start', $this->calls[0]['method']);
    }

    public function test_sends_explicit_steering_to_the_expected_turn_without_starting_another(): void
    {
        $agent = $this->agent();
        $phase = 0;
        $done = false;
        $this->peer(function () use ($agent, &$phase, &$done): void {
            if ($phase === 0) {
                $this->task($agent);
            } elseif ($phase === 1) {
                $this->started();
                $this->responses[10] = ['id' => 10, 'result' => ['turn' => ['id' => 'turn-native']]];
                $turn = AgentTurn::query()->sole();
                $this->app->make(AgentStore::class)->submit($agent->fresh(), 'steer', (string) Str::uuid(), ['turn_id' => $turn->id, 'text' => 'Focus on tests']);
            } else {
                $this->responses[11] = ['id' => 11, 'result' => ['turnId' => 'turn-native']];
                $this->completed('completed');
                $done = true;
            }
            $phase++;
        });
        $this->worker()->run($agent, function () use (&$done): bool {
            return $done;
        });
        $this->assertSame(['turn/start', 'turn/steer'], array_column($this->calls, 'method'));
        $this->assertSame('turn-native', $this->calls[1]['params']['expectedTurnId']);
        $this->assertDatabaseHas('agent_messages', ['kind' => 'steer', 'body' => 'Focus on tests']);
        $this->assertDatabaseCount('agent_turns', 1);
    }

    public function test_stop_sends_interrupt_and_waits_for_the_interrupted_event(): void
    {
        $agent = $this->agent();
        $phase = 0;
        $done = false;
        $this->peer(function () use ($agent, &$phase, &$done): void {
            if ($phase === 0) {
                $this->task($agent);
            } elseif ($phase === 1) {
                $this->started();
                $this->responses[10] = ['id' => 10, 'result' => ['turn' => ['id' => 'turn-native']]];
                $this->app->make(AgentStore::class)->submit($agent->fresh(), 'stop', (string) Str::uuid(), ['turn_id' => AgentTurn::query()->sole()->id]);
            } else {
                $this->assertSame('stopping', $agent->fresh()->status);
                $this->responses[11] = ['id' => 11, 'result' => []];
                $this->completed('interrupted');
                $done = true;
            }
            $phase++;
        });
        $this->worker()->run($agent, function () use (&$done): bool {
            return $done;
        });
        $this->assertSame('turn/interrupt', $this->calls[1]['method']);
        $this->assertSame('turn-native', $this->calls[1]['params']['turnId']);
        $this->assertSame('interrupted', AgentTurn::query()->sole()->status);
        $this->assertDatabaseMissing('agent_controls', ['status' => 'pending']);
    }

    public function test_interruption_replaces_the_owned_server_and_resumes_without_replaying_work(): void
    {
        $agent = $this->agent();
        $phase = 0;
        $done = false;
        $this->peer(function () use ($agent, &$phase, &$done): void {
            if ($phase === 0) {
                $this->task($agent);
            } elseif ($phase === 1) {
                $this->started();
                $this->completed('interrupted');
                $this->responses[10] = ['id' => 10, 'result' => ['turn' => ['id' => 'turn-native']]];
            } else {
                $this->assertSame('idle', $agent->fresh()->status);
                $this->assertSame('Task interrupted.', $agent->fresh()->notice);
                $done = true;
            }
            $phase++;
        }, history: [['id' => 'turn-native', 'status' => 'interrupted', 'items' => []]], rotate: true);
        $this->assertTrue($this->worker()->run($agent, function () use (&$done): bool {
            return $done;
        }));
        $this->assertCount(1, $this->calls);
        $this->assertDatabaseCount('agent_turns', 1);
        $this->assertSame('interrupted', AgentTurn::query()->sole()->status);
    }

    public function test_approval_grants_only_the_exact_pending_command_once(): void
    {
        $this->permissionCase('allow', ['decision' => 'accept']);
    }

    public function test_denial_uses_the_available_cancel_decision_without_granting_access(): void
    {
        $this->permissionCase('deny', ['decision' => 'cancel']);
    }

    public function test_lost_start_response_leaves_work_uncertain_and_does_not_retry(): void
    {
        $agent = $this->agent();
        $phase = 0;
        $this->peer(function () use ($agent, &$phase): void {
            if ($phase++ === 0) {
                $this->task($agent);
            } else {
                throw new RuntimeException('private-native-error');
            }
        });
        $this->assertFalse($this->worker()->run($agent, fn (): bool => false));
        $this->assertSame('uncertain', AgentControl::query()->sole()->status);
        $this->assertSame('needs_recovery', $agent->fresh()->status);
        $this->assertNull($agent->fresh()->reconciled_at);
        $this->assertStringNotContainsString('private-native-error', $agent->fresh()->notice);
        $this->assertCount(1, $this->calls);
    }

    public function test_restart_reconciles_history_and_expires_permissions_without_replaying_controls(): void
    {
        $agent = $this->agent();
        $agent->update(['thread_id' => 'thread-test']);
        $turn = AgentTurn::factory()->create(['agent_id' => $agent->id, 'codex_id' => 'old-turn', 'status' => 'running']);
        $agent->update(['active_turn_id' => $turn->id]);
        AgentApproval::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'connection_id' => $agent->connection_id]);
        AgentControl::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'kind' => 'decision', 'status' => 'processing']);
        $history = [['id' => 'old-turn', 'status' => 'interrupted', 'items' => [['id' => 'old-message', 'type' => 'agentMessage', 'text' => 'Recovered answer']]]];
        $this->peer(function (): void {}, true, $history);
        $checked = false;
        $this->worker()->run($agent, function () use ($agent, &$checked): bool {
            $checked = true;
            $this->assertSame('needs_recovery', $agent->fresh()->status);
            $this->assertNotNull($agent->fresh()->reconciled_at);

            return true;
        });
        $this->assertTrue($checked);
        $this->assertSame('interrupted', $turn->fresh()->status);
        $this->assertSame('expired', AgentApproval::query()->sole()->status);
        $this->assertSame('uncertain', AgentControl::query()->sole()->status);
        $this->assertDatabaseHas('agent_messages', ['body' => 'Recovered answer']);
        $this->assertSame([], $this->calls);
    }

    public function test_rejects_non_chatgpt_authentication_without_starting_a_thread(): void
    {
        $agent = $this->agent();
        $this->mock(AppServerClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')->once()->andReturn(123);
            $mock->shouldReceive('ownership')->once()->andReturnNull();
            $mock->shouldReceive('request')->with('initialize', \Mockery::any(), \Mockery::any(), \Mockery::any())->once()->andReturn([]);
            $mock->shouldReceive('notify')->with('initialized')->once();
            $mock->shouldReceive('request')->with('account/read', ['refreshToken' => false], \Mockery::any(), \Mockery::any())->once()->andReturn(['account' => ['type' => 'apiKey']]);
            $mock->shouldReceive('close')->once();
        });
        $this->assertFalse($this->worker()->run($agent, fn (): bool => false));
        $this->assertDatabaseCount('agent_turns', 0);
    }

    public function test_reconciles_a_lost_start_response_by_client_message_identity_without_duplicate_history(): void
    {
        $agent = $this->agent();
        $agent->update(['thread_id' => 'thread-test']);
        $turn = AgentTurn::factory()->create(['agent_id' => $agent->id, 'status' => 'starting']);
        $agent->update(['active_turn_id' => $turn->id]);
        AgentMessage::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'body' => 'A tiny task']);
        $control = AgentControl::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'status' => 'processing']);
        $history = [['id' => 'recovered-turn', 'status' => 'completed', 'items' => [
            ['id' => 'native-user', 'clientId' => $control->request_key, 'type' => 'userMessage', 'content' => [['type' => 'text', 'text' => 'A tiny task']]],
            ['id' => 'native-answer', 'type' => 'agentMessage', 'text' => 'Recovered answer'],
        ]]];
        $this->peer(function (): void {}, true, $history);
        $this->worker()->run($agent, fn (): bool => true);
        $this->assertDatabaseCount('agent_turns', 1);
        $this->assertDatabaseCount('agent_messages', 2);
        $this->assertSame('recovered-turn', $turn->fresh()->codex_id);
        $this->assertSame('completed', $turn->fresh()->status);
        $this->assertSame([], $this->calls);
    }

    /** @param array<string, mixed> $expected */
    private function permissionCase(string $decision, array $expected): void
    {
        $agent = $this->agent();
        $phase = 0;
        $done = false;
        $this->peer(function () use ($agent, $decision, &$phase, &$done): void {
            if ($phase === 0) {
                $this->task($agent);
            } elseif ($phase === 1) {
                $this->started();
                $request = ['id' => 0, 'method' => 'item/commandExecution/requestApproval', 'params' => ['threadId' => 'thread-test', 'turnId' => 'turn-native', 'itemId' => 'command-1', 'command' => 'python3 -c "print(1)"', 'cwd' => sys_get_temp_dir(), 'availableDecisions' => ['accept', 'cancel']]];
                $this->nativeRequests = [$request];
                ($this->observer)($request);
                $this->responses[10] = ['id' => 10, 'result' => ['turn' => ['id' => 'turn-native']]];
            } elseif ($phase === 2) {
                $this->assertSame('waiting_permission', $agent->fresh()->status);
                $approval = AgentApproval::query()->sole();
                $this->assertSame('pending', AgentEvent::query()->where('type', 'approval')->firstOrFail()->data['status']);
                $this->app->make(AgentStore::class)->submit($agent->fresh(), 'decision', (string) Str::uuid(), ['turn_id' => $approval->agent_turn_id, 'approval_id' => $approval->id, 'decision' => $decision]);
            } else {
                $this->completed($decision === 'allow' ? 'completed' : 'interrupted');
                $done = true;
            }
            $phase++;
        });
        $this->worker()->run($agent, function () use (&$done): bool {
            return $done;
        });
        $this->assertSame([['id' => 0, 'result' => $expected]], $this->decisions);
        $this->assertSame($decision === 'allow' ? 'accepted' : 'denied', AgentApproval::query()->sole()->status);
    }

    /** @param list<array<string, mixed>> $history */
    private function peer(Closure $poll, bool $resuming = false, array $history = [], bool $rotate = false): void
    {
        $this->mock(AppServerClient::class, function (MockInterface $mock) use ($poll, $resuming, $history, $rotate): void {
            $connections = $rotate ? 2 : 1;
            $mock->shouldReceive('start')->times($connections)->andReturnUsing(function (array $command, string $workspace, Closure $observer): int {
                $this->observer = $observer;

                return 123;
            });
            $mock->shouldReceive('ownership')->times($connections)->andReturnNull();
            $mock->shouldReceive('request')->with('initialize', \Mockery::any(), \Mockery::any(), \Mockery::any())->times($connections)->andReturn([]);
            $mock->shouldReceive('notify')->with('initialized')->times($connections);
            $mock->shouldReceive('request')->with('account/read', ['refreshToken' => false], \Mockery::any(), \Mockery::any())->times($connections)->andReturn(['account' => ['type' => 'chatgpt']]);
            $mock->shouldReceive('request')->with($resuming ? 'thread/resume' : 'thread/start', \Mockery::any(), \Mockery::any(), \Mockery::any())->once()->andReturn(['thread' => ['id' => 'thread-test']]);
            if ($rotate) {
                $mock->shouldReceive('renew')->once();
                $mock->shouldReceive('request')->with('thread/resume', \Mockery::any(), \Mockery::any(), \Mockery::any())->once()->andReturn(['thread' => ['id' => 'thread-test']]);
            }
            if ($resuming || $rotate) {
                $mock->shouldReceive('request')->with('thread/turns/list', \Mockery::any(), \Mockery::any(), \Mockery::any())->once()->andReturn(['data' => $history, 'nextCursor' => null]);
                $mock->shouldReceive('request')->with('thread/read', \Mockery::any(), \Mockery::any(), \Mockery::any())->once()->andReturn(['thread' => ['status' => ['type' => 'idle']]]);
            }
            $mock->shouldReceive('poll')->andReturnUsing($poll);
            $mock->shouldReceive('beginRequest')->andReturnUsing(function (string $method, array $params): int {
                $id = 10 + count($this->calls);
                $this->calls[] = compact('id', 'method', 'params');

                return $id;
            });
            $mock->shouldReceive('takeResponse')->andReturnUsing(function (int $id): ?array {
                $response = $this->responses[$id] ?? null;
                unset($this->responses[$id]);

                return $response;
            });
            $mock->shouldReceive('pendingRequests')->andReturnUsing(fn (): array => $this->nativeRequests);
            $mock->shouldReceive('respond')->andReturnUsing(function (int|string $id, array $result): void {
                $this->decisions[] = compact('id', 'result');
                $this->nativeRequests = [];
            });
            $mock->shouldReceive('close')->times($connections);
        });
    }

    private function agent(): Agent
    {
        return Agent::factory()->create(['user_id' => User::factory()->create()->id, 'thread_id' => null]);
    }

    private function task(Agent $agent): void
    {
        $this->app->make(AgentStore::class)->submit($agent->fresh(), 'task', (string) Str::uuid(), ['text' => 'A tiny task']);
    }

    private function started(): void
    {
        $this->emit('turn/started', ['turn' => ['id' => 'turn-native']]);
    }

    private function completed(string $status): void
    {
        $this->emit('turn/completed', ['turn' => ['id' => 'turn-native', 'status' => $status]]);
    }

    /** @param array<string, mixed> $params */
    private function emit(string $method, array $params): void
    {
        ($this->observer)(['method' => $method, 'params' => ['threadId' => 'thread-test', ...$params]]);
    }

    private function worker(): AgentWorker
    {
        return $this->app->make(AgentWorker::class);
    }
}
