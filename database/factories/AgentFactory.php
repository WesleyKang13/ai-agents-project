<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Agent> */
class AgentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['slot' => 1, 'user_id' => User::factory(), 'name' => 'Sage', 'workspace_key' => 'sandbox', 'workspace_path' => sys_get_temp_dir(), 'status' => 'idle', 'thread_id' => 'thread-test', 'heartbeat_at' => now(), 'connection_id' => (string) Str::uuid()];
    }
}
