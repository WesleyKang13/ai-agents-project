<?php

namespace App\Console\Commands;

use App\Services\Codex\AppServerClient;
use App\Services\Codex\OwnedProcess;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Process\Process;
use Throwable;

class CodexSpike extends Command
{
    protected $signature = 'codex:spike
        {--workspace= : Reuse a disposable workspace printed by a previous run}
        {--thread= : Resume a persisted thread in that workspace}
        {--task=Reply with exactly SPIKE_OK. Do not use tools. : Tiny task to submit}
        {--approval=ask : ask for each permission, or deny all requests}
        {--timeout=120 : Maximum turn duration in seconds, including approval waits}
        {--request-timeout=20 : Initialization and RPC timeout in seconds}
        {--cancel-after= : Interrupt the turn after this many seconds}';

    protected $description = 'Run a dedicated Codex App Server over stdio in a disposable repository';

    private bool $interrupted = false;

    private ?string $threadId = null;

    private ?string $turnId = null;

    private int $sequence = 0;

    /** @var array<string, bool> */
    private array $shownRequests = [];

    private string $inputBuffer = '';

    public function handle(AppServerClient $client): int
    {
        $this->interrupted = false;
        $this->threadId = $this->turnId = null;
        $this->sequence = 0;
        $this->shownRequests = [];
        $this->inputBuffer = '';
        $stdinBlocking = stream_get_meta_data(STDIN)['blocked'];

        try {
            $timeout = $this->seconds('timeout');
            $requestTimeout = $this->seconds('request-timeout');
            $cancelAfter = $this->option('cancel-after') === null ? null : $this->seconds('cancel-after');

            if (! in_array($this->option('approval'), ['ask', 'deny'], true)) {
                throw new RuntimeException('--approval must be ask or deny.');
            }

            if (! extension_loaded('pcntl')) {
                throw new RuntimeException('The spike requires pcntl for interruption handling.');
            }

            if ($this->option('thread') !== null && $this->option('workspace') === null) {
                throw new RuntimeException('Resumption requires the original --workspace.');
            }

            $workspace = $this->workspace();
            $this->display('Workspace: '.$workspace);
            $this->trap([SIGINT, SIGTERM], function (): void {
                $this->interrupted = true;
            });
            stream_set_blocking(STDIN, false);
            $pid = $client->start(
                [(string) config('services.codex.binary'), 'app-server', '--listen', 'stdio://'],
                $workspace,
                fn (array $message) => $this->observe($message),
            );
            $this->display('Owned App Server PID/group: '.$pid);

            $tick = function () use ($client): void {
                if ($this->interrupted) {
                    throw new RuntimeException('Interrupted by signal.', 130);
                }

                $this->permissions($client);
            };

            $client->request('initialize', ['clientInfo' => ['name' => 'ai_agents_spike', 'title' => 'AI Agents Laravel Spike', 'version' => '0.1.0']], $requestTimeout, $tick);
            $client->notify('initialized');
            $account = $client->request('account/read', ['refreshToken' => false], $requestTimeout, $tick);

            if (($account['account']['type'] ?? null) !== 'chatgpt') {
                throw new RuntimeException('This spike requires the existing ChatGPT CLI login. Run codex login status; authentication was not changed.');
            }

            $this->display('Authentication: existing ChatGPT CLI login (account details suppressed).');
            $params = [
                'cwd' => $workspace,
                'sandbox' => 'read-only',
                'approvalPolicy' => 'untrusted',
                'approvalsReviewer' => 'user',
                'developerInstructions' => 'This is a tiny integration test in a disposable repository. Work only in cwd. Never access credentials, personal files, other repositories or external services. Do not spawn agents. If permission is denied, stop that action and report the denial; do not try alternatives.',
            ];
            $resume = $this->option('thread');

            if ($resume !== null) {
                $params['threadId'] = (string) $resume;
            }

            $thread = $client->request($resume === null ? 'thread/start' : 'thread/resume', $params, $requestTimeout, $tick);
            $this->threadId = $thread['thread']['id'];

            if (file_put_contents($workspace.'/.ai-agents-spike-thread', $this->threadId) === false) {
                throw new RuntimeException('Could not retain the spike thread identity.');
            }

            $this->display(($resume === null ? 'Created' : 'Resumed').' thread: '.$this->threadId);
            $this->display('Model: '.($thread['model'] ?? 'configured default'));
            $turn = $client->request('turn/start', ['threadId' => $this->threadId, 'input' => [['type' => 'text', 'text' => (string) $this->option('task')]]], $requestTimeout, $tick);
            $this->turnId = $turn['turn']['id'];
            $this->display('Turn: '.$this->turnId);

            return $this->waitForTurn($client, $timeout, $cancelAfter, $requestTimeout);
        } catch (Throwable $exception) {
            $code = in_array($exception->getCode(), [124, 130], true) ? $exception->getCode() : self::FAILURE;
            $this->display('Harness '.($code === 130 ? 'interrupted' : 'failed').': '.($exception instanceof RuntimeException ? $exception->getMessage() : 'Unexpected local error; details suppressed.'));

            if ($this->threadId !== null && $this->turnId !== null) {
                try {
                    $client->request('turn/interrupt', ['threadId' => $this->threadId, 'turnId' => $this->turnId], 2);
                } catch (Throwable) {
                    $this->display('Interrupt acknowledgement unavailable; closing owned process.');
                }
            }

            return $code;
        } finally {
            $client->close();
            $this->untrap();
            stream_set_blocking(STDIN, $stdinBlocking);
            $this->display('Owned App Server cleanup complete. Workspace retained for resumption.');
        }
    }

