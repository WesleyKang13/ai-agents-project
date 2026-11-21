<?php

namespace Tests\Feature;

use App\Services\Codex\AgentWorkerLock;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentWorkerLockTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/agent-worker-test-'.bin2hex(random_bytes(8)).'.lock';
        config(['agents.worker_lock' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_a_second_worker_cannot_acquire_the_lock_until_the_owner_releases_it(): void
    {
        $first = new AgentWorkerLock;
        $second = new AgentWorkerLock;
        $first->acquire();
        $rejected = false;
        try {
            try {
                $second->acquire();
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        } finally {
            $first->release();
            $second->release();
        }
        $second->acquire();
        $second->release();
    }

    public function test_the_lock_file_descriptor_is_not_inherited_by_child_processes(): void
    {
        $lock = new AgentWorkerLock;
        $lock->acquire();

        try {
            $child = new Process([PHP_BINARY, '-r', '$found = 0; foreach (glob("/proc/self/fd/*") as $fd) { if (@readlink($fd) === $argv[1]) { $found++; } } echo $found;', $this->path]);
            $child->mustRun();
            $this->assertSame('0', $child->getOutput());
        } finally {
            $lock->release();
        }
    }
}
