<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Codex\AgentWorker;
use App\Services\Codex\AgentWorkerLock;
use Illuminate\Console\Command;
use Throwable;

class AgentsWork extends Command
{
    protected $signature = 'agents:work';

    protected $description = 'Own the one workshop agent and consume its saved controls';

    public function handle(AgentWorker $worker, AgentWorkerLock $lock): int
    {
        $stopping = false;

        try {
            $lock->acquire();
            $this->trap([SIGINT, SIGTERM], function () use (&$stopping): void {
                $stopping = true;
            });
            $this->info('Agent worker ready. Waiting for the workshop agent. Ctrl+C stops this worker.');

            while (! $stopping) {
                $agent = Agent::query()->first();

                if ($agent !== null) {
                    $this->info('Connecting the saved agent. Tasks and output stay in the private workshop.');
                    $success = $worker->run($agent, function () use (&$stopping): bool {
                        return $stopping;
                    });
                    $this->line($success ? 'Agent worker stopped.' : 'Agent connection ended. Restart agents:work to reconcile the saved conversation.');

                    return $success ? self::SUCCESS : self::FAILURE;
                }

                usleep(250000);
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Worker unavailable. Check database/workspace access and ensure another agents:work process is not already running.');

            return self::FAILURE;
        } finally {
            $this->untrap();
            $lock->release();
        }
    }
}
