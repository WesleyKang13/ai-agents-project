<?php

namespace App\Services\Codex;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class OwnedProcess
{
    private ?Process $process = null;

    private ?InputStream $input = null;

    private ?int $group = null;

    private string $stdoutBuffer = '';

    private int $stderrBytes = 0;

    /** @var array<int, string> Linux process start times, to guard against PID reuse. */
    private array $descendants = [];

    private float $lastSnapshot = 0;

    /** @param list<string> $command */
    public function start(array $command, string $workspace): int
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_getpgid')) {
            throw new RuntimeException('The spike requires Linux/WSL with POSIX and pcntl support.');
        }

        if ($this->process !== null) {
            throw new RuntimeException('This process owner has already been used.');
        }

        $this->input = new InputStream;
        $this->process = new Process(['/usr/bin/setsid', ...$command], $workspace, self::environment(), $this->input, null);
        $this->process->start(function (string $type, string $chunk): void {
            if ($type === Process::OUT) {
                $this->stdoutBuffer .= $chunk;
            } else {
                $this->stderrBytes += strlen($chunk);
            }
        });
        $pid = $this->process->getPid();
        $deadline = microtime(true) + 1;

        while ($pid !== null && microtime(true) < $deadline) {
            if (posix_getpgid($pid) === $pid) {
                $this->group = $pid;
                $this->descendants[$pid] = $this->identity($pid) ?? '';
                $this->rememberDescendants();

                return $pid;
            }

            if (! $this->process->isRunning()) {
                break;
            }

            usleep(1000);
        }

        throw new RuntimeException('App Server startup failed before establishing its owned process group.');
    }

    public function write(string $message): void
    {
        if ($this->input === null || ! $this->running()) {
            throw new RuntimeException('Cannot write: App Server is not running.');
        }

        $this->input->write($message);
    }

    /** @return array{stdout: string, stderrBytes: int} */
    public function read(): array
    {
        $this->process?->isRunning();

        if (microtime(true) - $this->lastSnapshot >= 0.2) {
            $this->rememberDescendants();
        }

        $stdout = $this->stdoutBuffer;
        $stderrBytes = $this->stderrBytes;
        $this->stdoutBuffer = '';
        $this->stderrBytes = 0;
        $this->process?->clearOutput();
        $this->process?->clearErrorOutput();

        return compact('stdout', 'stderrBytes');
    }

    public function running(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    public function exitCode(): ?int
    {
        return $this->process?->getExitCode();
    }

    /** @return array{pid: int, start: string}|null */
    public function ownership(): ?array
    {
        return $this->group === null ? null : ['pid' => $this->group, 'start' => $this->descendants[$this->group]];
    }

    public static function cleanRecorded(int $pid, string $start): void
    {
        $owner = new self;

        if ($start !== '' && $owner->identity($pid) === $start && posix_getpgid($pid) === $pid) {
            $owner->group = $pid;
            $owner->descendants[$pid] = $start;
            $owner->close();
        }
    }

    public function close(): void
    {
        $this->rememberDescendants();
        $this->input?->close();
        $deadline = microtime(true) + 1;

        while ($this->running() && microtime(true) < $deadline) {
            $this->read();
            usleep(10000);
        }

        if ($this->group !== null) {
            $this->signalDescendants(SIGTERM);
            $this->signalGroup(SIGTERM);
            $deadline = microtime(true) + 0.5;

            while (@posix_kill(-$this->group, 0) && microtime(true) < $deadline) {
                $this->read();
                usleep(10000);
            }

            $this->signalGroup(SIGKILL);
            $this->signalDescendants(SIGKILL);
            $this->group = null;
        }

        $this->process?->stop(0);
        $this->descendants = [];
    }

    private function rememberDescendants(): void
    {
        $this->lastSnapshot = microtime(true);
        $pending = array_keys($this->descendants);
        $visited = [];

        while (($pid = array_pop($pending)) !== null) {
            if (isset($visited[$pid]) || $this->identity($pid) !== $this->descendants[$pid]) {
                continue;
            }

            $visited[$pid] = true;

            foreach (glob('/proc/'.$pid.'/task/*/children') ?: [] as $childrenFile) {
                $children = trim(@file_get_contents($childrenFile) ?: '');

                foreach (preg_split('/\s+/', $children, flags: PREG_SPLIT_NO_EMPTY) as $child) {
                    $childPid = (int) $child;
                    $identity = $this->identity($childPid, $pid);

                    if ($identity !== null && ! isset($visited[$childPid])) {
                        $this->descendants[$childPid] = $identity;
                        $pending[] = $childPid;
                    }
                }
            }
        }
    }

    private function identity(int $pid, ?int $parent = null): ?string
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');

        if ($stat === false) {
            return null;
        }

        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));

        if ($parent !== null && ($fields[1] ?? null) !== (string) $parent) {
            return null;
        }

        return $fields[19] ?? null;
    }

    private function signalGroup(int $signal): void
    {
        foreach ($this->descendants as $pid => $identity) {
            if ($identity !== '' && $this->identity($pid) === $identity && posix_getpgid($pid) === $this->group) {
                @posix_kill(-$this->group, $signal);

                return;
            }
        }
    }

    private function signalDescendants(int $signal): void
    {
        foreach (array_reverse($this->descendants, true) as $pid => $identity) {
            if ($identity !== '' && $this->identity($pid) === $identity) {
                @posix_kill($pid, $signal);
            }
        }
    }

    /** @return array<string, string|false> */
    public static function environment(): array
    {
        $inherited = array_merge(getenv(), $_SERVER, $_ENV);
        $environment = array_fill_keys(array_keys($inherited), false);

        foreach (['HOME', 'PATH', 'USER', 'LOGNAME', 'SHELL', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'CODEX_HOME', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR'] as $name) {
            if (isset($inherited[$name]) && is_string($inherited[$name])) {
                $environment[$name] = $inherited[$name];
            }
        }

        return $environment;
    }
}
