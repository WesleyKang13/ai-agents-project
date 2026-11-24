<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Services\Codex\AgentWorker;
use App\Services\Codex\AgentWorkerLock;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AgentTargetingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = sys_get_temp_dir().'/selected-agent-test-'.bin2hex(random_bytes(8)).'.lock';
        config(['agents.worker_lock' => $this->lockPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        parent::tearDown();
    }

    public function test_worker_connects_only_the_selected_agent_and_owner(): void
    {
        $first = Agent::factory()->create();
        $selected = Agent::factory()->create();
        $this->mock(AgentWorker::class)->shouldReceive('run')->once()->withArgs(fn (Agent $agent, \Closure $stopping): bool => $agent->id === $selected->id && ! $stopping())->andReturn(true);

        $this->artisan('agents:work', ['agent' => $selected->id, '--user' => $selected->user_id])->assertSuccessful();

        $this->assertSame($first->thread_id, $first->fresh()->thread_id);
        $this->assertDatabaseCount('agent_controls', 0);
    }

    public function test_worker_refuses_an_agent_owned_by_someone_else(): void
    {
        $agent = Agent::factory()->create();
        $other = User::factory()->create();
        $this->mock(AgentWorker::class)->shouldNotReceive('run');

        $this->artisan('agents:work', ['agent' => $agent->id, '--user' => $other->id])->expectsOutput('No agent matches that ID and project owner. Nothing was connected.')->assertFailed();
    }

    #[TestWith(['999999', '1'])]
    #[TestWith(['first', '1'])]
    #[TestWith(['1', ''])]
    public function test_worker_refuses_missing_or_invalid_explicit_target(string $agent, string $owner): void
    {
        $this->mock(AgentWorker::class)->shouldNotReceive('run');

        $this->artisan('agents:work', ['agent' => $agent, '--user' => $owner])->assertFailed();
    }

    public function test_another_worker_cannot_connect_even_to_a_different_saved_agent(): void
    {
        $selected = Agent::factory()->create();
        $lock = new AgentWorkerLock;
        $lock->acquire();
        $this->mock(AgentWorker::class)->shouldNotReceive('run');

        try {
            $this->artisan('agents:work', ['agent' => $selected->id, '--user' => $selected->user_id])->assertFailed();
        } finally {
            $lock->release();
        }
    }
}
