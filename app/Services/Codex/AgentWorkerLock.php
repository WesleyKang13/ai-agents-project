<?php

namespace App\Services\Codex;

use RuntimeException;

class AgentWorkerLock
{
    /** @var resource|null */
    private $handle = null;

    public function acquire(): void
    {
        $this->handle = fopen(config('agents.worker_lock'), 'c+e');

        if ($this->handle === false || ! flock($this->handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another agent worker is running, or its lock is unavailable. Stop that worker before selecting another agent.');
        }
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }
}
