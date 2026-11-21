<?php

namespace App\Console\Commands;

use App\Services\Codex\AgentWorkspace;
use App\Services\Codex\OwnedProcess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AgentsPrepareWorkspace extends Command
{
    protected $signature = 'agents:prepare-workspace {key=sandbox : Key from config/agents.php}';

    protected $description = 'Prepare a configured development workspace without changing existing files';

    public function handle(AgentWorkspace $workspaces): int
    {
        $workspace = config('agents.workspaces', [])[$this->argument('key')] ?? null;

        if ($workspace === null || ! str_starts_with($workspace['path'], '/')) {
            $this->error('Choose an absolute workspace configured in config/agents.php.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($workspace['path'], 0700);
        $path = $workspaces->resolve($this->argument('key'));

        if (! is_dir($path.'/.git')) {
            $process = new Process(['git', 'init', '--quiet', $path], $path, OwnedProcess::environment());
            $process->run();

            if (! $process->isSuccessful()) {
                $this->error('Git could not initialize the configured workspace.');

                return self::FAILURE;
            }
        }

        $this->info('Workspace ready: '.$path);

        return self::SUCCESS;
    }
}
