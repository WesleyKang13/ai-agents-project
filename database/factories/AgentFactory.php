<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Agent> */
class AgentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => 'Sage', 'role' => null, 'instructions' => null, 'workspace_key' => 'sandbox', 'workspace_path' => sys_get_temp_dir(),
            'project_id' => fn (array $attributes): int => Project::query()->firstOrCreate(['user_id' => $attributes['user_id'], 'workspace_hash' => hash('sha256', $attributes['workspace_path'])], ['name' => 'Test workspace', 'workspace_path' => $attributes['workspace_path'], 'is_legacy' => true, 'is_temporary' => true])->id,
            'status' => 'idle', 'thread_id' => 'thread-test', 'heartbeat_at' => now(), 'connection_id' => (string) Str::uuid()];
    }

    public function inProject(Project $project): static
    {
        return $this->state(fn (): array => ['project_id' => $project->id, 'user_id' => $project->user_id, 'workspace_path' => $project->workspace_path]);
    }
}
