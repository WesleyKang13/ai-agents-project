<?php

namespace App\Services\Codex;

use Closure;
use RuntimeException;

class AppServerClient
{
    private int $nextId = 0;

    /** @var array<int, array<string, mixed>|null> */
    private array $responses = [];

    /** @var array<string, array<string, mixed>> */
    private array $serverRequests = [];

    /** @var array<string, array<string, mixed>> */
    private array $completions = [];

    private ?Closure $observer = null;

    private bool $exited = false;

    public function __construct(private OwnedProcess $process, private JsonLineDecoder $decoder) {}

    /** @param list<string> $command */
    public function start(array $command, string $workspace, Closure $observer): int
    {
        $this->observer = $observer;

        return $this->process->start($command, $workspace);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params, float $timeout = 15, ?Closure $tick = null): array
    {
        $id = ++$this->nextId;
        $this->responses[$id] = null;

        try {
            $this->send(['id' => $id, 'method' => $method, 'params' => (object) $params]);
            $deadline = microtime(true) + $timeout;

            while ($this->responses[$id] === null) {
                $tick?->__invoke();

                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Timed out waiting for {$method}.", 124);
                }

                $this->poll();
                usleep(10000);
            }

            $response = $this->responses[$id];

            if (isset($response['error'])) {
                $code = (int) ($response['error']['code'] ?? 0);
                throw new RuntimeException("App Server rejected {$method} (RPC code {$code}; details suppressed).");
            }

            if (! is_array($response['result'])) {
                throw new RuntimeException("Invalid result for {$method}.");
            }

            return $response['result'];
        } finally {
            unset($this->responses[$id]);
        }
    }

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params = []): void
    {
        $this->send(['method' => $method, 'params' => (object) $params]);
    }

    /** @param array<string, mixed> $result */
    public function respond(int|string $id, array $result): void
    {
        $key = json_encode($id, JSON_THROW_ON_ERROR);

        if (! isset($this->serverRequests[$key])) {
            throw new RuntimeException('Approval is stale or has already been answered.');
        }

        $this->send(['id' => $id, 'result' => (object) $result]);
        unset($this->serverRequests[$key]);
    }

    public function reject(int|string $id): void
    {
        $this->send(['id' => $id, 'error' => ['code' => -32601, 'message' => 'Unsupported server request in the spike client.']]);
        unset($this->serverRequests[json_encode($id, JSON_THROW_ON_ERROR)]);
    }

    /** @return list<array<string, mixed>> */
    public function pendingRequests(): array
    {
        return array_values($this->serverRequests);
    }

    /** @return array<string, mixed>|null */
    public function completion(string $threadId, string $turnId): ?array
    {
        $key = $threadId.':'.$turnId;
        $completion = $this->completions[$key] ?? null;
        unset($this->completions[$key]);

        return $completion;
    }

    public function poll(): void
    {
        if ($this->exited) {
            throw new RuntimeException('App Server exited unexpectedly (code '.($this->process->exitCode() ?? 'unknown').').');
        }

        $output = $this->process->read();
        $running = $this->process->running();

        if (! $running) {
            $remaining = $this->process->read();
            $output['stdout'] .= $remaining['stdout'];
            $output['stderrBytes'] += $remaining['stderrBytes'];
        }

        if ($output['stderrBytes'] > 0) {
            $this->observer?->__invoke(['method' => 'transport/stderr', 'params' => ['bytes' => $output['stderrBytes']]]);
        }

        $messages = $this->decoder->feed($output['stdout']);

        foreach ($messages as $message) {
            $this->dispatch($message);
        }

        if (! $running) {
            $this->exited = true;
            $this->decoder->finish();

            if ($messages === []) {
                throw new RuntimeException('App Server exited unexpectedly (code '.($this->process->exitCode() ?? 'unknown').').');
            }
        }
    }

    public function close(): void
    {
        $this->process->close();
    }

    public function renew(): void
    {
        $this->close();
        $this->process = new OwnedProcess;
        $this->decoder = new JsonLineDecoder;
        $this->responses = $this->serverRequests = $this->completions = [];
        $this->nextId = 0;
        $this->exited = false;
    }

    /** @param array<string, mixed> $params */
    public function beginRequest(string $method, array $params): int
    {
        $id = ++$this->nextId;
        $this->responses[$id] = null;
        $this->send(['id' => $id, 'method' => $method, 'params' => (object) $params]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function takeResponse(int $id): ?array
    {
        $response = $this->responses[$id] ?? null;

        if ($response !== null) {
            unset($this->responses[$id]);
        }

        return $response;
    }

    /** @return array{pid: int, start: string}|null */
    public function ownership(): ?array
    {
        return $this->process->ownership();
    }

    /** @param array<string, mixed> $message */
    private function dispatch(array $message): void
    {
        if (isset($message['method'])) {
            if (! is_string($message['method']) || (isset($message['params']) && ! is_array($message['params']))) {
                throw new RuntimeException('Malformed App Server event.');
            }

            if (array_key_exists('id', $message)) {
                if (! is_int($message['id']) && ! is_string($message['id'])) {
                    throw new RuntimeException('Invalid server request ID.');
                }

                $key = json_encode($message['id'], JSON_THROW_ON_ERROR);

                if (isset($this->serverRequests[$key])) {
                    throw new RuntimeException('Duplicate server request ID.');
                }

                $this->serverRequests[$key] = $message;
            } elseif ($message['method'] === 'serverRequest/resolved') {
                $key = json_encode($message['params']['requestId'] ?? null, JSON_THROW_ON_ERROR);
                unset($this->serverRequests[$key]);
            } elseif ($message['method'] === 'turn/completed') {
                $params = $message['params'];
                $this->completions[$params['threadId'].':'.$params['turn']['id']] = $params['turn'];
            }

            $this->observer?->__invoke($message);

            return;
        }

        $id = $message['id'] ?? null;

        if (! is_int($id) || ! array_key_exists($id, $this->responses) || $this->responses[$id] !== null
            || (array_key_exists('result', $message) === array_key_exists('error', $message))) {
            throw new RuntimeException('Unmatched or malformed App Server response.');
        }

        $this->responses[$id] = $message;
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $this->process->write(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }
}
