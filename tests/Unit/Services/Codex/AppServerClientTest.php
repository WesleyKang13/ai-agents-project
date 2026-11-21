<?php

namespace Tests\Unit\Services\Codex;

use App\Services\Codex\AppServerClient;
use App\Services\Codex\JsonLineDecoder;
use App\Services\Codex\OwnedProcess;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AppServerClientTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_correlates_responses_while_streaming_events_and_answering_a_server_request_with_the_same_id(): void
    {
        $events = [];
        $process = new OwnedProcess;
        $client = new AppServerClient($process, new JsonLineDecoder);
        $pid = $client->start([PHP_BINARY, $this->fixture(), 'protocol'], sys_get_temp_dir(), function (array $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $this->assertSame(['ok' => true], $client->request('probe', [], 2));
            $this->assertSame(1, $client->pendingRequests()[0]['id']);
            $client->respond(1, ['decision' => 'decline']);
            $reply = $client->request('verify', [], 2);
            $this->assertSame(['decision' => 'decline'], $reply['decision']);
            $this->assertSame([], $client->pendingRequests());
            $this->assertSame(['id' => 'turn-1', 'status' => 'completed'], $client->completion('thread-1', 'turn-1'));
            $this->assertSame(null, $client->completion('thread-1', 'turn-1'));
            $this->assertSame(['transport/stderr', 'item/commandExecution/requestApproval', 'item/agentMessage/delta', 'turn/completed'], array_column($events, 'method'));
            $this->assertStringNotContainsString('secret-test-token', json_encode($events, JSON_THROW_ON_ERROR));
        } finally {
            $client->close();
        }

        $this->assertFalse(posix_kill($pid, 0));
    }

    public function test_does_not_forward_database_or_api_credentials_to_the_child(): void
    {
        $_ENV['DB_PASSWORD'] = 'private-database-password';
        $_ENV['OPENAI_API_KEY'] = 'private-api-key';

        try {
            $this->withPeer('protocol', function (AppServerClient $client): void {
                $reply = $client->request('verify', [], 2);
                $this->assertFalse($reply['databaseSecret']);
                $this->assertFalse($reply['apiKey']);
            });
        } finally {
            unset($_ENV['DB_PASSWORD'], $_ENV['OPENAI_API_KEY']);
        }
    }

    public function test_rejects_a_stale_approval(): void
    {
        $this->withPeer('protocol', function (AppServerClient $client): void {
            $client->request('probe', [], 2);
            $client->respond(1, ['decision' => 'decline']);
            $this->expectExceptionMessage('Approval is stale or has already been answered.');
            $client->respond(1, ['decision' => 'accept']);
        });
    }

    public function test_reports_rpc_errors_without_the_server_error_text(): void
    {
        $this->withPeer('protocol', function (AppServerClient $client): void {
            $this->expectExceptionMessage('App Server rejected error (RPC code -32602; details suppressed).');
            $client->request('error', [], 2);
        });
    }

    public function test_rejects_an_unmatched_response(): void
    {
        $this->withPeer('protocol', function (AppServerClient $client): void {
            $this->expectExceptionMessage('Unmatched or malformed App Server response.');
            $client->request('unmatched', [], 2);
        });
    }

    public function test_reports_malformed_output(): void
    {
        $this->withPeer('protocol', function (AppServerClient $client): void {
            $this->expectExceptionMessage('Invalid JSON from App Server; payload suppressed.');
            $client->request('malformed', [], 2);
        });
    }

    public function test_times_out_and_cleans_up_an_unresponsive_child(): void
    {
        $this->withPeer('hang', function (AppServerClient $client): void {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionCode(124);
            $this->expectExceptionMessage('Timed out waiting for initialize.');
            $client->request('initialize', [], 0.05);
        });
    }

    public function test_reports_an_unexpected_process_exit(): void
    {
        $this->withPeer('exit', function (AppServerClient $client): void {
            $this->expectExceptionMessage('App Server exited unexpectedly (code 23).');
            $client->request('initialize', [], 2);
        });
    }

    public function test_reports_partial_output_on_exit(): void
    {
        $this->withPeer('partial-exit', function (AppServerClient $client): void {
            $this->expectExceptionMessage('App Server exited with a partial JSON message.');
            $client->request('initialize', [], 2);
        });
    }

    public function test_cleanup_terminates_owned_descendants_and_leaves_an_unrelated_process_alive(): void
    {
        $unrelated = new OwnedProcess;
        $unrelatedPid = $unrelated->start([PHP_BINARY, $this->fixture(), 'hang'], sys_get_temp_dir());
        $childPid = null;

        try {
            $this->withPeer('protocol', function (AppServerClient $client) use (&$childPid): void {
                $childPid = $client->request('descendant', [], 2)['child'];
                $this->assertTrue(posix_kill($childPid, 0));
            });

            $this->assertFalse(posix_kill($childPid, 0));
            $this->assertTrue(posix_kill($unrelatedPid, 0));
        } finally {
            $unrelated->close();
        }
    }

    public function test_reports_a_missing_executable_and_cleans_up(): void
    {
        $client = new AppServerClient(new OwnedProcess, new JsonLineDecoder);

        try {
            $client->start(['/does-not-exist/codex'], sys_get_temp_dir(), function (): void {});
            $client->request('initialize', [], 2);
            $this->fail('A missing executable must fail.');
        } catch (RuntimeException $exception) {
            $this->assertMatchesRegularExpression('/startup failed|not running|exited unexpectedly/', $exception->getMessage());
        } finally {
            $client->close();
        }
    }

    private function withPeer(string $scenario, Closure $test): void
    {
        $process = new OwnedProcess;
        $client = new AppServerClient($process, new JsonLineDecoder);
        $pid = $client->start([PHP_BINARY, $this->fixture(), $scenario], sys_get_temp_dir(), function (): void {});

        try {
            $test($client);
        } finally {
            $client->close();
            $this->assertFalse($process->running());
            $this->assertFalse(posix_kill($pid, 0));
        }
    }

    private function fixture(): string
    {
        return dirname(__DIR__, 3).'/Fixtures/codex-peer.php';
    }

    public function test_a_new_connection_discards_old_permissions_and_terminal_events(): void
    {
        $client = new AppServerClient(new OwnedProcess, new JsonLineDecoder);

        try {
            $client->start([PHP_BINARY, $this->fixture(), 'protocol'], sys_get_temp_dir(), function (): void {});
            $client->request('probe', [], 2);
            $client->renew();
            $this->assertSame([], $client->pendingRequests());
            $this->assertNull($client->completion('thread-1', 'turn-1'));
            $client->start([PHP_BINARY, $this->fixture(), 'protocol'], sys_get_temp_dir(), function (): void {});
            $this->assertNull($client->request('verify', [], 2)['decision']);
        } finally {
            $client->close();
        }
    }

    public function test_correlates_two_outstanding_controls_when_responses_arrive_in_reverse_order(): void
    {
        $this->withPeer('protocol', function (AppServerClient $client): void {
            $first = $client->beginRequest('async-first', []);
            $second = $client->beginRequest('async-second', []);
            $answers = [];
            $deadline = microtime(true) + 2;

            while (count($answers) < 2 && microtime(true) < $deadline) {
                $client->poll();

                foreach ([$first, $second] as $id) {
                    $response = $client->takeResponse($id);

                    if ($response !== null) {
                        $answers[$id] = $response['result'];
                    }
                }

                usleep(1000);
            }

            $this->assertSame(['order' => 'first'], $answers[$first]);
            $this->assertSame(['order' => 'second'], $answers[$second]);
        });
    }
}
