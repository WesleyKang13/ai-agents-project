<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Codex\AgentWorker;
use App\Services\Codex\AgentWorkerLock;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class AgentsWork extends Command
{
    protected $signature = 'agents:work {agent : Exact saved agent ID shown in its Workshop} {--user= : Required owner ID shown in the worker command}';

    protected $description = 'Connect one explicitly selected, owner-validated project agent and consume only its controls';

    public function handle(AgentWorker $worker, AgentWorkerLock $lock): int
    {
        $stopping = false;

        try {
            $agentId = (string) $this->argument('agent');
            $ownerId = (string) $this->option('user');

            if (! ctype_digit($agentId) || ! ctype_digit($ownerId)) {
                $this->error('Use the exact worker command from the agent’s Workshop, including its ID and --user owner ID.');

                return self::FAILURE;
            }

            $agent = Agent::query()->where('user_id', $ownerId)->whereHas('project', fn (Builder $query): Builder => $query->where('user_id', $ownerId))->find($agentId);

            if ($agent === null) {
                $this->error('No agent matches that ID and project owner. Nothing was connected.');

                return self::FAILURE;
            }

            $lock->acquire();
            $this->trap([SIGINT, SIGTERM], function () use (&$stopping): void {
                $stopping = true;
            });
            $this->info('Connecting agent '.$agent->id.' in project '.$agent->project_id.'. Ctrl+C stops only this worker.');
            $success = $worker->run($agent, function () use (&$stopping): bool {
                return $stopping;
            });
            $this->line($success ? 'Agent worker stopped.' : 'Agent connection ended. Restart the same worker command to check the saved conversation.');

            return $success ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Worker unavailable. Check database/workspace access and ensure another agents:work process is not already running.');

            return self::FAILURE;
        } finally {
            $this->untrap();
            $lock->release();
        }
    }
}
