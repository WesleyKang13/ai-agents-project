<?php

namespace App\Services\Codex;

use App\Models\Agent;
use App\Models\Project;
use App\Services\ProjectFolders;
use Illuminate\Validation\ValidationException;

class AgentWorkspace
{
    public function __construct(private ProjectFolders $folders) {}

    public function project(Project $project): string
    {
        $path = $project->is_legacy ? $this->folders->canonical($project->workspace_path) : $this->folders->resolve($project->workspace_path);

        if ($path !== $project->workspace_path) {
            throw ValidationException::withMessages(['folder' => 'The registered folder has changed. Restore its original location before continuing.']);
        }

        return $path;
    }

    public function agent(Agent $agent): string
    {
        $project = $agent->project()->firstOrFail();
        $path = $this->project($project);

        if ($agent->user_id !== $project->user_id || $this->folders->canonical($agent->workspace_path) !== $path) {
            throw ValidationException::withMessages(['folder' => 'The agent does not match its project owner and workspace.']);
        }

        return $path;
    }

    /** @return list<array{key: string, name: string, path: string, available: bool}> */
    public function choices(): array
    {
        $choices = [];

        foreach (config('agents.workspaces', []) as $key => $workspace) {
            $path = realpath($workspace['path']);
            $choices[] = ['key' => $key, 'name' => $workspace['name'], 'path' => $path ?: $workspace['path'], 'available' => $path !== false && is_dir($path) && is_readable($path) && is_writable($path)];
        }

        return $choices;
    }

    public function resolve(string $key, ?string $expectedPath = null): string
    {
        $workspace = config('agents.workspaces', [])[$key] ?? null;
        $path = $workspace === null ? false : realpath($workspace['path']);

        if ($path === false || ! is_dir($path) || ! is_readable($path) || ! is_writable($path)
            || ($expectedPath !== null && $path !== $expectedPath)) {
            throw ValidationException::withMessages(['workspace' => 'Choose an available workspace configured on this server.']);
        }

        return $path;
    }
}