    private function waitForTurn(AppServerClient $client, float $timeout, ?float $cancelAfter, float $requestTimeout): int
    {
        $started = microtime(true);
        $interruptSent = false;
        $timedOut = false;
        $interruptDeadline = null;

        while (true) {
            $completion = $client->completion($this->threadId, $this->turnId);

            if ($completion !== null) {
                $status = $completion['status'] ?? 'unknown';
                $this->display('Turn result: '.$status);

                if ($status === 'failed') {
                    $info = $completion['error']['codexErrorInfo'] ?? 'unspecified';
                    $this->display('Failure category: '.json_encode($info, JSON_THROW_ON_ERROR));
                }

                return $timedOut ? 124 : match ($status) {
                    'completed' => self::SUCCESS,
                    'interrupted' => 130,
                    default => self::FAILURE,
                };
            }

            $elapsed = microtime(true) - $started;
            $timedOut = $timedOut || $elapsed >= $timeout;

            if (! $interruptSent && ($this->interrupted || $timedOut || ($cancelAfter !== null && $elapsed >= $cancelAfter))) {
                $this->display($timedOut ? 'Turn timeout: requesting interruption.' : 'Requesting turn interruption.');
                $client->request('turn/interrupt', ['threadId' => $this->threadId, 'turnId' => $this->turnId], min(5, $requestTimeout));
                $interruptSent = true;
                $interruptDeadline = microtime(true) + 5;
            }

            if ($interruptDeadline !== null && microtime(true) >= $interruptDeadline) {
                throw new RuntimeException('No terminal event after interruption; stopping owned process.', $timedOut ? 124 : 130);
            }

            $this->permissions($client, $interruptSent);
            $client->poll();
            usleep(10000);
        }
    }

    /** @param array<string, mixed> $message */
    private function observe(array $message): void
    {
        $method = $message['method'];
        $params = $message['params'] ?? [];

        if ($method === 'turn/started' && ($params['threadId'] ?? null) === $this->threadId) {
            $this->turnId = $params['turn']['id'];
        }

        $summary = $method;

        if ($method === 'transport/stderr') {
            $summary .= ' bytes='.$params['bytes'].' (content suppressed)';
        } elseif ($method === 'item/agentMessage/delta' || $method === 'item/commandExecution/outputDelta') {
            $summary .= ' '.($params['delta'] ?? '');
        } elseif (isset($params['item']['type'])) {
            $summary .= ' type='.$params['item']['type'].' id='.$params['item']['id'];

            if ($method === 'item/completed' && $params['item']['type'] === 'agentMessage') {
                $summary .= ' text='.($params['item']['text'] ?? '');
            } elseif ($params['item']['type'] === 'commandExecution') {
                $summary .= ' status='.($params['item']['status'] ?? 'unknown').' exitCode='.json_encode($params['item']['exitCode'] ?? null);
            }
        } elseif ($method === 'turn/completed') {
            $summary .= ' status='.$params['turn']['status'];
        } elseif ($method === 'error') {
            $summary .= ' willRetry='.json_encode($params['willRetry'] ?? false).' (details suppressed)';
        }

        $this->display(sprintf('[%04d] %s', ++$this->sequence, $summary));
    }

