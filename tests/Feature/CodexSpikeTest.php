<?php

namespace Tests\Feature;

use App\Services\Codex\AppServerClient;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CodexSpikeTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    /** @var list<string> */
    private array $workspaces = [];

    protected function tearDown(): void
    {
        foreach ($this->workspaces as $workspace) {
            File::deleteDirectory($workspace);
        }

        parent::tearDown();
    }

    public function test_initializes_with_existing_chatgpt_auth_and_streams_a_completed_turn(): void
    {
        $this->fakeClient('completed');

        $this->artisan('codex:spike', ['--no-interaction' => true])
            ->expectsOutputToContain('Authentication: existing ChatGPT CLI login')
            ->expectsOutputToContain('Created thread: thread-1')
            ->expectsOutputToContain('Turn result: completed')
            ->expectsOutputToContain('cleanup complete')
            ->assertSuccessful();
    }

    public function test_reports_a_failed_turn_as_failure(): void
    {
        $this->fakeClient('failed');

        $this->artisan('codex:spike', ['--no-interaction' => true])
            ->expectsOutputToContain('Turn result: failed')
            ->assertExitCode(1);
    }

    public function test_reports_an_interrupted_turn_with_exit_code_130(): void
    {
        $this->fakeClient('interrupted');

        $this->artisan('codex:spike', ['--no-interaction' => true])
            ->expectsOutputToContain('Turn result: interrupted')
            ->assertExitCode(130);
    }

    public function test_noninteractive_permission_requests_are_denied_and_never_granted(): void
    {
        $request = ['id' => 'permission-1', 'method' => 'item/permissions/requestApproval', 'params' => ['threadId' => 'thread-1', 'turnId' => 'turn-1', 'itemId' => 'item-1', 'permissions' => ['network' => ['enabled' => true]]]];
        $this->fakeClient('completed', $request);

        $this->artisan('codex:spike', ['--no-interaction' => true])
            ->expectsOutputToContain('"permissions":{},"scope":"turn"')
            ->assertSuccessful();
    }

    public function test_denial_uses_cancel_when_the_server_does_not_offer_decline(): void
    {
        $request = ['id' => 0, 'method' => 'item/commandExecution/requestApproval', 'params' => ['threadId' => 'thread-1', 'turnId' => 'turn-1', 'itemId' => 'item-1', 'command' => 'echo safe', 'availableDecisions' => ['accept', 'cancel']]];
        $this->fakeClient('completed', $request);

        $this->artisan('codex:spike', ['--approval' => 'deny', '--no-interaction' => true])
            ->expectsOutputToContain('Permission response 0: {"decision":"cancel"}')
            ->assertSuccessful();
    }

    public function test_sends_turn_interrupt_after_the_cancellation_deadline(): void
    {
        $this->fakeClient('interrupted', cancel: true);

        $this->artisan('codex:spike', ['--cancel-after' => '0.001', '--no-interaction' => true])
            ->expectsOutputToContain('Requesting turn interruption.')
            ->assertExitCode(130);
    }

    public function test_turn_timeout_interrupts_the_turn_and_returns_124(): void
    {
        $this->fakeClient('interrupted', cancel: true);

        $this->artisan('codex:spike', ['--timeout' => '0.001', '--no-interaction' => true])
            ->expectsOutputToContain('Turn timeout: requesting interruption.')
            ->assertExitCode(124);
    }

    public function test_startup_failure_still_closes_the_owned_process(): void
    {
        $this->mock(AppServerClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')->once()->andReturnUsing(function (array $command, string $workspace): never {
                $this->workspaces[] = $workspace;
                throw new RuntimeException('App Server startup failed.');
            });
            $mock->shouldReceive('close')->once();
        });

        $this->artisan('codex:spike', ['--no-interaction' => true])
            ->expectsOutputToContain('App Server startup failed.')
            ->assertFailed();
    }

    public function test_refuses_to_use_the_application_repository_as_a_workspace(): void
    {
        $this->mock(AppServerClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('start');
            $mock->shouldReceive('close')->once();
        });

        $this->artisan('codex:spike', ['--workspace' => base_path()])
            ->expectsOutputToContain('must be a disposable repository')
            ->assertFailed();
    }

    public function test_refuses_to_resume_a_thread_owned_by_another_session(): void
    {
        $workspace = sys_get_temp_dir().'/ai-agents-spike-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($workspace.'/.git', 0700, true);
        File::put($workspace.'/.ai-agents-spike', 'test workspace');
        File::put($workspace.'/.ai-agents-spike-thread', 'owned-thread');
        $this->workspaces[] = $workspace;
        $this->mock(AppServerClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('start');
            $mock->shouldReceive('close')->once();
        });

        $this->artisan('codex:spike', ['--workspace' => $workspace, '--thread' => 'unrelated-thread'])
            ->expectsOutputToContain('must match the thread retained by this disposable workspace')
            ->assertFailed();
    }

    /** @param array<string, mixed>|null $request */
    private function fakeClient(string $status, ?array $request = null, bool $cancel = false): void
    {
        $this->mock(AppServerClient::class, function (MockInterface $mock) use ($status, $request, $cancel): void {
            $turnStarted = false;
            $answered = $request === null;
            $interrupted = false;
            $mock->shouldReceive('start')->once()->andReturnUsing(function (array $command, string $workspace): int {
                $this->workspaces[] = $workspace;
                $this->assertSame([config('services.codex.binary'), 'app-server', '--listen', 'stdio://'], $command);

                return 123;
            });
            $mock->shouldReceive('request')->with('initialize', \Mockery::on(fn (array $params): bool => $params['clientInfo']['name'] === 'ai_agents_spike'), 20.0, \Mockery::type('Closure'))->once()->andReturn([]);
            $mock->shouldReceive('notify')->with('initialized')->once();
            $mock->shouldReceive('request')->with('account/read', ['refreshToken' => false], 20.0, \Mockery::type('Closure'))->once()->andReturn(['account' => ['type' => 'chatgpt']]);
            $mock->shouldReceive('request')->with('thread/start', \Mockery::on(fn (array $params): bool => $params['sandbox'] === 'read-only' && $params['approvalPolicy'] === 'untrusted' && $params['approvalsReviewer'] === 'user'), 20.0, \Mockery::type('Closure'))->once()->andReturn(['thread' => ['id' => 'thread-1']]);
            $mock->shouldReceive('request')->with('turn/start', ['threadId' => 'thread-1', 'input' => [['type' => 'text', 'text' => 'Reply with exactly SPIKE_OK. Do not use tools.']]], 20.0, \Mockery::type('Closure'))->once()->andReturnUsing(function () use (&$turnStarted): array {
                $turnStarted = true;

                return ['turn' => ['id' => 'turn-1']];
            });
            $mock->shouldReceive('pendingRequests')->andReturnUsing(function () use (&$turnStarted, &$answered, $request): array {
                return $turnStarted && ! $answered ? [$request] : [];
            });

            if ($request !== null) {
                $mock->shouldReceive('respond')->once()->withArgs(function (int|string $id, array $response) use ($request): bool {
                    return $id === $request['id'] && ($request['method'] === 'item/permissions/requestApproval'
                        ? $response['permissions'] == (object) [] && $response['scope'] === 'turn'
                        : $response === ['decision' => 'cancel']);
                })->andReturnUsing(function () use (&$answered): void {
                    $answered = true;
                });
            }

            if ($cancel) {
                $mock->shouldReceive('request')->with('turn/interrupt', ['threadId' => 'thread-1', 'turnId' => 'turn-1'], 5)->once()->andReturnUsing(function () use (&$interrupted): array {
                    $interrupted = true;

                    return [];
                });
            }

            $mock->shouldReceive('completion')->with('thread-1', 'turn-1')->andReturnUsing(function () use ($status, &$answered, $cancel, &$interrupted): ?array {
                return $answered && (! $cancel || $interrupted) ? ['id' => 'turn-1', 'status' => $status] : null;
            });
            $mock->shouldReceive('poll')->andReturnNull();
            $mock->shouldReceive('close')->once();
        });
    }
}
