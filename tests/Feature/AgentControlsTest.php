<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentApproval;
use App\Models\AgentEvent;
use App\Models\AgentMessage;
use App\Models\AgentTurn;
use App\Models\User;
use App\Services\Codex\AgentStore;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AgentControlsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agents.workspaces.sandbox.path' => sys_get_temp_dir(), 'agents.stream_seconds' => 0]);
    }

    public function test_guests_cannot_read_history_or_submit_controls(): void
    {
        $agent = Agent::factory()->create();
        $this->get('/workshop')->assertRedirect('/login');

        foreach (['', '/events'] as $path) {
            $this->getJson('/workshop/agents/'.$agent->id.$path)->assertUnauthorized();
        }

        foreach (['tasks', 'steer', 'stop', 'decisions', 'recover'] as $action) {
            $this->postJson('/workshop/agents/'.$agent->id.'/'.$action, [])->assertUnauthorized();
        }

        $this->assertDatabaseCount('agent_controls', 0);
    }

    public function test_other_accounts_cannot_read_or_control_the_agent(): void
    {
        $agent = Agent::factory()->create();
        $this->actingAs(User::factory()->create());
        $this->getJson('/workshop/agents/'.$agent->id)->assertNotFound();
        $this->getJson('/workshop/agents/'.$agent->id.'/events')->assertNotFound();

        foreach (['tasks', 'steer', 'stop', 'decisions', 'recover'] as $action) {
            $this->postJson('/workshop/agents/'.$agent->id.'/'.$action, [])->assertNotFound();
        }
    }

    public function test_creates_only_one_named_agent_with_a_server_selected_workspace(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/workshop/agents', ['name' => 'Sage', 'workspace' => 'sandbox', 'workspace_path' => '/', 'binary' => 'malicious'])->assertCreated()->assertJsonPath('agent.workspace', sys_get_temp_dir());
        $this->assertDatabaseHas('agents', ['user_id' => $user->id, 'workspace_path' => sys_get_temp_dir(), 'thread_id' => null]);
        $this->postJson('/workshop/agents', ['name' => 'Another', 'workspace' => 'sandbox'])->assertConflict();
        $this->assertDatabaseCount('agents', 1);
    }

    public function test_rejects_an_arbitrary_workspace_path(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/workshop/agents', ['name' => 'Sage', 'workspace' => '/tmp'])->assertUnprocessable()->assertJsonValidationErrors('workspace');
        $this->assertDatabaseCount('agents', 0);
    }

    public function test_retries_a_task_without_creating_a_second_turn_or_control(): void
    {
        $agent = $this->ownedAgent();
        $payload = ['request_key' => (string) Str::uuid(), 'text' => 'Reply HELLO'];
        $first = $this->postJson($this->url($agent, 'tasks'), $payload)->assertAccepted();
        $second = $this->postJson($this->url($agent, 'tasks'), $payload)->assertAccepted();
        $this->assertSame($first->json('control_id'), $second->json('control_id'));
        $this->assertDatabaseCount('agent_controls', 1);
        $this->assertDatabaseCount('agent_turns', 1);
        $this->assertDatabaseCount('agent_messages', 1);
        $this->assertSame('queued', $agent->fresh()->status);
    }

    public function test_rejects_a_second_task_and_reused_key_with_different_content(): void
    {
        $agent = $this->ownedAgent();
        $key = (string) Str::uuid();
        $this->postJson($this->url($agent, 'tasks'), ['request_key' => $key, 'text' => 'First'])->assertAccepted();
        $this->postJson($this->url($agent, 'tasks'), ['request_key' => (string) Str::uuid(), 'text' => 'Second'])->assertConflict();
        $this->postJson($this->url($agent, 'tasks'), ['request_key' => $key, 'text' => 'Changed'])->assertConflict();
        $this->assertDatabaseCount('agent_controls', 1);
        $this->assertDatabaseCount('agent_turns', 1);
    }

    public function test_steering_is_a_separate_control_for_the_exact_running_turn(): void
    {
        $agent = $this->ownedAgent();
        $turn = $this->running($agent);
        $this->postJson($this->url($agent, 'steer'), ['request_key' => (string) Str::uuid(), 'text' => 'Focus on tests', 'turn_id' => $turn->id])->assertAccepted();
        $this->assertDatabaseHas('agent_controls', ['agent_turn_id' => $turn->id, 'kind' => 'steer']);
        $this->postJson($this->url($agent, 'steer'), ['request_key' => (string) Str::uuid(), 'text' => 'Late', 'turn_id' => $turn->id + 1])->assertConflict();
        $this->assertDatabaseCount('agent_turns', 1);
    }

    public function test_stop_waits_for_worker_confirmation_and_rejects_the_wrong_turn(): void
    {
        $agent = $this->ownedAgent();
        $turn = $this->running($agent);
        $this->postJson($this->url($agent, 'stop'), ['request_key' => (string) Str::uuid(), 'turn_id' => $turn->id + 1])->assertConflict();
        $payload = ['request_key' => (string) Str::uuid(), 'turn_id' => $turn->id];
        $this->postJson($this->url($agent, 'stop'), $payload)->assertAccepted();
        $this->postJson($this->url($agent, 'stop'), $payload)->assertAccepted();
        $this->assertSame('stopping', $agent->fresh()->status);
        $this->assertSame('running', $turn->fresh()->status);
        $this->assertDatabaseCount('agent_controls', 1);
    }

    public function test_permission_decisions_are_bound_once_to_the_pending_turn_and_connection(): void
    {
        $agent = $this->ownedAgent();
        $turn = $this->running($agent);
        $agent->update(['status' => 'waiting_permission']);
        $approval = AgentApproval::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'connection_id' => $agent->connection_id]);
        $data = ['request_key' => (string) Str::uuid(), 'turn_id' => $turn->id, 'approval_id' => $approval->id, 'decision' => 'allow'];
        $this->postJson($this->url($agent, 'decisions'), $data)->assertAccepted();
        $this->postJson($this->url($agent, 'decisions'), $data)->assertConflict();
        $this->assertSame('deciding', $approval->fresh()->status);
        $data['request_key'] = (string) Str::uuid();
        $this->postJson($this->url($agent, 'decisions'), $data)->assertConflict();
        $this->assertDatabaseCount('agent_controls', 1);
    }

    public function test_rejects_expired_or_previous_connection_approvals(): void
    {
        $agent = $this->ownedAgent();
        $turn = $this->running($agent);
        $expired = AgentApproval::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'status' => 'expired', 'connection_id' => $agent->connection_id]);
        $oldConnection = AgentApproval::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id]);

        foreach ([$expired, $oldConnection] as $approval) {
            $this->postJson($this->url($agent, 'decisions'), ['request_key' => (string) Str::uuid(), 'turn_id' => $turn->id, 'approval_id' => $approval->id, 'decision' => 'allow'])->assertConflict();
        }

        $this->assertDatabaseCount('agent_controls', 0);
    }

    public function test_replays_ordered_events_after_the_later_header_or_query_cursor(): void
    {
        $agent = $this->ownedAgent();
        $first = AgentEvent::factory()->create(['agent_id' => $agent->id, 'data' => ['label' => 'earlier']]);
        $second = AgentEvent::factory()->create(['agent_id' => $agent->id, 'data' => ['label' => 'middle']]);
        $last = AgentEvent::factory()->create(['agent_id' => $agent->id, 'data' => ['label' => 'latest']]);
        $response = $this->withHeader('Last-Event-ID', (string) $second->id)->get($this->url($agent, 'events').'?after='.$first->id)->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $stream = $response->streamedContent();
        $this->assertStringContainsString('id: '.$last->id, $stream);
        $this->assertStringContainsString('latest', $stream);
        $this->assertStringNotContainsString('middle', $stream);
        $this->assertStringNotContainsString('earlier', $stream);
        $this->assertDatabaseCount('agent_controls', 0);
    }

    public function test_refresh_returns_saved_conversation_without_private_runtime_fields(): void
    {
        $agent = $this->ownedAgent();
        $turn = $this->running($agent);
        AgentMessage::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'body' => 'Saved conversation']);
        AgentApproval::factory()->create(['agent_id' => $agent->id, 'agent_turn_id' => $turn->id, 'connection_id' => $agent->connection_id, 'request_payload' => ['privateRuntimeToken' => 'not-for-browser']]);
        $response = $this->getJson($this->url($agent, ''))->assertOk()->assertJsonPath('messages.0.body', 'Saved conversation');
        $response->assertDontSee('not-for-browser')->assertDontSee('server_pid')->assertDontSee('request_payload')->assertDontSee('thread-test');
    }

    public function test_offline_worker_and_unreconciled_failure_cannot_accept_work(): void
    {
        $agent = $this->ownedAgent();
        $agent->update(['heartbeat_at' => now()->subMinute()]);
        $this->postJson($this->url($agent, 'tasks'), ['request_key' => (string) Str::uuid(), 'text' => 'Hello'])->assertConflict();
        $agent->update(['heartbeat_at' => now(), 'status' => 'needs_recovery', 'reconciled_at' => null]);
        $this->postJson($this->url($agent, 'recover'), ['request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson($this->url($agent, ''))->assertJsonPath('agent.can_recover', false);
        $agent->update(['reconciled_at' => now()]);
        $this->postJson($this->url($agent, 'recover'), ['request_key' => (string) Str::uuid()])->assertAccepted();
        $this->assertSame('recovering', $agent->fresh()->status);
    }

    public function test_unexpected_errors_do_not_expose_private_runtime_details_to_the_browser(): void
    {
        $agent = $this->ownedAgent();
        $this->mock(AgentStore::class)->shouldReceive('submit')->once()->andThrow(new RuntimeException('private-runtime-details'));
        $this->postJson($this->url($agent, 'tasks'), ['request_key' => (string) Str::uuid(), 'text' => 'Hello'])
            ->assertStatus(503)->assertExactJson(['message' => 'The workshop is temporarily unavailable. Check the server and reconnect.']);
    }

    private function ownedAgent(): Agent
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return Agent::factory()->create(['user_id' => $user->id]);
    }

    private function running(Agent $agent): AgentTurn
    {
        $turn = AgentTurn::factory()->create(['agent_id' => $agent->id, 'codex_id' => 'native-turn', 'status' => 'running']);
        $agent->update(['active_turn_id' => $turn->id, 'status' => 'running']);

        return $turn;
    }

    private function url(Agent $agent, string $action): string
    {
        return '/workshop/agents/'.$agent->id.($action === '' ? '' : '/'.$action);
    }
}