    private function permissions(AppServerClient $client, bool $cancelling = false): void
    {
        $requests = $client->pendingRequests();

        foreach ($requests as $request) {
            $id = $request['id'];
            $key = json_encode($id, JSON_THROW_ON_ERROR);
            $method = $request['method'];
            $params = $request['params'] ?? [];

            if (! in_array($method, ['item/commandExecution/requestApproval', 'item/fileChange/requestApproval', 'item/permissions/requestApproval'], true)) {
                $client->reject($id);
                $this->display('Unsupported server request rejected: '.$method);

                continue;
            }

            if (! isset($this->shownRequests[$key])) {
                $context = array_intersect_key($params, array_flip(['threadId', 'turnId', 'itemId', 'command', 'cwd', 'reason', 'permissions', 'grantRoot', 'availableDecisions']));
                $this->display('Permission '.$key.': '.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $this->display('Respond with allow '.$key.', deny '.$key.' or cancel '.$key.' (once only; no session grants).');
                $this->shownRequests[$key] = true;
            }

            if ($cancelling || $this->option('approval') === 'deny' || ! $this->input->isInteractive()
                || ($params['threadId'] ?? null) !== $this->threadId || ($params['turnId'] ?? null) !== $this->turnId) {
                $this->decide($client, $request, 'deny');
            }
        }

        if ($requests === [] || ! $this->input->isInteractive() || $cancelling) {
            return;
        }

        $chunk = fread(STDIN, 4096);
        $this->inputBuffer .= $chunk === false ? '' : $chunk;

        while (($position = strpos($this->inputBuffer, "\n")) !== false) {
            $line = trim(substr($this->inputBuffer, 0, $position));
            $this->inputBuffer = substr($this->inputBuffer, $position + 1);
            $matched = false;

            foreach ($client->pendingRequests() as $request) {
                foreach (['allow', 'deny', 'cancel'] as $decision) {
                    if ($line === $decision.' '.json_encode($request['id'], JSON_THROW_ON_ERROR)) {
                        $this->decide($client, $request, $decision);
                        $matched = true;
                        break 2;
                    }
                }
            }

            if (! $matched) {
                $this->display('No matching pending permission; nothing granted.');
            }
        }
    }

    /** @param array<string, mixed> $request */
    private function decide(AppServerClient $client, array $request, string $decision): void
    {
        $permissions = $request['method'] === 'item/permissions/requestApproval';
        $result = $permissions
            ? ['permissions' => $decision === 'allow' ? (object) $request['params']['permissions'] : (object) [], 'scope' => 'turn']
            : ['decision' => match ($decision) {
                'allow' => 'accept', 'cancel' => 'cancel', default => 'decline'
            }];

        if (! $permissions && isset($request['params']['availableDecisions']) && ! in_array($result['decision'], $request['params']['availableDecisions'], true)) {
            $result = ['decision' => 'cancel'];
        }

        $client->respond($request['id'], $result);
        $this->display('Permission response '.json_encode($request['id'], JSON_THROW_ON_ERROR).': '.json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($decision === 'cancel') {
            $this->interrupted = true;
        }
    }

    private function seconds(string $option): float
    {
        $value = $this->option($option);

        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0 || (float) $value > 3600) {
            throw new RuntimeException('--'.$option.' must be a number greater than 0 and at most 3600.');
        }

        return (float) $value;
    }

    private function workspace(): string
    {
        $existing = $this->option('workspace');

        if ($existing !== null) {
            $path = realpath((string) $existing);

            if ($path === false || ! str_starts_with($path, realpath(sys_get_temp_dir()).'/ai-agents-spike-')
                || ! is_file($path.'/.ai-agents-spike') || ! is_dir($path.'/.git')) {
                throw new RuntimeException('--workspace must be a disposable repository created by this harness.');
            }

            if ($this->option('thread') !== null && (! is_file($path.'/.ai-agents-spike-thread')
                || trim(file_get_contents($path.'/.ai-agents-spike-thread')) !== $this->option('thread'))) {
                throw new RuntimeException('--thread must match the thread retained by this disposable workspace.');
            }

            return $path;
        }

        $path = sys_get_temp_dir().'/ai-agents-spike-'.bin2hex(random_bytes(8));

        if (! mkdir($path, 0700)) {
            throw new RuntimeException('Could not create disposable workspace.');
        }

        file_put_contents($path.'/.ai-agents-spike', "Disposable Codex App Server spike workspace.\n");
        $git = new Process(['git', 'init', '--quiet', $path], $path, OwnedProcess::environment());
        $git->run();

        if (! $git->isSuccessful()) {
            throw new RuntimeException('Could not initialize the disposable Git repository.');
        }

        return $path;
    }

    private function display(string $text): void
    {
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';
        $this->line(OutputFormatter::escape($text));
    }
}
